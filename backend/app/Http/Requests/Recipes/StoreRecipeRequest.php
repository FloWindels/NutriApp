<?php

namespace App\Http\Requests\Recipes;

use App\Enums\MealType;
use App\Services\Recipes\RecipeIngredients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /recipes — validation legacy (titre, calories requis, image jusqu'à 500 000 caractères,
 * règles de publication) + nouveaux champs §5 (servings, macros, tags, meal_types).
 */
class StoreRecipeRequest extends FormRequest
{
    /** Vocabulaire des étiquettes (brief §5). */
    public const TAGS = [
        'vegetarien', 'vegan', 'sans_gluten', 'sans_lactose', 'low_carb', 'keto',
        'mediterraneen', 'dash', 'flexitarien', 'montignac', 'halal', 'rapide',
        'riche_en_proteines', 'economique',
    ];

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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'calories' => ['required', 'numeric', 'min:0', 'max:10000'],
            'image_url' => ['nullable', 'string', 'max:500000'],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*.name' => ['nullable', 'string', 'max:255'],
            'ingredients.*.ean' => ['nullable', 'string', 'max:32'],
            'ingredients.*.amount' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:64'],
            'is_public' => ['nullable', 'boolean'],
            // --- §5 ---
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
            'proteins' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:14'],
            'tags.*' => ['string', 'distinct', Rule::in(self::TAGS)],
            'meal_types' => ['nullable', 'array', 'max:4'],
            'meal_types.*' => ['string', 'distinct', Rule::in(MealType::values())],
            'is_estimate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Règles de publication (messages legacy conservés à l'identique).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('is_public')) {
                return;
            }

            if (! $this->filled('image_url')) {
                $validator->errors()->add('image_url', "Une photo de l'assiette est requise pour publier une recette publique.");
            }

            $imageUrl = (string) $this->input('image_url', '');

            if ($imageUrl !== '' && ! str_starts_with($imageUrl, 'data:image/') && ! filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $validator->errors()->add('image_url', 'La photo doit etre une image importee ou une URL valide.');
            }

            if (! $this->filled('prep_time_minutes')) {
                $validator->errors()->add('prep_time_minutes', 'Le temps de preparation est requis pour publier une recette publique.');
            }

            if (count(RecipeIngredients::normalize($this->input('ingredients', []))) === 0) {
                $validator->errors()->add('ingredients', 'Une recette publique doit contenir au moins un aliment.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::frenchAttributes();
    }

    /**
     * @return array<string, string>
     */
    public static function frenchAttributes(): array
    {
        return [
            'title' => 'titre',
            'description' => 'description',
            'prep_time_minutes' => 'temps de préparation',
            'calories' => 'calories',
            'image_url' => 'photo',
            'ingredients' => 'ingrédients',
            'ingredients.*.name' => 'nom de l’ingrédient',
            'ingredients.*.ean' => 'code-barres de l’ingrédient',
            'ingredients.*.amount' => 'quantité de l’ingrédient',
            'ingredients.*.unit' => 'unité de l’ingrédient',
            'is_public' => 'visibilité publique',
            'servings' => 'nombre de portions',
            'proteins' => 'protéines',
            'carbs' => 'glucides',
            'fat' => 'lipides',
            'tags' => 'étiquettes',
            'tags.*' => 'étiquette',
            'meal_types' => 'types de repas',
            'meal_types.*' => 'type de repas',
            'is_estimate' => 'estimation',
        ];
    }
}
