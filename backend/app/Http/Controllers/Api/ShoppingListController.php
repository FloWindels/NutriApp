<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShoppingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\GenerateShoppingListRequest;
use App\Http\Requests\Shopping\StoreShoppingItemRequest;
use App\Http\Requests\Shopping\ToStockRequest;
use App\Http\Requests\Shopping\UpdateShoppingItemRequest;
use App\Http\Resources\ShoppingItemResource;
use App\Models\Food;
use App\Models\ShoppingItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Shopping\ShoppingGenerator;
use App\Support\Clock;
use App\Support\OwnerScope;
use App\Support\StockScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Liste de courses (brief §11) — portée foyer ou personnelle via OwnerScope.
 */
class ShoppingListController extends Controller
{
    /** Lieux de stock utilisés par défaut pour « Mettre au stock », dans cet ordre. */
    private const DEFAULT_LOCATIONS = ['Placard', 'Frigo'];

    public function __construct(private readonly ShoppingGenerator $generator)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->listPayload($user));
    }

    public function store(StoreShoppingItemRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $food = ! empty($data['food_id']) ? Food::query()->find((int) $data['food_id']) : null;

        $item = ShoppingItem::query()->forceCreate(OwnerScope::ownerAttributes($user) + [
            'food_id' => $food?->id,
            'label' => mb_substr(trim((string) $data['label']), 0, 255),
            'quantity' => isset($data['quantity']) ? round((float) $data['quantity'], 2) : null,
            'unit' => $this->cleanUnit($data['unit'] ?? null),
            'checked' => false,
            'source' => ShoppingSource::Manuel->value,
        ]);

        $item->setRelation('food', $food);

        return response()->json([
            'message' => 'Article ajouté à la liste.',
            'data' => (new ShoppingItemResource($item))->resolve(),
        ], 201);
    }

    public function update(UpdateShoppingItemRequest $request, int $item): JsonResponse
    {
        $user = $request->user();
        $model = $this->scoped($user)->with('food')->findOrFail($item);
        $data = $request->validated();

        $changes = [];
        if (array_key_exists('label', $data)) {
            $changes['label'] = mb_substr(trim((string) $data['label']), 0, 255);
        }
        if (array_key_exists('checked', $data)) {
            $changes['checked'] = (bool) $data['checked'];
        }
        if (array_key_exists('quantity', $data)) {
            $changes['quantity'] = $data['quantity'] === null ? null : round((float) $data['quantity'], 2);
        }
        if (array_key_exists('unit', $data)) {
            $changes['unit'] = $this->cleanUnit($data['unit']);
        }

        if ($changes !== []) {
            $model->forceFill($changes)->save();
        }

        return response()->json([
            'message' => 'Article mis à jour.',
            'data' => (new ShoppingItemResource($model))->resolve(),
        ]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $model = $this->scoped($request->user())->findOrFail($item);
        $model->delete();

        return response()->json(['message' => 'Article supprimé.']);
    }

    public function clearChecked(Request $request): JsonResponse
    {
        $deleted = $this->scoped($request->user())->where('checked', true)->delete();

        return response()->json([
            'message' => $deleted > 0 ? 'Articles cochés supprimés.' : 'Aucun article coché à supprimer.',
            'deleted_count' => (int) $deleted,
        ]);
    }

    public function generate(GenerateShoppingListRequest $request): JsonResponse
    {
        $user = $request->user();
        $weekStart = Clock::weekStart($user, $request->validated()['week_start'] ?? null);

        $added = $this->generator->generate($user, $weekStart);

        $message = match (true) {
            $added === 0 => 'Rien à ajouter : ta liste est déjà à jour.',
            $added === 1 => '1 article ajouté à la liste.',
            default => $added.' articles ajoutés à la liste.',
        };

        return response()->json($this->listPayload($user) + [
            'message' => $message,
            'added_count' => $added,
            'week_start' => $weekStart,
        ]);
    }

    public function toStock(ToStockRequest $request, int $item): JsonResponse
    {
        $user = $request->user();
        $model = $this->scoped($user)->with('food')->findOrFail($item);
        $data = $request->validated();

        $stock = ! empty($data['stock_id'])
            ? StockScope::query($user)->findOrFail((int) $data['stock_id'])
            : $this->defaultLocation($user);

        $food = $model->relationLoaded('food') ? $model->getRelation('food') : null;

        $stockItem = DB::transaction(function () use ($model, $stock, $food, $data) {
            $created = StockItem::query()->forceCreate([
                'stock_id' => $stock->id,
                'food_id' => $food?->id,
                'food_name' => mb_substr(trim((string) ($model->label ?: ($food?->name ?? ''))), 0, 255) ?: 'Article',
                'food_barcode' => $food?->barcode,
                'food_brand' => $food?->brand,
                'quantity' => isset($data['quantity']) ? round((float) $data['quantity'], 2) : (($model->quantity !== null && (float) $model->quantity > 0) ? (float) $model->quantity : 1),
                'unit' => $this->cleanUnit($data['unit'] ?? null) ?? ($model->unit ?: 'unite'),
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            $model->delete();

            return $created;
        });

        $stockItem->setRelation('stock', $stock);
        $stockItem->setRelation('food', $food);

        return response()->json([
            'message' => 'Article ajouté au stock « '.$stock->name.' ».',
            'data' => $this->stockItemPayload($stockItem, $user),
        ], 201);
    }

    // ------------------------------------------------------------------------------------

    /**
     * @return Builder<ShoppingItem>
     */
    private function scoped(User $user): Builder
    {
        return OwnerScope::apply(ShoppingItem::query(), $user);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, counts: array{total: int, checked: int}}
     */
    private function listPayload(User $user): array
    {
        $items = $this->scoped($user)
            ->with('food')
            ->orderBy('checked')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'data' => ShoppingItemResource::collection($items)->resolve(),
            'counts' => [
                'total' => $items->count(),
                'checked' => $items->where('checked', true)->count(),
            ],
        ];
    }

    /**
     * Premier lieu « Placard » puis « Frigo » de la portée ; créé si aucun n'existe.
     */
    private function defaultLocation(User $user): Stock
    {
        foreach (self::DEFAULT_LOCATIONS as $name) {
            $stock = StockScope::query($user)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($stock !== null) {
                return $stock;
            }
        }

        $first = StockScope::query($user)->orderBy('id')->first();
        if ($first !== null) {
            return $first;
        }

        try {
            return Stock::query()->forceCreate(StockScope::ownerAttributes($user) + ['name' => self::DEFAULT_LOCATIONS[0]]);
        } catch (UniqueConstraintViolationException) {
            return StockScope::query($user)->whereRaw('LOWER(name) = ?', [mb_strtolower(self::DEFAULT_LOCATIONS[0])])->firstOrFail();
        }
    }

    /**
     * StockItemResource (module M5) quand elle existe, sinon la forme héritée (brief §0.3).
     *
     * @return array<string, mixed>
     */
    private function stockItemPayload(StockItem $item, User $user): array
    {
        $resource = 'App\\Http\\Resources\\StockItemResource';
        if (class_exists($resource)) {
            return (new $resource($item))->resolve();
        }

        $expiresAt = $item->expires_at?->format('Y-m-d');
        $daysLeft = $expiresAt === null
            ? null
            : (int) \Carbon\CarbonImmutable::parse(Clock::today($user))->diffInDays(\Carbon\CarbonImmutable::parse($expiresAt), false);

        $stock = $item->relationLoaded('stock') ? $item->getRelation('stock') : null;

        return [
            'id' => $item->id,
            'stock_id' => $item->stock_id,
            'stock_name' => $stock?->name,
            'food_id' => $item->food_id,
            'food_name' => $item->food_name,
            'food_barcode' => $item->food_barcode,
            'food_brand' => $item->food_brand,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'expires_at' => $expiresAt,
            'days_left' => $daysLeft,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    private function cleanUnit(mixed $unit): ?string
    {
        $unit = trim((string) $unit);

        return $unit === '' ? null : mb_substr($unit, 0, 16);
    }
}
