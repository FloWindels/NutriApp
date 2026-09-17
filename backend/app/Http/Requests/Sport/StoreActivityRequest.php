<?php

namespace App\Http\Requests\Sport;

use App\Enums\Intensity;
use Illuminate\Validation\Rule;

/**
 * POST /sport/activities — activité libre déjà réalisée (addendum §C.3).
 */
class StoreActivityRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'sport_id' => $this->sportIdRules(),
            'sport_name' => ['nullable', 'required_without:sport_id', 'string', 'min:2', 'max:80'],
            'duration_min' => ['required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'intensity' => ['nullable', 'string', Rule::enum(Intensity::class)],
            'distance_km' => ['nullable', 'numeric', 'between:0,1000'],
            'calories_burned' => ['nullable', 'numeric', 'between:0,10000'],
            'rpe' => ['nullable', 'integer', 'between:1,10'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sport_plan_id' => ['nullable', 'integer'],
        ];
    }
}
