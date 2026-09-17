<?php

namespace App\Http\Requests\Planner;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMealPlanRequest extends FormRequest
{
    public const MSG_RECETTE_OU_ALIMENT = 'Choisis une recette ou un aliment, pas les deux.';

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
            'meal_type' => ['required', Rule::enum(MealType::class)],
            'recipe_id' => ['nullable', 'integer', 'exists:recipes,id'],
            'food_id' => ['nullable', 'integer', 'exists:food,id'],
            'title' => ['required_without_all:recipe_id,food_id', 'nullable', 'string', 'max:255'],
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled('recipe_id') && $this->filled('food_id')) {
                $v->errors()->add('recipe_id', self::MSG_RECETTE_OU_ALIMENT);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'date',
            'meal_type' => 'type de repas',
            'recipe_id' => 'recette',
            'food_id' => 'aliment',
            'title' => 'titre',
            'servings' => 'nombre de portions',
            'notes' => 'notes',
        ];
    }
}
