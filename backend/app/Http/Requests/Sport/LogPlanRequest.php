<?php

namespace App\Http\Requests\Sport;

use App\Enums\Intensity;
use Illuminate\Validation\Rule;

/**
 * POST /sport/calendar/{plan}/log — « j'ai fait cette séance » (addendum §C.2).
 * `calories_burned` présent ⇒ calories_source = manuel, sinon estimation MET (auto).
 */
class LogPlanRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'duration_min' => ['required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'intensity' => ['nullable', 'string', Rule::enum(Intensity::class)],
            'distance_km' => ['nullable', 'numeric', 'between:0,1000'],
            'calories_burned' => ['nullable', 'numeric', 'between:0,10000'],
            'rpe' => ['nullable', 'integer', 'between:1,10'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
