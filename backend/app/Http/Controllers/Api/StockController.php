<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stocks\ConsumeStockItemRequest;
use App\Http\Requests\Stocks\StoreLocationRequest;
use App\Http\Requests\Stocks\StoreStockItemRequest;
use App\Http\Requests\Stocks\UpdateLocationRequest;
use App\Http\Requests\Stocks\UpdateStockItemRequest;
use App\Http\Resources\StockItemResource;
use App\Http\Resources\StockLocationResource;
use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MealCalculator;
use App\Services\MealService;
use App\Services\Stock\StockAlerts;
use App\Services\StockService;
use App\Support\Clock;
use App\Support\Portions;
use App\Support\StockScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module M5 — Stock (brief §6) : lieux par défaut créés à la demande, portée personnelle ou foyer
 * (App\Support\StockScope), alertes de péremption / stock bas, consommation reliée aux repas.
 */
class StockController extends Controller
{
    /** Lieux créés automatiquement au premier GET /stocks. */
    public const DEFAULT_LOCATIONS = ['Frigo', 'Congélateur', 'Placard'];

    public const MSG_LOCATION_NOT_EMPTY = 'Vide ce lieu avant de le supprimer.';
    public const MSG_QUANTITY_OVER_STOCK = 'Quantité supérieure au stock.';

