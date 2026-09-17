<?php

namespace App\Http\Requests\Sport;

use App\Enums\Lieu;
use Illuminate\Validation\Rule;

/**
 * POST /sport/calendar/recurring — une entrée par semaine (1 à 12) sur un jour donné (addendum §C.2).
 */
class StoreRecurringPlanRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'weekday' => ['required', 'integer', 'between:1,7'],
            'weeks' => ['required', 'integer', 'between:1,12'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'sport_id' => $this->sportIdRules(),
            'sport_name' => ['nullable', 'required_without:sport_id', 'string', 'min:2', 'max:80'],
            'planned_duration_min' => ['required', 'integer', 'between:'.self::DUREE_MIN.','.self::DUREE_MAX],
            'planned_at' => ['nullable', 'date_format:H:i'],
            'lieu' => ['nullable', 'string', Rule::enum(Lieu::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
