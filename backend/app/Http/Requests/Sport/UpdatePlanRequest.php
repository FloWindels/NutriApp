<?php

namespace App\Http\Requests\Sport;

use App\Enums\Lieu;
use App\Enums\PlanStatus;
use Illuminate\Validation\Rule;

/**
 * PUT /sport/calendar/{plan} — modification d'une entrée du calendrier (addendum §C.2).
 */
class UpdatePlanRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'sport_id' => array_merge(['sometimes'], $this->sportIdRules()),
            'sport_name' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
            'planned_duration_min' => ['sometimes', 'required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'planned_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'lieu' => ['sometimes', 'nullable', 'string', Rule::enum(Lieu::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'required', 'string', Rule::enum(PlanStatus::class)],
        ];
    }
}
