<?php

namespace App\Http\Requests\Meals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /meals/{meal} {name?, notes?, consumed_at?} — mise à jour partielle.
 */
class UpdateMealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'consumed_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'notes' => 'notes',
            'consumed_at' => 'heure de consommation',
        ];
    }
}
