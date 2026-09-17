<?php

namespace App\Http\Resources\Sport;

use App\Models\SportPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrée du calendrier sportif (addendum §C.2). Charger la relation `sport` pour l'exposer.
 *
 * @mixin SportPlan
 */
class SportPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SportPlan $plan */
        $plan = $this->resource;

        $sport = $plan->relationLoaded('sport') ? $plan->getRelation('sport') : null;

        return [
            'id' => (int) $plan->id,
            'date' => $plan->date?->format('Y-m-d'),
            'sport_id' => $plan->sport_id !== null ? (int) $plan->sport_id : null,
            'sport_name' => (string) $plan->sport_name,
            'sport' => $sport === null ? null : SportResource::make($sport)->resolve($request),
            'planned_duration_min' => (int) $plan->planned_duration_min,
            'planned_at' => self::time($plan->planned_at),
            'lieu' => $plan->lieu,
            'notes' => $plan->notes,
            'status' => (string) $plan->status,
            'session_id' => $plan->session_id !== null ? (int) $plan->session_id : null,
            'recurrence_id' => $plan->recurrence_id,
        ];
    }

    /**
     * Heure au format HH:MM (la colonne `time` remonte en « 18:30:00 » selon le pilote).
     */
    public static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }
}
