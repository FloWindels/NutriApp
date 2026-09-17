<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShoppingItemRequest extends FormRequest
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
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'checked' => ['sometimes', 'boolean'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'libellé',
            'checked' => 'coché',
            'quantity' => 'quantité',
            'unit' => 'unité',
        ];
    }
}
