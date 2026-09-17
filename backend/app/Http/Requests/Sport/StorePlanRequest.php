<?php

namespace App\Http\Requests\Sport;

use App\Enums\Lieu;
use Illuminate\Validation\Rule;

/**
 * POST /sport/calendar — nouvelle entrée du calendrier (addendum §C.2).
 * `sport_id` (catalogue visible) ou `sport_name` (libellé libre) : au moins l'un des deux.
 */
class StorePlanRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'sport_id' => $this->sportIdRules(),
            'sport_name' => ['nullable', 'required_without:sport_id', 'string', 'min:2', 'max:80'],
            'planned_duration_min' => ['required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'planned_at' => ['nullable', 'date_format:H:i'],
            'lieu' => ['nullable', 'string', Rule::enum(Lieu::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
