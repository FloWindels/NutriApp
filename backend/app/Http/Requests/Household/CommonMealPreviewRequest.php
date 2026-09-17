<?php

namespace App\Http\Requests\Household;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommonMealPreviewRequest extends FormRequest
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
            'recipe_id' => ['required', 'integer', 'exists:recipes,id'],
            'meal_type' => ['required', 'string', Rule::enum(MealType::class)],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'recipe_id' => 'recette',
            'meal_type' => 'type de repas',
            'date' => 'date',
        ];
    }
}
