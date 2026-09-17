<?php

namespace App\Http\Requests\Stocks;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /stocks/items/{item}/consume — {quantity, unit?, meal_type?, date?, add_to_meal? (défaut true)}.
 */
class ConsumeStockItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'meal_type' => ['nullable', Rule::enum(MealType::class)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'add_to_meal' => ['nullable', 'boolean'],
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
            'meal_type' => 'type de repas',
            'date' => 'date',
            'add_to_meal' => 'ajout au repas',
        ];
    }

    public function addToMeal(): bool
    {
        $value = $this->validated()['add_to_meal'] ?? null;

        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
