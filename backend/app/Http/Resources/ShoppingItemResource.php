<?php

namespace App\Http\Resources;

use App\Enums\ShoppingSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Article de la liste de courses (brief §11).
 *
 * @mixin \App\Models\ShoppingItem
 */
class ShoppingItemResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $food = $this->relationLoaded('food') ? $this->getRelation('food') : null;

        return [
            'id' => $this->id,
            'household_id' => $this->household_id,
            'food_id' => $this->food_id,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'checked' => (bool) $this->checked,
            'source' => $this->source,
            'source_label' => ShoppingSource::tryFrom((string) $this->source)?->label(),
            'food' => $food ? [
                'id' => $food->id,
                'name' => $food->name,
                'brand' => $food->brand,
                'barcode' => $food->barcode,
                'image_url' => $food->image_url,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
