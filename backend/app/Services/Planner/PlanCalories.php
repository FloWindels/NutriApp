<?php

namespace App\Services\Planner;

use App\Models\MealPlan;
use App\Support\Portions;

/**
 * Calories indicatives d'un plan de repas (brief §12) :
 *  - recette  : per_serving.calories × portions (estimation si les macros sont incomplètes) ;
 *  - aliment  : « 1 portion » via Portions (toujours une estimation) × portions ;
 *  - titre seul : idée de config/meal_ideas.php si le titre correspond, sinon inconnu.
 *
 * Les relations recipe/food doivent être chargées (preventLazyLoading).
 */
final class PlanCalories
{
    /**
     * @return array{calories: float|null, is_estimate: bool}
     */
    public static function forPlan(MealPlan $plan): array
    {
        $servings = max((float) $plan->servings, 0.0);

        $recipe = $plan->relationLoaded('recipe') ? $plan->getRelation('recipe') : null;
        if ($recipe !== null) {
            $perServing = $recipe->perServing();

            return [
                'calories' => round((float) $perServing['calories'] * $servings, 1),
                'is_estimate' => ! $recipe->hasMacros() || (bool) $recipe->is_estimate,
            ];
        }

        $food = $plan->relationLoaded('food') ? $plan->getRelation('food') : null;
        if ($food !== null) {
            $conversion = Portions::toGrams($servings, 'portion', $food);
            $grams = (float) ($conversion->grams ?? 0.0);

            return [
                'calories' => round((float) $food->calories * $grams / 100, 1),
                'is_estimate' => true,
            ];
        }

        $idea = self::ideaForTitle((string) $plan->title);
        if ($idea !== null) {
            return [
                'calories' => round((float) $idea['calories'] * $servings, 1),
                'is_estimate' => true,
            ];
        }

        return ['calories' => null, 'is_estimate' => true];
    }

    /**
     * Idée de repas dont le titre correspond (insensible à la casse et aux accents).
     *
     * @return array{title: string, calories: int|float, proteins: int|float, carbs: int|float, fat: int|float, tags: list<string>, meal_types: list<string>}|null
     */
    public static function ideaForTitle(string $title): ?array
    {
        $needle = CompatibilityFilter::fold($title);
        if ($needle === '') {
            return null;
        }

        foreach (self::ideas() as $idea) {
            if (CompatibilityFilter::fold((string) ($idea['title'] ?? '')) === $needle) {
                return $idea;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function ideas(): array
    {
        $ideas = config('meal_ideas', []);

        return is_array($ideas) ? array_values($ideas) : [];
    }
}
