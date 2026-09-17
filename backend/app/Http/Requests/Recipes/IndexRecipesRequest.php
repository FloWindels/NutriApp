<?php

namespace App\Http\Requests\Recipes;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /recipes?q=&mine=1&tag=&meal_type=&max_calories=&page=&per_page=
 */
class IndexRecipesRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:255'],
            'mine' => ['nullable', 'boolean'],
            'tag' => ['nullable', 'string', Rule::in(StoreRecipeRequest::TAGS)],
            'meal_type' => ['nullable', 'string', Rule::in(MealType::values())],
            'max_calories' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'recherche',
            'mine' => 'mes recettes',
            'tag' => 'étiquette',
            'meal_type' => 'type de repas',
            'max_calories' => 'calories maximales par portion',
            'page' => 'page',
            'per_page' => 'éléments par page',
        ];
    }
}
