<?php

namespace Database\Factories;

use App\Enums\ShoppingSource;
use App\Models\ShoppingItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingItem>
 */
class ShoppingItemFactory extends Factory
{
    protected $model = ShoppingItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => null,
            'food_id' => null,
            'label' => fake()->randomElement(['Lait', 'Œufs', 'Tomates', 'Riz', 'Poulet', 'Yaourts', 'Pain complet']),
            'quantity' => fake()->optional(0.6)->randomElement([1, 2, 6, 500]),
            'unit' => fake()->optional(0.6)->randomElement(['piece', 'g', 'ml']),
            'checked' => false,
            'source' => ShoppingSource::Manuel->value,
        ];
    }

    public function checked(): static
    {
        return $this->state(fn () => ['checked' => true]);
    }
}
