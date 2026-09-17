<?php

namespace App\Http\Requests\Sport;

use App\Enums\ExerciseLevel;
use App\Enums\Lieu;
use App\Services\Sport\SportVocab;
use Illuminate\Validation\Rule;

/**
 * POST /sport/sessions/generate — proposition non persistée (addendum §C.4).
 * Tout est optionnel sauf `duration_min` : le reste vient du profil sport de l'utilisateur.
 */
class GenerateSessionRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'mode' => ['nullable', 'string', Rule::in(['ia', 'regles'])],
            'goal' => ['nullable', 'string', Rule::in(array_keys(SportVocab::OBJECTIFS))],
            'level' => ['nullable', 'string', Rule::enum(ExerciseLevel::class)],
            'duration_min' => array_merge($this->durationRule(), ['integer', 'between:'.self::GENERATION_MIN.','.self::GENERATION_MAX]),
            'sport_type' => ['nullable', 'string', Rule::in(array_keys(SportVocab::SPORT_TYPES))],
            'sport_id' => $this->sportIdRules(),
            'lieu' => ['nullable', 'string', Rule::enum(Lieu::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'seed' => ['nullable', 'integer', 'between:0,1000000'],
        ], $this->vocabularyRules());
    }

    /**
     * @return array<int, string>
     */
    protected function durationRule(): array
    {
        return ['required'];
    }
}
