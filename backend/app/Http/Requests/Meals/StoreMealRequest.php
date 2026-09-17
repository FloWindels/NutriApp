<?php

namespace App\Http\Requests\Meals;

use App\Enums\MealType;
use App\Http\Requests\Meals\Concerns\ValidatesMealItems;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /meals {date, type, name?, items?:[ItemInput]} (brief §4.2).
 */
class StoreMealRequest extends FormRequest
{
    use ValidatesMealItems;

    public const MAX_ITEMS = 50;

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
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::enum(MealType::class)],
            'name' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array', 'max:'.self::MAX_ITEMS],
        ] + $this->itemRules('items.*.');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'date',
            'type' => 'type de repas',
            'name' => 'nom',
            'items' => 'éléments',
        ] + $this->itemAttributes('items.*.');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $items = $this->input('items');
            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    $validator->errors()->add('items.'.$index, self::MSG_SOURCE_UNIQUE);

                    continue;
                }

                $this->validateItemSource($validator, $item, 'items.'.$index, 'items.'.$index.'.');
            }
        });
    }
}
