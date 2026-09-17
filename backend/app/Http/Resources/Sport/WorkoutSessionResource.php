<?php

namespace App\Http\Resources\Sport;

use App\Models\WorkoutSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Séance structurée ou activité libre (brief §13.3 + addendum §A.2/§C.3).
 * Charger `exercises` (et `exercises.exercise` pour les consignes) avant sérialisation.
 *
 * @mixin WorkoutSession
 */
class WorkoutSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkoutSession $session */
        $session = $this->resource;

        $exercises = $session->relationLoaded('exercises')
            ? WorkoutExerciseResource::collection($session->getRelation('exercises'))->resolve($request)
            : [];

        return [
            'id' => (int) $session->id,
            'date' => $session->date?->format('Y-m-d'),
            'planned_at' => SportPlanResource::time($session->planned_at),
            'title' => (string) $session->title,
            'kind' => (string) $session->kind,
            'goal' => $session->goal,
            'level' => $session->level,
            'equipment' => $session->equipment === null ? null : array_values((array) $session->equipment),
            'focus' => $session->focus === null ? null : array_values((array) $session->focus),
            'zones_a_eviter' => $session->zones_a_eviter === null ? null : array_values((array) $session->zones_a_eviter),
            'duration_min' => (int) $session->duration_min,
            'calories_burned' => $session->calories_burned !== null ? (float) $session->calories_burned : null,
            'calories_source' => (string) ($session->calories_source ?? 'auto'),
            'status' => (string) $session->status,
            'rpe' => $session->rpe !== null ? (int) $session->rpe : null,
            'notes' => $session->notes,
            'source' => (string) $session->source,
            'sport_id' => $session->sport_id !== null ? (int) $session->sport_id : null,
            'sport_name' => $session->sport_name,
            'lieu' => $session->lieu,
            'intensity' => $session->intensity,
            'distance_km' => $session->distance_km !== null ? (float) $session->distance_km : null,
            'generated_by' => $session->generated_by,
            'llm_model' => $session->llm_model,
            'sport_plan_id' => $session->sport_plan_id !== null ? (int) $session->sport_plan_id : null,
            'started_at' => $session->started_at?->toISOString(),
            'completed_at' => $session->completed_at?->toISOString(),
            'is_estimate' => ($session->calories_source ?? 'auto') === 'auto',
            'exercises' => $exercises,
        ];
    }

    /**
     * Forme allégée utilisée par le calendrier (addendum §C.2).
     *
     * @return array<string, mixed>
     */
    public static function lite(WorkoutSession $session): array
    {
        return [
            'id' => (int) $session->id,
            'title' => (string) $session->title,
            'sport_name' => $session->sport_name,
            'status' => (string) $session->status,
            'duration_min' => (int) $session->duration_min,
            'calories_burned' => $session->calories_burned !== null ? (float) $session->calories_burned : null,
            'kind' => (string) $session->kind,
        ];
    }
}
