<?php

namespace App\Http\Resources;

use App\Models\Food;
use App\Models\Stock;
use App\Models\StockItem;
use App\Services\Stock\StockAlerts;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Article de stock : clés héritées (brief §0.3) + min_quantity, opened_at, depleted_at, is_depleted,
 * expiry_kind, expiry_status, food, household_id. Charger `stock` et `food` avant sérialisation.
 *
 * @mixin StockItem
 */
class StockItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockItem $item */
        $item = $this->resource;
        $user = $request->user();

        $today = $user ? Clock::today($user) : now()->toDateString();
        $window = $user ? StockAlerts::windowFor($user) : StockAlerts::DEFAULT_WINDOW_DAYS;

        $stock = $item->relationLoaded('stock') ? $item->getRelation('stock') : Stock::query()->find($item->stock_id);
        $food = $item->relationLoaded('food')
            ? $item->getRelation('food')
            : ($item->food_id ? Food::query()->find($item->food_id) : null);

        $daysLeft = StockAlerts::daysLeft($item, $today);

        return [
            'id' => (int) $item->id,
            'stock_id' => $item->stock_id !== null ? (int) $item->stock_id : null,
            'stock_name' => $stock?->name,
            'food_id' => $item->food_id !== null ? (int) $item->food_id : null,
            'food_name' => $item->food_name,
            'food_barcode' => $item->food_barcode,
            'food_brand' => $item->food_brand,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'expires_at' => $item->expires_at?->format('Y-m-d'),
            'days_left' => $daysLeft,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
            // Nouvelles clés (brief §6.2)
            'min_quantity' => $item->min_quantity !== null ? (float) $item->min_quantity : null,
            'opened_at' => $item->opened_at?->format('Y-m-d'),
            'depleted_at' => $item->depleted_at?->toISOString(),
            'is_depleted' => StockAlerts::isDepleted($item),
            'expiry_kind' => $item->expiry_kind,
            'expiry_status' => StockAlerts::expiryStatus($item, $today, $window),
            'food' => $food === null ? null : [
                'calories' => $food->calories,
                'proteins' => $food->proteins,
                'carbs' => $food->carbs,
                'fat' => $food->fat,
                'image_url' => $food->image_url,
                'serving_size_g' => $food->serving_size_g,
            ],
            'household_id' => $stock?->household_id !== null ? (int) $stock->household_id : null,
        ];
    }
}
