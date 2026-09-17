<?php

namespace App\Http\Requests\Sport;

use App\Enums\SportCategory;
use Illuminate\Validation\Rule;

/**
 * PUT /sport/sports/{sport} — modification d'un sport personnalisé (addendum §C.1).
 */
class UpdateSportRequest extends SportFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
            'category' => ['sometimes', 'required', 'string', Rule::enum(SportCategory::class)],
            'met_moderee' => ['sometimes', 'nullable', 'numeric', 'between:1.5,15'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
