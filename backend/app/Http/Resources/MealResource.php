<?php

namespace App\Http\Resources;

use App\Enums\MealType;
use App\Models\Meal;
use App\Services\MealCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Repas (brief §4.2) : `{id, date, type, name, consumed_at, notes, items:[MealItemResource], totals}`.
 * Les items (et leur aliment) doivent être chargés en amont.
 *
 * @mixin Meal
 */
class MealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Meal $meal */
        $meal = $this->resource;

        if (! $meal->relationLoaded('items')) {
            $meal->load(['items' => fn ($q) => $q->orderBy('id'), 'items.food']);
        }

        $type = $meal->type instanceof MealType ? $meal->type->value : (string) $meal->type;

        return [
            'id' => (int) $meal->id,
            'date' => $meal->date?->format('Y-m-d'),
            'type' => $type,
            'name' => $meal->name,
            'consumed_at' => $meal->consumed_at?->toISOString(),
            'notes' => $meal->notes,
            'items' => MealItemResource::collection($meal->getRelation('items')),
            'totals' => app(MealCalculator::class)->totalsForMeal($meal),
        ];
    }
}
