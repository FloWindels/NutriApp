<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Models\Stock;
use App\Models\StockItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    private function toPayload(StockItem $item): array
    {
        $today = now()->startOfDay();
        $expiresAt = $item->expires_at;
        $daysLeft = $expiresAt ? $today->diffInDays($expiresAt, false) : null;

        return [
            'id' => $item->id,
            'stock_id' => $item->stock_id,
            'stock_name' => $item->stock?->name,
            'food_id' => $item->food_id,
            'food_name' => $item->food_name,
            'food_barcode' => $item->food_barcode,
            'food_brand' => $item->food_brand,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'expires_at' => $expiresAt?->toDateString(),
            'days_left' => $daysLeft,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    private function toLocationPayload(Stock $stock): array
    {
        return [
            'id' => $stock->id,
            'name' => $stock->name,
        ];
    }

    private function getOrCreateDefaultLocation(int $userId): Stock
    {
        return Stock::firstOrCreate(
            ['user_id' => $userId, 'name' => 'Frigo'],
            ['user_id' => $userId, 'name' => 'Frigo'],
        );
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('stocks', 'name')->where(fn ($query) => $query->where('user_id', $userId)),
            ],
        ]);

        $stock = Stock::create([
            'user_id' => $userId,
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Lieu de stock cree.',
            'data' => $this->toLocationPayload($stock),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $this->getOrCreateDefaultLocation($userId);

        $locations = Stock::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();

        $locationIds = $locations->pluck('id');

        $items = StockItem::query()
            ->with('stock:id,name')
            ->whereIn('stock_id', $locationIds)
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $items->map(fn (StockItem $item) => $this->toPayload($item))->values(),
            'locations' => $locations->map(fn (Stock $location) => $this->toLocationPayload($location))->values(),
        ]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'stock_id' => ['nullable', 'integer'],
            'food_id' => ['nullable', 'integer', 'exists:food,id'],
            'food_name' => ['nullable', 'string', 'max:255', 'required_without:food_id'],
            'food_barcode' => ['nullable', 'string', 'max:32'],
            'food_brand' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $food = null;
        if (!empty($validated['food_id'])) {
            $food = Food::findOrFail($validated['food_id']);
        }

        if (!empty($validated['stock_id'])) {
            $stock = Stock::query()
                ->where('id', $validated['stock_id'])
                ->where('user_id', $userId)
                ->first();

            if (!$stock) {
                return response()->json([
                    'message' => 'Lieu de stock introuvable.',
                ], 404);
            }
        } else {
            $stock = $this->getOrCreateDefaultLocation($userId);
        }

        $item = StockItem::create([
            'stock_id' => $stock->id,
            'food_id' => $food?->id,
            'food_name' => $food?->name ?? $validated['food_name'] ?? null,
            'food_barcode' => $food?->barcode ?? ($validated['food_barcode'] ?? null),
            'food_brand' => $food?->brand ?? ($validated['food_brand'] ?? null),
            'quantity' => $validated['quantity'] ?? 1,
            'unit' => $validated['unit'] ?? 'unite',
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Produit ajoute au stock.',
            'data' => $this->toPayload($item),
        ], 201);
    }

    public function updateItem(Request $request, StockItem $item): JsonResponse
    {
        $item->loadMissing('stock:id,user_id');

        if ((int) ($item->stock?->user_id ?? 0) !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Element introuvable dans ton stock.',
            ], 404);
        }

        $validated = $request->validate([
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $item->update([
            'quantity' => $validated['quantity'] ?? $item->quantity,
            'unit' => $validated['unit'] ?? $item->unit,
            'expires_at' => array_key_exists('expires_at', $validated)
                ? $validated['expires_at']
                : $item->expires_at,
        ]);

        return response()->json([
            'message' => 'Element du stock mis a jour.',
            'data' => $this->toPayload($item->fresh()),
        ]);
    }

    public function destroyItem(Request $request, StockItem $item): JsonResponse
    {
        $item->loadMissing('stock:id,user_id');

        if ((int) ($item->stock?->user_id ?? 0) !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Element introuvable dans ton stock.',
            ], 404);
        }

        $item->delete();

        return response()->json([
            'message' => 'Element supprime du stock.',
        ]);
    }
}
