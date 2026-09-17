<?php

namespace App\Http\Requests\Meals;

use App\Http\Requests\Meals\Concerns\ValidatesMealItems;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /meals/{meal}/items — un `ItemInput` (brief §4.2).
 */
class StoreMealItemRequest extends FormRequest
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
        return $this->itemRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->itemAttributes();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateItemSource($validator, $this->all(), 'food_id');
        });
    }
}
