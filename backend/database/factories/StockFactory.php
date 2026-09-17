<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => null,
            'name' => fake()->randomElement(['Frigo', 'Congélateur', 'Placard']),
        ];
    }

    /**
     * Lieu partagé par un foyer (user_id NULL).
     */
    public function forHousehold(?Household $household = null): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'household_id' => $household?->id ?? Household::factory(),
        ]);
    }
}
