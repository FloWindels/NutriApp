<?php

namespace App\Http\Requests\Planner;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMealPlanRequest extends FormRequest
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
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'meal_type' => ['sometimes', 'required', Rule::enum(MealType::class)],
            'recipe_id' => ['sometimes', 'nullable', 'integer', 'exists:recipes,id'],
            'food_id' => ['sometimes', 'nullable', 'integer', 'exists:food,id'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'servings' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'required', Rule::enum(PlanStatus::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled('recipe_id') && $this->filled('food_id')) {
                $v->errors()->add('recipe_id', StoreMealPlanRequest::MSG_RECETTE_OU_ALIMENT);
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
            'status' => 'statut',
        ];
    }
}
