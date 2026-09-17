<?php

namespace Database\Factories;

use App\Enums\MealType;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meal>
 */
class MealFactory extends Factory
{
    protected $model = Meal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'type' => fake()->randomElement(MealType::values()),
            'name' => null,
            'consumed_at' => null,
            'notes' => null,
        ];
    }

    public function ofType(MealType|string $type): static
    {
        return $this->state(fn () => ['type' => $type instanceof MealType ? $type->value : $type]);
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['date' => $date]);
    }
}
