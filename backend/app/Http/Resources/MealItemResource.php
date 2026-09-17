<?php

namespace App\Http\Resources;

use App\Models\Food;
use App\Models\MealItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Élément de repas (brief §4.2) :
 * `{id, meal_id, source_type, food_id, recipe_id, stock_item_id, label, quantity, unit, grams_equivalent,
 *   calories, proteins, carbs, fat, fiber, sugar, salt, is_estimate, food: {id, barcode, brand, image_url}|null, created_at}`.
 *
 * @mixin MealItem
 */
class MealItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MealItem $item */
        $item = $this->resource;

        // Aucune charge paresseuse : la relation est chargée en amont (with/load) ; sinon on la charge ici explicitement.
        if ($item->food_id !== null && ! $item->relationLoaded('food')) {
            $item->load('food');
        }

        /** @var Food|null $food */
        $food = $item->food_id !== null && $item->relationLoaded('food') ? $item->getRelation('food') : null;

        return [
            'id' => (int) $item->id,
            'meal_id' => (int) $item->meal_id,
            'source_type' => $item->source_type,
            'food_id' => $item->food_id,
            'recipe_id' => $item->recipe_id,
            'stock_item_id' => $item->stock_item_id,
            'label' => $item->label,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'grams_equivalent' => $item->grams_equivalent,
            'calories' => (float) $item->calories,
            'proteins' => (float) $item->proteins,
            'carbs' => (float) $item->carbs,
            'fat' => (float) $item->fat,
            'fiber' => $item->fiber,
            'sugar' => $item->sugar,
            'salt' => $item->salt,
            'is_estimate' => (bool) $item->is_estimate,
            'food' => $food === null ? null : [
                'id' => (int) $food->id,
                'barcode' => $food->barcode,
                'brand' => $food->brand,
                'image_url' => $food->image_url,
            ],
            'created_at' => $item->created_at?->toISOString(),
        ];
    }
}
