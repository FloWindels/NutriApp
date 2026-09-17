<?php

namespace App\Http\Requests\Sport;

/**
 * POST /sport/sessions/{session}/complete — clôture d'une séance (brief §13.2) :
 * durée réellement faite, ressenti d'effort, exercices cochés (séries / répétitions / charge).
 * `calories_burned` présent ⇒ saisie manuelle, sinon estimation MET (addendum §B).
 */
class CompleteSessionRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'duration_min' => ['nullable', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'rpe' => ['nullable', 'integer', 'between:1,10'],
            'calories_burned' => ['nullable', 'numeric', 'between:0,10000'],
            'distance_km' => ['nullable', 'numeric', 'between:0,1000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'exercises' => ['nullable', 'array', 'max:60'],
            'exercises.*.id' => ['required', 'integer'],
            'exercises.*.completed' => ['nullable', 'boolean'],
            'exercises.*.sets' => ['nullable', 'integer', 'between:1,10'],
            'exercises.*.reps' => ['nullable', 'integer', 'between:1,50'],
            'exercises.*.duration_sec' => ['nullable', 'integer', 'between:5,3600'],
            'exercises.*.weight_kg' => ['nullable', 'numeric', 'between:0,500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'exercises.*.id' => 'exercice',
            'exercises.*.completed' => 'exercice réalisé',
            'exercises.*.sets' => 'séries',
            'exercises.*.reps' => 'répétitions',
            'exercises.*.weight_kg' => 'charge',
        ]);
    }
}
