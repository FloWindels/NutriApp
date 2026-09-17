<?php

namespace App\Http\Requests\Sport;

use App\Enums\SessionStatus;
use Illuminate\Validation\Rule;

/**
 * GET /sport/sessions?from=&to=&status= (brief §13.2).
 */
class IndexSessionsRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'string', Rule::enum(SessionStatus::class)],
        ];
    }
}
