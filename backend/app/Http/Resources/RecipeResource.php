<?php

namespace App\Http\Resources;

use App\Models\Recipe;
use App\Models\User;
use App\Services\Recipes\RecipeIngredients;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Charge utile d'une recette (contrat legacy §0.3 + clés §5).
 *
 * @mixin Recipe
 */
class RecipeResource extends JsonResource
{
    public function __construct($resource, private readonly ?int $viewerId = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forViewer(Recipe $recipe, ?User $viewer): array
    {
        return (new self($recipe, $viewer?->id))->resolve();
    }

    /**
     * @param  iterable<Recipe>  $recipes
     * @return list<array<string, mixed>>
     */
    public static function collectionForViewer(iterable $recipes, ?User $viewer): array
    {
        return collect($recipes)
            ->map(fn (Recipe $recipe) => (new self($recipe, $viewer?->id))->resolve())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Recipe $recipe */
        $recipe = $this->resource;

        $ingredients = RecipeIngredients::normalize($recipe->ingredients ?? []);

        return [
            // --- contrat legacy (ordre et noms figés) ---
            'id' => $recipe->id,
            'title' => $recipe->title,
            'description' => $recipe->description,
            'prep_time_minutes' => $recipe->prep_time_minutes,
            'calories' => $recipe->calories,
            'image_url' => $recipe->image_url,
            'ingredients' => $ingredients,
            'ingredients_count' => count($ingredients),
            'is_public' => (bool) $recipe->is_public,
            'is_owner' => $this->viewerId !== null && $recipe->created_by_user_id === $this->viewerId,
            'created_by_user_id' => $recipe->created_by_user_id,
            'created_at' => $recipe->created_at?->toISOString(),
            'updated_at' => $recipe->updated_at?->toISOString(),
            // --- clés additives §5 ---
            'servings' => (float) ($recipe->servings ?? 1),
            'proteins' => $recipe->proteins,
            'carbs' => $recipe->carbs,
            'fat' => $recipe->fat,
            'tags' => array_values($recipe->tags ?? []),
            'meal_types' => array_values($recipe->meal_types ?? []),
            'per_serving' => $recipe->perServing(),
            'has_macros' => $recipe->hasMacros(),
            'is_estimate' => (bool) $recipe->is_estimate,
        ];
    }
}
