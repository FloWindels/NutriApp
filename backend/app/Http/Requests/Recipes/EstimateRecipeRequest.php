<?php

namespace App\Http\Requests\Recipes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /recipes/estimate {ingredients:[{name, ean, amount, unit}], servings?}
 */
class EstimateRecipeRequest extends FormRequest
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
            'ingredients' => ['required', 'array', 'min:1', 'max:100'],
            'ingredients.*.name' => ['nullable', 'string', 'max:255'],
            'ingredients.*.ean' => ['nullable', 'string', 'max:32'],
            'ingredients.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:64'],
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ingredients' => 'ingrédients',
            'ingredients.*.name' => 'nom de l’ingrédient',
            'ingredients.*.ean' => 'code-barres de l’ingrédient',
            'ingredients.*.amount' => 'quantité de l’ingrédient',
            'ingredients.*.unit' => 'unité de l’ingrédient',
            'servings' => 'nombre de portions',
        ];
    }
}
