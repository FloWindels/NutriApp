<?php

namespace Database\Factories;

use App\Models\DailyTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyTarget>
 */
class DailyTargetFactory extends Factory
{
    protected $model = DailyTarget::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'calories' => fake()->numberBetween(1600, 2600),
            'proteins' => fake()->numberBetween(90, 160),
            'carbs' => fake()->numberBetween(180, 320),
            'fat' => fake()->numberBetween(50, 90),
        ];
    }
}
