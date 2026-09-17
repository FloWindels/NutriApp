<?php

namespace App\Http\Requests\Sport;

use App\Enums\SportCategory;
use Illuminate\Validation\Rule;

/**
 * POST /sport/sports — création d'un sport personnalisé (addendum §C.1).
 */
class StoreSportRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'category' => ['required', 'string', Rule::enum(SportCategory::class)],
            'met_moderee' => ['nullable', 'numeric', 'between:1.5,15'],
            'icon' => ['nullable', 'string', 'max:32'],
        ];
    }
}
