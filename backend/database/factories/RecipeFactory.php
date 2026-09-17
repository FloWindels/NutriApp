<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    public function definition(): array
    {
        $servings = fake()->randomElement([1, 2, 4]);

        return [
            'created_by_user_id' => User::factory(),
            'title' => fake()->randomElement([
                'Poulet au curry et riz', 'Salade de lentilles', 'Omelette aux légumes',
                'Pâtes au saumon', 'Bowl d’avoine aux fruits', 'Wok de légumes au tofu',
                'Gratin de brocolis', 'Chili végétarien',
            ]),
            'description' => fake()->sentence(12),
            'prep_time_minutes' => fake()->numberBetween(10, 45),
            'calories' => fake()->randomFloat(1, 300, 1600),
            'proteins' => fake()->randomFloat(1, 15, 90),
            'carbs' => fake()->randomFloat(1, 20, 160),
            'fat' => fake()->randomFloat(1, 8, 60),
            'servings' => $servings,
            'image_url' => null,
            'ingredients' => [
                ['name' => 'Riz', 'ean' => null, 'amount' => 150, 'unit' => 'g'],
                ['name' => 'Poulet', 'ean' => null, 'amount' => 200, 'unit' => 'g'],
            ],
            'tags' => fake()->randomElements(['rapide', 'riche_en_proteines', 'economique', 'vegetarien'], 2),
            'meal_types' => ['dejeuner', 'diner'],
            'is_public' => true,
            'is_estimate' => false,
        ];
    }

    public function privee(): static
    {
        return $this->state(fn () => ['is_public' => false]);
    }

    public function sansMacros(): static
    {
        return $this->state(fn () => ['proteins' => null, 'carbs' => null, 'fat' => null]);
    }
}
