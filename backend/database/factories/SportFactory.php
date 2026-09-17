<?php

namespace Database\Factories;

use App\Enums\SportCategory;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sport>
 */
class SportFactory extends Factory
{
    protected $model = Sport::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));
        $met = fake()->randomFloat(1, 3.0, 9.0);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'category' => fake()->randomElement(SportCategory::values()),
            'met_faible' => round($met * 0.75, 1),
            'met_moderee' => $met,
            'met_elevee' => round($met * 1.3, 1),
            'icon' => 'fitness_center',
            'is_public' => true,
            'created_by_user_id' => null,
        ];
    }

    /**
     * Sport personnalisé, visible uniquement par son créateur.
     */
    public function custom(?User $user = null): static
    {
        return $this->state(function (array $attributes) use ($user) {
            $userId = $user?->id ?? User::factory();

            return [
                'is_public' => false,
                'created_by_user_id' => $userId,
            ];
        });
    }
}
