<?php

namespace Database\Factories;

use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    protected $model = Recommendation::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['budget_restant', 'manque_proteines', 'anti_gaspillage', 'hydratation']);
        $title = fake()->randomElement([
            'Il te reste du budget aujourd’hui',
            'Un peu plus de protéines',
            'Utilise tes produits avant qu’ils n’expirent',
            'Pense à boire',
        ]);

        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'type' => $type,
            'dedupe_key' => Recommendation::dedupeKey($type, $title),
            'title' => $title,
            'message' => fake()->sentence(14),
            'factors' => [],
            'actions' => [],
            'priority' => fake()->numberBetween(1, 3),
            'status' => RecommendationStatus::Nouvelle->value,
            'is_estimate' => true,
        ];
    }
}
