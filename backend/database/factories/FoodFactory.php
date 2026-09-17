<?php

namespace Database\Factories;

use App\Models\Food;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Food>
 */
class FoodFactory extends Factory
{
    protected $model = Food::class;

    public function definition(): array
    {
        $names = [
            'Poulet rôti', 'Riz basmati', 'Pâtes complètes', 'Yaourt nature', 'Pomme',
            'Banane', 'Pain complet', 'Saumon', 'Lentilles', 'Brocoli', 'Fromage blanc',
            'Amandes', 'Œuf', 'Lait demi-écrémé', 'Flocons d’avoine',
        ];

        return [
            'barcode' => fake()->unique()->numerify('3############'),
            'name' => fake()->randomElement($names),
            'brand' => fake()->optional(0.6)->company(),
            'image_url' => null,
            'calories' => fake()->randomFloat(1, 40, 450),
            'fat' => fake()->randomFloat(1, 0, 30),
            'carbs' => fake()->randomFloat(1, 0, 70),
            'proteins' => fake()->randomFloat(1, 0, 30),
            'fiber' => fake()->optional(0.7)->randomFloat(1, 0, 10),
            'sugar' => fake()->optional(0.7)->randomFloat(1, 0, 30),
            'salt' => fake()->optional(0.7)->randomFloat(2, 0, 2),
            'serving_size_g' => fake()->optional(0.5)->randomElement([30, 50, 100, 125, 150]),
            'serving_label' => null,
            'category' => fake()->optional(0.5)->randomElement(['fruit', 'legume', 'viande', 'poisson', 'yaourt', 'pain', 'fromage']),
            'allergens' => [],
            'density_g_per_ml' => null,
            'per_unit' => '100g',
            'source_type' => 'manual',
            'source_fetched_at' => null,
            'off_last_checked_at' => null,
            'is_verified' => false,
            'created_by_user_id' => null,
        ];
    }

    public function sansCodeBarres(): static
    {
        return $this->state(fn () => ['barcode' => null]);
    }

    public function openFoodFacts(): static
    {
        return $this->state(fn () => [
            'source_type' => 'open_food_facts',
            'source_fetched_at' => now(),
            'off_last_checked_at' => now(),
            'created_by_user_id' => null,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['is_verified' => true]);
    }
}
