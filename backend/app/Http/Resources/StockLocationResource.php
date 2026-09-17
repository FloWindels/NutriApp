<?php

namespace App\Http\Resources;

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lieu de stock : {id, name} (contrat hérité) + items_count quand il a été chargé (withCount).
 *
 * @mixin Stock
 */
class StockLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Stock $stock */
        $stock = $this->resource;
        $attributes = $stock->getAttributes();

        return [
            'id' => (int) $stock->id,
            'name' => $stock->name,
            'items_count' => $this->when(
                array_key_exists('items_count', $attributes),
                fn () => (int) $attributes['items_count']
            ),
        ];
    }
}
