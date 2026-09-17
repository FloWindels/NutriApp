<?php

namespace App\Http\Resources\Sport;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Proposition de séance non persistée (addendum §C.4), commune aux modes « ia » et « regles ».
 * La ressource enveloppe le tableau renvoyé par WorkoutAiGenerator / WorkoutGenerator et fige
 * l'ordre et le typage des clés attendues par les clients.
 */
class ProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::payload((array) $this->resource);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public static function payload(array $proposal): array
    {
        return [
            'title' => (string) ($proposal['title'] ?? ''),
            'sport_type' => (string) ($proposal['sport_type'] ?? 'autre'),
            'sport_id' => isset($proposal['sport_id']) && $proposal['sport_id'] !== null ? (int) $proposal['sport_id'] : null,
            'sport_name' => $proposal['sport_name'] ?? null,
            'lieu' => $proposal['lieu'] ?? null,
            'goal' => $proposal['goal'] ?? null,
            'level' => $proposal['level'] ?? null,
            'duration_min' => (int) ($proposal['duration_min'] ?? 0),
            'equipment' => array_values((array) ($proposal['equipment'] ?? [])),
            'focus' => array_values((array) ($proposal['focus'] ?? [])),
            'zones_a_eviter' => array_values((array) ($proposal['zones_a_eviter'] ?? [])),
            'intensity' => $proposal['intensity'] ?? null,
            'calories_estimate' => (float) ($proposal['calories_estimate'] ?? 0),
            'is_estimate' => true,
            'generated_by' => (string) ($proposal['generated_by'] ?? 'regles'),
            'llm_model' => $proposal['llm_model'] ?? null,
            'explication' => array_values((array) ($proposal['explication'] ?? [])),
            'warnings' => array_values((array) ($proposal['warnings'] ?? [])),
            'date' => $proposal['date'] ?? null,
            'planned_at' => $proposal['planned_at'] ?? null,
            'sport_plan_id' => isset($proposal['sport_plan_id']) && $proposal['sport_plan_id'] !== null ? (int) $proposal['sport_plan_id'] : null,
            'blocks' => array_map(static fn (array $block): array => [
                'key' => (string) ($block['key'] ?? 'principal'),
                'name' => (string) ($block['name'] ?? ''),
                'exercises' => array_map(static fn (array $ex): array => self::exercise($ex), array_values((array) ($block['exercises'] ?? []))),
            ], array_values((array) ($proposal['blocks'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $ex
     * @return array<string, mixed>
     */
    private static function exercise(array $ex): array
    {
        return [
            'exercise_id' => isset($ex['exercise_id']) && $ex['exercise_id'] !== null ? (int) $ex['exercise_id'] : null,
            'name' => (string) ($ex['name'] ?? ''),
            'category' => (string) ($ex['category'] ?? 'force'),
            'muscle_group' => (string) ($ex['muscle_group'] ?? 'corps_entier'),
            'equipment' => (string) ($ex['equipment'] ?? 'aucun'),
            'sets' => isset($ex['sets']) && $ex['sets'] !== null ? (int) $ex['sets'] : null,
            'reps' => isset($ex['reps']) && $ex['reps'] !== null ? (int) $ex['reps'] : null,
            'duration_sec' => isset($ex['duration_sec']) && $ex['duration_sec'] !== null ? (int) $ex['duration_sec'] : null,
            'rest_sec' => isset($ex['rest_sec']) && $ex['rest_sec'] !== null ? (int) $ex['rest_sec'] : null,
            'distance_km' => isset($ex['distance_km']) && $ex['distance_km'] !== null ? (float) $ex['distance_km'] : null,
            'intensity' => $ex['intensity'] ?? null,
            'instructions' => (string) ($ex['instructions'] ?? ''),
            'met' => isset($ex['met']) && $ex['met'] !== null ? (float) $ex['met'] : null,
        ];
    }
}
