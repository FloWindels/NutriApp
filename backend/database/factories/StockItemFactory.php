<?php

namespace Database\Factories;

use App\Models\Stock;
use App\Models\StockItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    public function definition(): array
    {
        return [
            'stock_id' => Stock::factory(),
            'food_id' => null,
            'food_name' => fake()->randomElement(['Lait demi-écrémé', 'Yaourt nature', 'Poulet', 'Carottes', 'Riz', 'Œufs', 'Fromage râpé']),
            'food_barcode' => null,
            'food_brand' => fake()->optional(0.4)->company(),
            'quantity' => fake()->randomElement([1, 2, 250, 500, 1000]),
            'unit' => fake()->randomElement(['piece', 'g', 'ml']),
            'expires_at' => now()->addDays(fake()->numberBetween(1, 21))->toDateString(),
            'expiry_kind' => 'dlc',
            'min_quantity' => null,
            'opened_at' => null,
            'depleted_at' => null,
        ];
    }

    public function expired(int $daysAgo = 2): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDays($daysAgo)->toDateString()]);
    }

    public function expiringSoon(int $inDays = 1): static
    {
        return $this->state(fn () => ['expires_at' => now()->addDays($inDays)->toDateString()]);
    }

    public function depleted(): static
    {
        return $this->state(fn () => ['quantity' => 0, 'depleted_at' => now()]);
    }
}
