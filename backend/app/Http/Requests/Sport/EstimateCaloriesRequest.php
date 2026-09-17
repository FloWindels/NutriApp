<?php

namespace App\Http\Requests\Sport;

use App\Enums\Intensity;
use Illuminate\Validation\Rule;

/**
 * POST /sport/calories/estimate — aperçu du champ « calories » avant saisie manuelle (addendum §C.3).
 */
class EstimateCaloriesRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sport_id' => $this->sportIdRules(),
            'sport_name' => ['nullable', 'string', 'max:80'],
            'duration_min' => ['required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'intensity' => ['nullable', 'string', Rule::enum(Intensity::class)],
            'met' => ['nullable', 'numeric', 'between:1,20'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
