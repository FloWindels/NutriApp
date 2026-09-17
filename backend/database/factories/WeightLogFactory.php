<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WeightLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeightLog>
 */
class WeightLogFactory extends Factory
{
    protected $model = WeightLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'weight_kg' => fake()->randomFloat(1, 55, 95),
        ];
    }
}
