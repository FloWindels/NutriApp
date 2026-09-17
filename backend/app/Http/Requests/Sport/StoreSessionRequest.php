<?php

namespace App\Http\Requests\Sport;

use App\Enums\Equipment;
use App\Enums\ExerciseCategory;
use App\Enums\ExerciseLevel;
use App\Enums\Intensity;
use App\Enums\Lieu;
use App\Enums\MuscleGroup;
use App\Enums\SessionKind;
use App\Enums\SessionSource;
use App\Services\Sport\SportVocab;
use App\Services\Sport\WorkoutProposalSchema;
use Illuminate\Validation\Rule;

/**
 * POST /sport/sessions — enregistre une séance : une proposition générée peut être renvoyée
 * telle quelle (blocs + exercices) accompagnée de `date`, `sport_plan_id`, `planned_at`, `status`.
 * Une liste plate `exercises` reste acceptée (brief §13.2).
 */
class StoreSessionRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = array_merge([
            'date' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'kind' => ['nullable', 'string', Rule::enum(SessionKind::class)],
            'source' => ['nullable', 'string', Rule::enum(SessionSource::class)],
            'status' => ['nullable', 'string', Rule::in(['prevue', 'en_cours'])],
            'goal' => ['nullable', 'string', Rule::in(array_keys(SportVocab::OBJECTIFS))],
            'level' => ['nullable', 'string', Rule::enum(ExerciseLevel::class)],
            'sport_type' => ['nullable', 'string', Rule::in(array_keys(SportVocab::SPORT_TYPES))],
            'sport_id' => $this->sportIdRules(),
            'sport_name' => ['nullable', 'string', 'max:80'],
            'sport_plan_id' => ['nullable', 'integer'],
            'duration_min' => ['required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'planned_at' => ['nullable', 'date_format:H:i'],
            'started_at' => ['nullable', 'date'],
            'lieu' => ['nullable', 'string', Rule::enum(Lieu::class)],
            'intensity' => ['nullable', 'string', Rule::enum(Intensity::class)],
            'distance_km' => ['nullable', 'numeric', 'between:0,1000'],
            'calories_burned' => ['nullable', 'numeric', 'between:0,10000'],
            'calories_estimate' => ['nullable', 'numeric', 'between:0,10000'],
            'is_estimate' => ['nullable', 'boolean'],
            'generated_by' => ['nullable', 'string', Rule::in(['regles', 'ia'])],
            'llm_model' => ['nullable', 'string', 'max:64'],
            'rpe' => ['nullable', 'integer', 'between:1,10'],
            'notes' => ['nullable', 'string', 'max:500'],
            'explication' => ['nullable', 'array', 'max:12'],
            'explication.*' => ['string', 'max:500'],
            'warnings' => ['nullable', 'array', 'max:12'],
            'warnings.*' => ['string', 'max:500'],
            'blocks' => ['nullable', 'array', 'max:5'],
            'blocks.*.key' => ['nullable', 'string', Rule::in(WorkoutProposalSchema::BLOCK_KEYS)],
            'blocks.*.name' => ['nullable', 'string', 'max:80'],
            'blocks.*.exercises' => ['nullable', 'array', 'max:40'],
            'exercises' => ['nullable', 'array', 'max:60'],
            'exercises.*.block' => ['nullable', 'string', Rule::in(WorkoutProposalSchema::BLOCK_KEYS)],
        ], $this->vocabularyRules());

        foreach (['blocks.*.exercises.*', 'exercises.*'] as $prefix) {
            foreach ($this->exerciseRules() as $field => $fieldRules) {
                $rules[$prefix.'.'.$field] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * Règles communes d'un exercice (proposition ou saisie manuelle).
     *
     * @return array<string, array<int, mixed>>
     */
    private function exerciseRules(): array
    {
        return [
            'exercise_id' => ['nullable', 'integer', 'exists:exercises,id'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'category' => ['nullable', 'string', Rule::enum(ExerciseCategory::class)],
            'muscle_group' => ['nullable', 'string', Rule::enum(MuscleGroup::class)],
            'equipment' => ['nullable', 'string', Rule::in(Equipment::values())],
            'sets' => ['nullable', 'integer', 'between:1,10'],
            'reps' => ['nullable', 'integer', 'between:1,50'],
            'duration_sec' => ['nullable', 'integer', 'between:5,3600'],
            'rest_sec' => ['nullable', 'integer', 'between:0,600'],
            'weight_kg' => ['nullable', 'numeric', 'between:0,500'],
            'met' => ['nullable', 'numeric', 'between:1,20'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'intensity' => ['nullable', 'string', Rule::enum(Intensity::class)],
            'distance_km' => ['nullable', 'numeric', 'between:0,1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'blocks.*.exercises.*.name' => 'nom de l’exercice',
            'exercises.*.name' => 'nom de l’exercice',
        ]);
    }
}