    public function __construct(
        private readonly StockService $stockService,
        private readonly MealService $mealService,
        private readonly MealCalculator $mealCalculator,
        private readonly StockAlerts $alerts,
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------------------------

    /**
     * GET /stocks?include_depleted=1
     * → {data:[items], locations:[{id,name,items_count}], alerts:{expiring_count, expired_count, low_count}, household_id}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $includeDepleted = filter_var($request->query('include_depleted', false), FILTER_VALIDATE_BOOLEAN);

        $this->ensureDefaultLocations($user);

        $allItems = $this->alerts->itemsFor($user);

        $items = $includeDepleted
            ? $allItems
            : $allItems->filter(fn (StockItem $item) => (float) $item->quantity > 0)->values();

        $countsByStock = $items->countBy('stock_id');

        $locations = StockScope::query($user)
            ->orderBy('id')
            ->get()
            ->each(function (Stock $stock) use ($countsByStock) {
                $stock->setAttribute('items_count', (int) ($countsByStock->get($stock->id) ?? 0));
            });

        return $this->json([
            'data' => StockItemResource::collection($items)->resolve($request),
            'locations' => StockLocationResource::collection($locations)->resolve($request),
            'alerts' => $this->alerts->counts($allItems, $user),
            'household_id' => $user->household_id !== null ? (int) $user->household_id : null,
        ]);
    }

    /**
     * GET /stocks/alerts → {data:{expiring:[…], expired:[…], low:[…]}}
     */
    public function alerts(Request $request): JsonResponse
    {
        $user = $request->user();
        $classified = $this->alerts->forUser($user);

        return $this->json([
            'data' => [
                'expiring' => StockItemResource::collection($classified['expiring'])->resolve($request),
                'expired' => StockItemResource::collection($classified['expired'])->resolve($request),
                'low' => StockItemResource::collection($classified['low'])->resolve($request),
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------
    // Lieux
    // ------------------------------------------------------------------------------------

    /**
     * POST /stocks {name} → 201 {message, data:{id,name}}
     */
    public function storeLocation(StoreLocationRequest $request): JsonResponse
    {
        $user = $request->user();
        $name = $request->locationName();

        try {
            $stock = Stock::query()->create(StockScope::ownerAttributes($user) + ['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [StoreLocationRequest::MSG_EXISTS]]);
        }

        return $this->json([
            'message' => 'Lieu de stock créé.',
            'data' => StockLocationResource::make($stock)->resolve($request),
        ], 201);
    }

    /**
     * PUT /stocks/{stock} {name} → {message, data:{id,name}}
     */
    public function updateLocation(UpdateLocationRequest $request, int $stock): JsonResponse
    {
        $user = $request->user();
        $location = StockScope::query($user)->findOrFail($stock);

        try {
            $location->update(['name' => $request->locationName()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [StoreLocationRequest::MSG_EXISTS]]);
        }

        return $this->json([
            'message' => 'Lieu de stock renommé.',
            'data' => StockLocationResource::make($location)->resolve($request),
        ]);
    }

    /**
     * DELETE /stocks/{stock} → {message} ; 422 si le lieu contient encore des articles.
     */
    public function destroyLocation(Request $request, int $stock): JsonResponse
    {
        $user = $request->user();
        $location = StockScope::query($user)->findOrFail($stock);

        if (StockItem::query()->where('stock_id', $location->id)->exists()) {
            throw ValidationException::withMessages(['stock' => [self::MSG_LOCATION_NOT_EMPTY]]);
        }

        $location->delete();

        return $this->json(['message' => 'Lieu de stock supprimé.']);
    }

    // ------------------------------------------------------------------------------------
    // Articles
    // ------------------------------------------------------------------------------------

    /**
     * POST /stocks/items → 201 {message, data}
     */
    public function storeItem(StoreStockItemRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $stock = ! empty($validated['stock_id'])
            ? StockScope::query($user)->findOrFail((int) $validated['stock_id'])
            : $this->firstOrCreateLocation($user, self::DEFAULT_LOCATIONS[0]);

        $food = null;
        if (! empty($validated['food_id'])) {
            $food = Food::query()->findOrFail((int) $validated['food_id']);
        } elseif (! empty($validated['food_barcode'])) {
            $food = $this->foodFromBarcodePayload($user, $validated);
        }

        $item = StockItem::query()->create([
            'stock_id' => $stock->id,
            'food_id' => $food?->id,
            'food_name' => $food?->name ?? $this->cleanString($validated['food_name'] ?? null),
            'food_barcode' => $food?->barcode ?? $this->cleanString($validated['food_barcode'] ?? null),
            'food_brand' => $food?->brand ?? $this->cleanString($validated['food_brand'] ?? null),
            'quantity' => isset($validated['quantity']) ? (float) $validated['quantity'] : 1.0,
            'unit' => $this->cleanString($validated['unit'] ?? null) ?? 'unite',
            'expires_at' => $validated['expires_at'] ?? null,
            'expiry_kind' => $validated['expiry_kind'] ?? 'dlc',
            'min_quantity' => isset($validated['min_quantity']) ? (float) $validated['min_quantity'] : null,
            'opened_at' => $validated['opened_at'] ?? null,
            'depleted_at' => null,
        ]);

        $item->setRelation('stock', $stock)->setRelation('food', $food);

        return $this->json([
            'message' => 'Produit ajouté au stock.',
            'data' => StockItemResource::make($item)->resolve($request),
        ], 201);
    }

    /**
     * PUT /stocks/items/{item} → {message, data}
     */
    public function updateItem(UpdateStockItemRequest $request, int $item): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        /** @var StockItem $stockItem */
        $stockItem = StockScope::items($user)->with(['stock', 'food'])->findOrFail($item);

        $changes = [];

        if (array_key_exists('stock_id', $validated) && $validated['stock_id'] !== null) {
            $target = StockScope::query($user)->findOrFail((int) $validated['stock_id']);
            $changes['stock_id'] = $target->id;
            $stockItem->setRelation('stock', $target);
        }

        if (array_key_exists('quantity', $validated) && $validated['quantity'] !== null) {
            $quantity = round((float) $validated['quantity'], 2);
            $changes['quantity'] = $quantity;
            $changes['depleted_at'] = $quantity > 0 ? null : ($stockItem->depleted_at ?? now());
        }

        if (array_key_exists('unit', $validated) && $validated['unit'] !== null) {
            $changes['unit'] = $this->cleanString($validated['unit']) ?? $stockItem->unit;
        }

        if (array_key_exists('food_name', $validated) && $validated['food_name'] !== null) {
            $changes['food_name'] = $this->cleanString($validated['food_name']) ?? $stockItem->food_name;
        }

        if (array_key_exists('expiry_kind', $validated) && $validated['expiry_kind'] !== null) {
            $changes['expiry_kind'] = $validated['expiry_kind'];
        }

        // Champs effaçables explicitement (null envoyé → vidé).
        foreach (['expires_at', 'opened_at'] as $dateField) {
            if (array_key_exists($dateField, $validated)) {
                $changes[$dateField] = $validated[$dateField];
            }
        }

        if (array_key_exists('min_quantity', $validated)) {
            $changes['min_quantity'] = $validated['min_quantity'] === null ? null : (float) $validated['min_quantity'];
        }

        if ($changes !== []) {
            $stockItem->update($changes);
        }

        return $this->json([
            'message' => 'Élément du stock mis à jour.',
            'data' => StockItemResource::make($stockItem)->resolve($request),
        ]);
    }

    /**
     * DELETE /stocks/items/{item} → {message}
     */
    public function destroyItem(Request $request, int $item): JsonResponse
    {
        $user = $request->user();

        /** @var StockItem $stockItem */
        $stockItem = StockScope::items($user)->findOrFail($item);
        $stockItem->delete();

        return $this->json(['message' => 'Élément supprimé du stock.']);
    }

    /**
     * POST /stocks/items/{item}/consume {quantity, unit?, meal_type?, date?, add_to_meal?}
     * → 201 {message, data:{meal_item:{id, meal_id, calories}|null, stock_item:{id, quantity, previous_quantity, depleted}}}
     */
    public function consume(ConsumeStockItemRequest $request, int $item): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        /** @var StockItem $stockItem */
        $stockItem = StockScope::items($user)->with(['stock', 'food'])->findOrFail($item);
        $food = $stockItem->getRelation('food');

        $quantity = (float) $validated['quantity'];
        $unit = $this->cleanString($validated['unit'] ?? null) ?? (string) $stockItem->unit;

        if ($this->unitsComparable($stockItem, $unit, $food)) {
            $delta = $this->stockService->deltaInStockUnit($stockItem, $quantity, $unit, $food);
            if ($delta > (float) $stockItem->quantity + 1e-9) {
                throw ValidationException::withMessages(['quantity' => [self::MSG_QUANTITY_OVER_STOCK]]);
            }
        }

        $date = Clock::date($user, $validated['date'] ?? null);
        $addToMeal = $request->addToMeal() && $food !== null;

        $result = DB::transaction(function () use ($user, $stockItem, $food, $quantity, $unit, $date, $validated, $addToMeal) {
            $decrement = $this->stockService->decrement($stockItem, $quantity, $unit);

            $mealItem = null;
            if ($addToMeal) {
                $type = $validated['meal_type'] ?? $this->defaultMealType($user, $date);
                $meal = $this->mealService->findOrCreate($user, $date, $type);

                [$created] = $this->mealService->addItems($meal, [[
                    'food_id' => $food->id,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'stock_item_id' => $stockItem->id,
                    'decrement_stock' => false,
                ]]);

                /** @var MealItem|null $mealItem */
                $mealItem = $created[0] ?? null;
            }

            return [$decrement, $mealItem];
        });

        [$decrement, $mealItem] = $result;

        $message = $decrement['depleted']
            ? 'Consommation enregistrée : article épuisé, ajouté à ta liste de courses.'
            : 'Consommation enregistrée.';

        return $this->json([
            'message' => $message,
            'data' => [
                'meal_item' => $mealItem === null ? null : [
                    'id' => (int) $mealItem->id,
                    'meal_id' => (int) $mealItem->meal_id,
                    'calories' => (float) $mealItem->calories,
                ],
                'stock_item' => [
                    'id' => (int) $decrement['stock_item_id'],
                    'quantity' => (float) $decrement['new_quantity'],
                    'previous_quantity' => (float) $decrement['previous_quantity'],
                    'depleted' => (bool) $decrement['depleted'],
                ],
            ],
        ], 201);
    }

    // ------------------------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------------------------

    /**
     * Crée à la demande les lieux par défaut (Frigo, Congélateur, Placard) dans la portée de l'utilisateur.
     */
    private function ensureDefaultLocations(User $user): void
    {
        $existing = StockScope::query($user)
            ->pluck('name')
            ->map(fn ($name) => mb_strtolower((string) $name))
            ->all();

        foreach (self::DEFAULT_LOCATIONS as $name) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }

            $this->firstOrCreateLocation($user, $name);
        }
    }

    /**
     * firstOrCreate insensible à la casse, tolérant à la concurrence (index unique partiel).
     */
    private function firstOrCreateLocation(User $user, string $name): Stock
    {
        $find = fn () => StockScope::query($user)->whereRaw('LOWER(name) = LOWER(?)', [$name])->first();

        $stock = $find();
        if ($stock !== null) {
            return $stock;
        }

        try {
            return Stock::query()->create(StockScope::ownerAttributes($user) + ['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            return $find() ?? StockScope::query($user)->firstOrFail();
        }
    }

    /**
     * Sans food_id mais avec un code-barres : retrouve ou crée l'aliment à partir des macros fournies,
     * pour ne jamais perdre l'information nutritionnelle (brief §6.2).
     *
     * @param  array<string, mixed>  $validated
     */
    private function foodFromBarcodePayload(User $user, array $validated): ?Food
    {
        $barcode = $this->cleanString($validated['food_barcode'] ?? null);
        if ($barcode === null || ! preg_match('/^\d{8,14}$/', $barcode)) {
            return null;
        }

        $existing = Food::query()->where('barcode', $barcode)->first();
        if ($existing !== null) {
            return $existing;
        }

        $sourceType = $validated['source_type'] ?? 'manual';
        $attributes = [
            'barcode' => $barcode,
            'name' => $this->cleanString($validated['food_name'] ?? null) ?? 'Produit sans nom',
            'brand' => $this->cleanString($validated['food_brand'] ?? null),
            'image_url' => $this->cleanString($validated['image_url'] ?? null),
            'calories' => isset($validated['calories']) ? (float) $validated['calories'] : null,
            'fat' => isset($validated['fat']) ? (float) $validated['fat'] : null,
            'carbs' => isset($validated['carbs']) ? (float) $validated['carbs'] : null,
            'proteins' => isset($validated['proteins']) ? (float) $validated['proteins'] : null,
            'source_type' => $sourceType,
            'source_fetched_at' => $sourceType === 'open_food_facts' ? now() : null,
            'created_by_user_id' => $sourceType === 'open_food_facts' ? null : $user->id,
        ];

        try {
            return Food::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            return Food::query()->where('barcode', $barcode)->first();
        }
    }

    /**
     * Le contrôle « quantité ≤ stock » n'a de sens que si l'unité demandée est comparable à celle
     * du stock : même unité canonique, ou les deux convertibles en grammes.
     */
    private function unitsComparable(StockItem $item, string $unit, ?Food $food): bool
    {
        $stockUnit = Portions::canonical((string) $item->unit);
        $requestedUnit = Portions::canonical($unit);

        if ($stockUnit !== null && $stockUnit === $requestedUnit) {
            return true;
        }

        if (mb_strtolower(trim((string) $item->unit)) === mb_strtolower(trim($unit))) {
            return true;
        }

        return Portions::toGrams(1.0, (string) $item->unit, $food)->isConvertible()
            && Portions::toGrams(1.0, $unit, $food)->isConvertible();
    }

    /**
     * Type de repas par défaut : prochain repas non enregistré de la journée (heure locale).
     */
    private function defaultMealType(User $user, string $date): string
    {
        $logged = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->whereHas('items')
            ->get(['id', 'type'])
            ->map(fn (Meal $meal) => $meal->type instanceof MealType ? $meal->type->value : (string) $meal->type)
            ->unique()
            ->values()
            ->all();

        $today = Clock::today($user);
        $hour = $date === $today ? Clock::hour($user) : ($date < $today ? 24 : 0);

        return $this->mealCalculator->nextMealType($logged, $hour);
    }

    /**
     * Réponse JSON qui conserve les décimales nulles (« 500.0 » reste un flottant côté client).
     *
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
