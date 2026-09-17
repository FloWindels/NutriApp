<?php

namespace App\Http\Resources\Sport;

use App\Models\WorkoutExercise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exercice d'une séance enregistrée (snapshot). `instructions` provient du catalogue
 * quand la relation `exercise` est chargée.
 *
 * @mixin WorkoutExercise
 */
class WorkoutExerciseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkoutExercise $item */
        $item = $this->resource;

        $catalog = $item->relationLoaded('exercise') ? $item->getRelation('exercise') : null;

        return [
            'id' => (int) $item->id,
            'exercise_id' => $item->exercise_id !== null ? (int) $item->exercise_id : null,
            'block' => (string) $item->block,
            'position' => (int) $item->position,
            'name' => (string) $item->name,
            'sets' => $item->sets !== null ? (int) $item->sets : null,
            'reps' => $item->reps !== null ? (int) $item->reps : null,
            'duration_sec' => $item->duration_sec !== null ? (int) $item->duration_sec : null,
            'weight_kg' => $item->weight_kg !== null ? (float) $item->weight_kg : null,
            'rest_sec' => $item->rest_sec !== null ? (int) $item->rest_sec : null,
            'met' => $item->met !== null ? (float) $item->met : null,
            'completed' => (bool) $item->completed,
            'notes' => $item->notes,
            'instructions' => $catalog?->instructions,
        ];
    }
}
