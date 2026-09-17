<?php

namespace App\Http\Requests\Sport;

use Illuminate\Validation\Rule;

/**
 * POST /sport/calendar/plan-week — répartition hebdomadaire proposée (addendum §C.2).
 */
class PlanWeekRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['integer', 'between:1,7'],
            'mode' => ['nullable', 'string', Rule::in(['ia', 'regles'])],
            'replace' => ['nullable', 'boolean'],
        ];
    }
}
