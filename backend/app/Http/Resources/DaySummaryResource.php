<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Journée de repas (brief §4.2, `GET /meals` et clé `day` des mutations) :
 * `{date, meals:[MealResource], totals, targets, remaining, sport, next_meal_type, plancher_kcal}`.
 *
 * La ressource enveloppe `['summary' => MealCalculator::daySummary(), 'meals' => Collection<Meal>]`
 * (construit par App\Services\Meals\MealDayService).
 */
class DaySummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{summary: array<string, mixed>, meals: Collection} $payload */
        $payload = $this->resource;
        $summary = $payload['summary'];

        return [
            'date' => $summary['date'],
            'meals' => MealResource::collection($payload['meals']),
            'totals' => $summary['totals'],
            'targets' => $summary['targets'],
            'remaining' => $summary['remaining'],
            'sport' => $summary['sport'],
            'next_meal_type' => $summary['next_meal_type'],
            'plancher_kcal' => (int) $summary['plancher_kcal'],
        ];
    }
}
