<?php

namespace App\Services\Recipes;

use App\Models\Food;
use App\Services\Foods\FoodCatalog;
use App\Support\Portions;

/**
 * Estimation nutritionnelle d'une recette à partir de ses ingrédients (brief §5) :
 * chaque ingrédient est résolu dans le catalogue local (code-barres puis nom),
 * converti en grammes via App\Support\Portions, puis les valeurs « pour 100 g » sont sommées.
 * Les ingrédients non résolus sont ignorés et signalés dans `details`.
 */
class RecipeNutritionEstimator
{
    public function __construct(private readonly FoodCatalog $catalog)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $ingredients  lignes `{name, ean, amount, unit}`
     * @return array{
     *     calories: float, proteins: float, carbs: float, fat: float,
     *     resolved_count: int, total_count: int, is_estimate: bool,
     *     details: list<array{name: string, resolved: bool, grams: float|null}>
     * }
     */
    public function estimate(array $ingredients, ?float $servings = null): array
    {
        $ingredients = RecipeIngredients::normalize($ingredients);

        $totals = ['calories' => 0.0, 'proteins' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        $details = [];
        $resolved = 0;

        foreach ($ingredients as $ingredient) {
            $detail = ['name' => $ingredient['name'], 'resolved' => false, 'grams' => null];

            $food = $this->catalog->resolveIngredient($ingredient['ean'], $ingredient['name']);
            $amount = $ingredient['amount'];

            if ($food instanceof Food && $amount !== null && $amount > 0) {
                $conversion = Portions::toGrams((float) $amount, $ingredient['unit'] ?? 'g', $food);

                if ($conversion->isConvertible()) {
                    $grams = (float) $conversion->grams;
                    $factor = $grams / 100;

                    $totals['calories'] += (float) ($food->calories ?? 0) * $factor;
                    $totals['proteins'] += (float) ($food->proteins ?? 0) * $factor;
                    $totals['carbs'] += (float) ($food->carbs ?? 0) * $factor;
                    $totals['fat'] += (float) ($food->fat ?? 0) * $factor;

                    $detail['resolved'] = true;
                    $detail['grams'] = round($grams, 2);
                    $resolved++;
                }
            }

            $details[] = $detail;
        }

        $result = [
            'calories' => round($totals['calories'], 1),
            'proteins' => round($totals['proteins'], 1),
            'carbs' => round($totals['carbs'], 1),
            'fat' => round($totals['fat'], 1),
            'resolved_count' => $resolved,
            'total_count' => count($ingredients),
            'is_estimate' => true,
            'details' => $details,
        ];

        if ($servings !== null && $servings > 0) {
            $result['servings'] = $servings;
            $result['per_serving'] = [
                'calories' => round($result['calories'] / $servings, 1),
                'proteins' => round($result['proteins'] / $servings, 1),
                'carbs' => round($result['carbs'] / $servings, 1),
                'fat' => round($result['fat'] / $servings, 1),
            ];
        }

        return $result;
    }
}
