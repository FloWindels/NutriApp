<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealItem>
 */
class MealItemFactory extends Factory
{
    protected $model = MealItem::class;

    public function definition(): array
    {
        $quantity = 100.0;
        $calories = fake()->randomFloat(1, 50, 400);
        $proteins = fake()->randomFloat(1, 2, 30);
        $carbs = fake()->randomFloat(1, 5, 60);
        $fat = fake()->randomFloat(1, 1, 25);

        return [
            'meal_id' => Meal::factory(),
            'food_id' => null,
            'recipe_id' => null,
            'stock_item_id' => null,
            'source_type' => 'custom',
            'label' => fake()->randomElement(['Riz cuit', 'Blanc de poulet', 'Yaourt nature', 'Pomme', 'Pain complet']),
            'quantity' => $quantity,
            'unit' => 'g',
            'grams_equivalent' => $quantity,
            'calories' => $calories,
            'proteins' => $proteins,
            'carbs' => $carbs,
            'fat' => $fat,
            'fiber' => null,
            'sugar' => null,
            'salt' => null,
            'ref_basis' => 'per_100g',
            'ref_calories' => $calories,
            'ref_proteins' => $proteins,
            'ref_carbs' => $carbs,
            'ref_fat' => $fat,
            'ref_fiber' => null,
            'ref_sugar' => null,
            'ref_salt' => null,
            'ref_serving_size_g' => null,
            'is_estimate' => false,
        ];
    }

    /**
     * Item relié à un aliment : les références sont copiées depuis l'aliment (pour 100 g).
     */
    public function forFood(Food $food, float $grams = 100.0): static
    {
        $factor = $grams / 100;

        return $this->state(fn () => [
            'food_id' => $food->id,
            'source_type' => 'food',
            'label' => $food->name,
            'quantity' => $grams,
            'unit' => 'g',
            'grams_equivalent' => $grams,
            'calories' => round((float) $food->calories * $factor, 1),
            'proteins' => round((float) $food->proteins * $factor, 1),
            'carbs' => round((float) $food->carbs * $factor, 1),
            'fat' => round((float) $food->fat * $factor, 1),
            'ref_basis' => 'per_100g',
            'ref_calories' => $food->calories,
            'ref_proteins' => $food->proteins,
            'ref_carbs' => $food->carbs,
            'ref_fat' => $food->fat,
            'ref_serving_size_g' => $food->serving_size_g,
        ]);
    }
}
