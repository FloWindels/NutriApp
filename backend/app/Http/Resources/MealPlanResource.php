<?php

namespace App\Http\Resources;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Services\Planner\PlanCalories;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan de repas du planificateur (brief §12). Les relations recipe/food doivent être chargées.
 *
 * @mixin \App\Models\MealPlan
 */
class MealPlanResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recipe = $this->relationLoaded('recipe') ? $this->getRelation('recipe') : null;
        $food = $this->relationLoaded('food') ? $this->getRelation('food') : null;
        $calories = PlanCalories::forPlan($this->resource);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'household_id' => $this->household_id,
            'date' => $this->date?->format('Y-m-d'),
            'meal_type' => $this->meal_type,
            'meal_type_label' => MealType::tryFrom((string) $this->meal_type)?->label(),
            'recipe_id' => $this->recipe_id,
            'food_id' => $this->food_id,
            'title' => $this->title,
            'servings' => (float) $this->servings,
            'notes' => $this->notes,
            'status' => $this->status,
            'status_label' => PlanStatus::tryFrom((string) $this->status)?->label(),
            'meal_id' => $this->meal_id,
            'calories' => $calories['calories'],
            'is_estimate' => $calories['is_estimate'],
            'recipe' => $recipe ? [
                'id' => $recipe->id,
                'title' => $recipe->title,
                'image_url' => $recipe->image_url,
                'servings' => (float) $recipe->servings,
                'per_serving' => $recipe->perServing(),
                'tags' => is_array($recipe->tags) ? $recipe->tags : [],
                'prep_time_minutes' => $recipe->prep_time_minutes,
            ] : null,
            'food' => $food ? [
                'id' => $food->id,
                'name' => $food->name,
                'brand' => $food->brand,
                'image_url' => $food->image_url,
                'calories' => $food->calories,
                'serving_size_g' => $food->serving_size_g,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
