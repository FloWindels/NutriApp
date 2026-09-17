<?php

namespace App\Http\Requests\Sport;

use App\Enums\SportCategory;
use Illuminate\Validation\Rule;

/**
 * GET /sport/sports?q=&category= — catalogue visible (publics + sports personnalisés).
 */
class IndexSportsRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', Rule::enum(SportCategory::class)],
        ];
    }
}
