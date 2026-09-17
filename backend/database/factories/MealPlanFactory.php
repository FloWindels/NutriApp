<?php

namespace Database\Factories;

use App\Enums\MealType;
use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlan>
 */
class MealPlanFactory extends Factory
{
    protected $model = MealPlan::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => null,
            'date' => now()->toDateString(),
            'meal_type' => fake()->randomElement([MealType::Dejeuner->value, MealType::Diner->value]),
            'recipe_id' => null,
            'food_id' => null,
            'title' => fake()->randomElement(['Poulet rôti et légumes', 'Salade composée', 'Pâtes aux légumes', 'Soupe de lentilles']),
            'servings' => 1,
            'notes' => null,
            'status' => PlanStatus::Prevu->value,
            'meal_id' => null,
        ];
    }
}
