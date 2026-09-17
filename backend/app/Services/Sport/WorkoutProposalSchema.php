<?php

namespace App\Services\Sport;

use App\Enums\Equipment;
use App\Enums\ExerciseCategory;
use App\Enums\ExerciseLevel;
use App\Enums\Intensity;
use App\Enums\Lieu;
use App\Enums\MuscleGroup;
use App\Exceptions\LlmUnavailableException;
use App\Models\Exercise;

/**
 * Schéma JSON attendu du coach IA (sortie structurée) et normalisation de sa réponse
 * vers la forme de proposition de l'addendum §C.4.
 */
class WorkoutProposalSchema
{
    public const BLOCK_KEYS = ['echauffement', 'principal', 'retour_au_calme'];

    public function __construct(private CaloriesEstimator $calories)
    {
    }

    /**
     * Schéma JSON de la proposition de séance (toutes les propriétés requises, nullable via types multiples).
     *
     * @return array<string, mixed>
     */
    public static function json(): array
    {
        $exercise = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['exercise_id', 'name', 'category', 'muscle_group', 'equipment', 'sets', 'reps', 'duration_sec', 'rest_sec', 'distance_km', 'intensity', 'instructions'],
            'properties' => [
                'exercise_id' => ['type' => ['integer', 'null'], 'description' => 'Identifiant du catalogue fourni, sinon null.'],
                'name' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => ExerciseCategory::values()],
                'muscle_group' => ['type' => 'string', 'enum' => MuscleGroup::values()],
                'equipment' => ['type' => 'string', 'enum' => Equipment::values()],
                'sets' => ['type' => ['integer', 'null']],
                'reps' => ['type' => ['integer', 'null']],
                'duration_sec' => ['type' => ['integer', 'null']],
                'rest_sec' => ['type' => ['integer', 'null']],
                'distance_km' => ['type' => ['number', 'null']],
                'intensity' => ['type' => ['string', 'null'], 'enum' => [...Intensity::values(), null]],
                'instructions' => ['type' => 'string'],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'sport_type', 'intensity', 'calories_estimate', 'explication', 'warnings', 'blocks'],
            'properties' => [
                'title' => ['type' => 'string'],
                'sport_type' => ['type' => 'string', 'enum' => array_keys(SportVocab::SPORT_TYPES)],
                'intensity' => ['type' => 'string', 'enum' => Intensity::values()],
                'calories_estimate' => ['type' => ['number', 'null'], 'description' => 'Estimation prudente en kcal nettes.'],
                'explication' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3 à 6 puces en tutoiement, la dernière étant la phrase de sécurité.'],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'blocks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'name', 'exercises'],
                        'properties' => [
                            'key' => ['type' => 'string', 'enum' => self::BLOCK_KEYS],
                            'name' => ['type' => 'string'],
                            'exercises' => ['type' => 'array', 'items' => $exercise],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Schéma JSON de la planification hebdomadaire (addendum §C.4, mode ia de plan-week).
     *
     * @return array<string, mixed>
     */
    public static function weekJson(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['days', 'explication'],
            'properties' => [
                'days' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['weekday', 'sport', 'duration_min', 'focus', 'lieu', 'note'],
                        'properties' => [
                            'weekday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7, 'description' => '1 = lundi … 7 = dimanche'],
                            'sport' => ['type' => 'string', 'description' => 'Nom du sport, de préférence issu du catalogue fourni.'],
                            'duration_min' => ['type' => 'integer'],
                            'focus' => ['type' => ['string', 'null']],
                            'lieu' => ['type' => ['string', 'null'], 'enum' => [...Lieu::values(), null]],
                            'note' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'explication' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * Valide et normalise une réponse brute du LLM vers la forme de proposition.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $request  Demande résolue (goal, level, duration_min, sport_type, lieu, equipment, focus, zones_a_eviter)
     * @param  list<array<string, mixed>>  $catalog  Lignes envoyées au LLM ({exercise_id, …})
     * @return array<string, mixed>
     *
     * @throws LlmUnavailableException si la réponse est inexploitable (aucun bloc principal valide)
     */
    public function normalize(array $raw, array $request, array $catalog, float $weightKg, ?string $model): array
    {
        $zones = array_values((array) ($request['zones_a_eviter'] ?? []));
        $level = $request['level'] ?? 'debutant';
        $duration = (int) ($request['duration_min'] ?? 30);
        $warnings = $this->strings($raw['warnings'] ?? []);

        $rawBlocks = is_array($raw['blocks'] ?? null) ? array_values($raw['blocks']) : [];
        if ($rawBlocks === []) {
            throw new LlmUnavailableException('Réponse IA sans bloc d’exercices.');
        }

        $catalogById = [];
        foreach ($catalog as $row) {
            if (isset($row['exercise_id'])) {
                $catalogById[(int) $row['exercise_id']] = $row;
            }
        }

        $ids = [];
        foreach ($rawBlocks as $block) {
            foreach ((array) ($block['exercises'] ?? []) as $ex) {
                if (isset($ex['exercise_id']) && is_numeric($ex['exercise_id']) && isset($catalogById[(int) $ex['exercise_id']])) {
                    $ids[] = (int) $ex['exercise_id'];
                }
            }
        }
        $models = $ids === [] ? collect() : Exercise::query()->whereIn('id', array_unique($ids))->get()->keyBy('id');

        $blocks = array_fill_keys(self::BLOCK_KEYS, []);
        $last = count($rawBlocks) - 1;
        foreach ($rawBlocks as $index => $block) {
            $key = $block['key'] ?? null;
            if (! in_array($key, self::BLOCK_KEYS, true)) {
                $key = $index === 0 && $last > 0 ? 'echauffement' : ($index === $last && $last > 1 ? 'retour_au_calme' : 'principal');
            }

            foreach ((array) ($block['exercises'] ?? []) as $ex) {
                if (! is_array($ex)) {
                    continue;
                }
                $item = $this->normalizeExercise($ex, $catalogById, $models, $level, $zones, $warnings);
                if ($item !== null) {
                    $blocks[$key][] = $item;
                }
            }
        }

        if ($blocks['principal'] === []) {
            throw new LlmUnavailableException('Réponse IA sans exercice exploitable dans le bloc principal.');
        }

        $this->fitDuration($blocks, $duration);

        $blockList = [];
        foreach (self::BLOCK_KEYS as $key) {
            $blockList[] = [
                'key' => $key,
                'name' => WorkoutGenerator::BLOCK_NAMES[$key],
                'exercises' => array_values($blocks[$key]),
            ];
        }

        $metEstimate = $this->calories->forProposal($blockList, $level, $duration, $weightKg);
        $rawCalories = isset($raw['calories_estimate']) && is_numeric($raw['calories_estimate']) ? (float) $raw['calories_estimate'] : null;
        $calories = ($rawCalories === null || $rawCalories <= 0 || $rawCalories > 2 * max(1.0, $metEstimate))
            ? $metEstimate
            : round($rawCalories, 1);

        $explication = array_slice($this->strings($raw['explication'] ?? []), 0, 8);
        if ($explication === [] || ! str_contains(end($explication), 'Arrête l’exercice')) {
            $explication[] = WorkoutGenerator::SAFETY_SENTENCE;
        }

        $sportType = in_array($raw['sport_type'] ?? null, array_keys(SportVocab::SPORT_TYPES), true)
            ? $raw['sport_type']
            : ($request['sport_type'] ?? 'musculation');
        $intensity = in_array($raw['intensity'] ?? null, Intensity::values(), true)
            ? $raw['intensity']
            : ($level === 'avance' ? 'elevee' : 'moderee');
        $title = isset($raw['title']) && is_string($raw['title']) && trim($raw['title']) !== ''
            ? mb_substr(trim($raw['title']), 0, 120)
            : sprintf('%s · %d min', SportVocab::SPORT_TYPES[$sportType], $duration);

        return [
            'title' => $title,
            'sport_type' => $sportType,
            'lieu' => in_array($request['lieu'] ?? null, Lieu::values(), true) ? $request['lieu'] : 'maison',
            'goal' => $request['goal'] ?? 'forme',
            'level' => $level,
            'duration_min' => $duration,
            'equipment' => array_values((array) ($request['equipment'] ?? ['aucun'])),
            'focus' => array_values((array) ($request['focus'] ?? [])),
            'zones_a_eviter' => $zones,
            'intensity' => $intensity,
            'calories_estimate' => $calories,
            'is_estimate' => true,
            'generated_by' => 'ia',
            'llm_model' => $model,
            'explication' => $explication,
            'warnings' => array_values(array_unique($warnings)),
            'blocks' => $blockList,
        ];
    }

    /**
     * Normalise la planification hebdomadaire du LLM : un jour par weekday demandé, durées bornées.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<int>|null  $days  Jours demandés (1..7) ; null = tous acceptés
     * @return array{days: list<array{weekday: int, sport: string, duration_min: int, focus: string|null, lieu: string|null, note: string|null}>, explication: list<string>}
     *
     * @throws LlmUnavailableException
     */
    public function normalizeWeek(array $raw, ?array $days): array
    {
        $out = [];
        foreach ((array) ($raw['days'] ?? []) as $day) {
            if (! is_array($day)) {
                continue;
            }
            $weekday = (int) ($day['weekday'] ?? 0);
            if ($weekday < 1 || $weekday > 7 || isset($out[$weekday])) {
                continue;
            }
            if ($days !== null && ! in_array($weekday, $days, true)) {
                continue;
            }
            $sport = is_string($day['sport'] ?? null) ? trim($day['sport']) : '';
            if ($sport === '') {
                continue;
            }

            $out[$weekday] = [
                'weekday' => $weekday,
                'sport' => mb_substr($sport, 0, 80),
                'duration_min' => max(10, min(180, (int) ($day['duration_min'] ?? 30))),
                'focus' => is_string($day['focus'] ?? null) && trim($day['focus']) !== '' ? mb_substr(trim($day['focus']), 0, 80) : null,
                'lieu' => in_array($day['lieu'] ?? null, Lieu::values(), true) ? $day['lieu'] : null,
                'note' => is_string($day['note'] ?? null) && trim($day['note']) !== '' ? mb_substr(trim($day['note']), 0, 500) : null,
            ];
        }

        if ($out === []) {
            throw new LlmUnavailableException('Planification IA sans journée exploitable.');
        }

        ksort($out);

        return [
            'days' => array_values($out),
            'explication' => array_slice($this->strings($raw['explication'] ?? []), 0, 8),
        ];
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $ex
     * @param  array<int, array<string, mixed>>  $catalogById
     * @param  \Illuminate\Support\Collection<int, Exercise>  $models
     * @param  list<string>  $zones
     * @param  list<string>  $warnings
     * @return array<string, mixed>|null  null si l'exercice est écarté
     */
    private function normalizeExercise(array $ex, array $catalogById, $models, string $level, array $zones, array &$warnings): ?array
    {
        $name = is_string($ex['name'] ?? null) ? trim($ex['name']) : '';
        $id = isset($ex['exercise_id']) && is_numeric($ex['exercise_id']) && isset($catalogById[(int) $ex['exercise_id']])
            ? (int) $ex['exercise_id']
            : null;
        $row = $id !== null ? $catalogById[$id] : [];
        /** @var Exercise|null $model */
        $model = $id !== null ? $models->get($id) : null;

        if ($name === '') {
            $name = (string) ($row['name'] ?? '');
        }
        if ($name === '') {
            return null;
        }

        $hits = $model !== null ? $model->hitsZones($zones) : SportVocab::nameHitsZones($name, $zones);
        if ($hits) {
            $warnings[] = sprintf(
                '« %s » a été retiré de la proposition : il sollicite une zone à éviter (%s).',
                $name,
                implode(', ', array_map(fn ($z) => mb_strtolower(SportVocab::ZONES[$z] ?? $z), $zones))
            );

            return null;
        }

        $category = in_array($ex['category'] ?? null, ExerciseCategory::values(), true) ? $ex['category'] : ($row['category'] ?? 'force');
        $muscle = in_array($ex['muscle_group'] ?? null, MuscleGroup::values(), true) ? $ex['muscle_group'] : ($row['muscle_group'] ?? 'corps_entier');
        $equipment = in_array($ex['equipment'] ?? null, Equipment::values(), true) ? $ex['equipment'] : ($row['equipment'] ?? 'aucun');
        $met = isset($row['met']) && is_numeric($row['met']) && (float) $row['met'] > 0
            ? (float) $row['met']
            : (isset($ex['met']) && is_numeric($ex['met']) && (float) $ex['met'] >= 1 && (float) $ex['met'] <= 20
                ? round((float) $ex['met'], 1)
                : $this->calories->defaultExerciseMet($category, $level));

        $instructions = is_string($ex['instructions'] ?? null) && trim($ex['instructions']) !== ''
            ? mb_substr(trim($ex['instructions']), 0, 1000)
            : (string) ($model?->instructions ?? '');

        return [
            'exercise_id' => $id,
            'name' => mb_substr($name, 0, 120),
            'category' => $category,
            'muscle_group' => $muscle,
            'equipment' => $equipment,
            'sets' => $this->intOrNull($ex['sets'] ?? null, 1, 10),
            'reps' => $this->intOrNull($ex['reps'] ?? null, 1, 50),
            'duration_sec' => $this->intOrNull($ex['duration_sec'] ?? null, 5, 3600),
            'rest_sec' => $this->intOrNull($ex['rest_sec'] ?? null, 0, 600),
            'distance_km' => isset($ex['distance_km']) && is_numeric($ex['distance_km']) && (float) $ex['distance_km'] > 0
                ? round(min(200.0, (float) $ex['distance_km']), 1)
                : null,
            'intensity' => in_array($ex['intensity'] ?? null, Intensity::values(), true) ? $ex['intensity'] : null,
            'instructions' => $instructions,
            'met' => $met,
        ];
    }

    /**
     * Ramène la durée estimée dans ±20 % de la durée demandée en réduisant repos et durées d'effort.
     *
     * @param  array<string, list<array<string, mixed>>>  $blocks
     */
    private function fitDuration(array &$blocks, int $durationMin): void
    {
        $target = $durationMin * 60;
        $total = 0;
        foreach ($blocks as $items) {
            foreach ($items as $ex) {
                $total += $this->calories->estimatedSeconds($ex);
            }
        }

        if ($total <= 0 || $total <= 1.2 * $target) {
            return;
        }

        $factor = $target / $total;
        foreach ($blocks as &$items) {
            foreach ($items as &$ex) {
                if ($ex['duration_sec'] !== null) {
                    $ex['duration_sec'] = max(5, (int) floor($ex['duration_sec'] * $factor));
                }
                if ($ex['rest_sec'] !== null) {
                    $ex['rest_sec'] = max(0, (int) floor($ex['rest_sec'] * $factor));
                }
            }
            unset($ex);
        }
        unset($items);
    }

    private function intOrNull(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) max($min, min($max, (int) round((float) $value)));
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = mb_substr(trim($v), 0, 500);
            }
        }

        return $out;
    }
}
