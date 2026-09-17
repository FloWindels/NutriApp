<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class StoreShoppingItemRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'unit' => ['nullable', 'string', 'max:16'],
            'food_id' => ['nullable', 'integer', 'exists:food,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'libellé',
            'quantity' => 'quantité',
            'unit' => 'unité',
            'food_id' => 'aliment',
        ];
    }
}
