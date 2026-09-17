<?php

namespace App\Http\Resources\Sport;

use App\Models\Exercise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exercice du catalogue public (brief §13.2, GET /sport/exercises).
 *
 * @mixin Exercise
 */
class ExerciseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Exercise $exercise */
        $exercise = $this->resource;

        return [
            'id' => (int) $exercise->id,
            'name' => (string) $exercise->name,
            'slug' => (string) $exercise->slug,
            'category' => (string) $exercise->category,
            'muscle_group' => (string) $exercise->muscle_group,
            'equipment' => (string) $exercise->equipment,
            'level' => (string) $exercise->level,
            'met' => (float) $exercise->met,
            'default_sets' => $exercise->default_sets !== null ? (int) $exercise->default_sets : null,
            'default_reps' => $exercise->default_reps !== null ? (int) $exercise->default_reps : null,
            'default_duration_sec' => $exercise->default_duration_sec !== null ? (int) $exercise->default_duration_sec : null,
            'instructions' => (string) $exercise->instructions,
            'contraindications' => array_values((array) ($exercise->contraindications ?? [])),
            'is_public' => (bool) $exercise->is_public,
        ];
    }
}
