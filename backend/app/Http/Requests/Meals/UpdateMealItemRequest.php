<?php

namespace App\Http\Requests\Meals;

use App\Http\Requests\Meals\Concerns\ValidatesMealItems;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /meals/{meal}/items/{item} {quantity, unit} — recalcul depuis les `ref_*` de l'élément.
 */
class UpdateMealItemRequest extends FormRequest
{
    use ValidatesMealItems;

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
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'unit' => $this->unitRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity' => 'quantité',
            'unit' => 'unité',
        ];
    }
}
