<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SportPlan>
 */
class SportPlanFactory extends Factory
{
    protected $model = SportPlan::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->addDays(fake()->numberBetween(0, 6))->toDateString(),
            'sport_id' => Sport::factory(),
            // Résolu après sport_id : reprend le nom du sport créé.
            'sport_name' => fn (array $attributes) => Sport::query()->find($attributes['sport_id'] ?? null)?->name ?? 'Course à pied',
            'planned_duration_min' => fake()->randomElement([20, 30, 45, 60]),
            'planned_at' => fake()->optional(0.5)->randomElement(['07:30:00', '12:15:00', '18:30:00']),
            'lieu' => fake()->randomElement(['maison', 'exterieur', 'salle_publique']),
            'notes' => null,
            'status' => PlanStatus::Prevu->value,
            'session_id' => null,
            'recurrence_id' => null,
        ];
    }

    public function realise(): static
    {
        return $this->state(fn () => ['status' => PlanStatus::Realise->value]);
    }
}
