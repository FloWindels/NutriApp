<?php

namespace Database\Factories;

use App\Enums\SessionKind;
use App\Enums\SessionSource;
use App\Enums\SessionStatus;
use App\Models\Sport;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkoutSession>
 */
class WorkoutSessionFactory extends Factory
{
    protected $model = WorkoutSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'planned_at' => null,
            'title' => fake()->randomElement(['Séance corps entier', 'Renforcement haut du corps', 'Circuit cardio', 'Séance jambes']),
            'kind' => SessionKind::Seance->value,
            'goal' => 'forme',
            'level' => 'intermediaire',
            'equipment' => ['aucun'],
            'focus' => [],
            'duration_min' => fake()->randomElement([20, 30, 45]),
            'calories_burned' => null,
            'calories_source' => 'auto',
            'status' => SessionStatus::Prevue->value,
            'rpe' => null,
            'notes' => null,
            'source' => SessionSource::Manuelle->value,
            'intensity' => null,
            'distance_km' => null,
            'started_at' => null,
            'completed_at' => null,
            'sport_id' => null,
            'sport_name' => null,
            'lieu' => 'maison',
            'generated_by' => null,
            'llm_model' => null,
            'sport_plan_id' => null,
            'zones_a_eviter' => [],
        ];
    }

    public function completed(?float $calories = null): static
    {
        return $this->state(fn () => [
            'status' => SessionStatus::Terminee->value,
            'started_at' => now()->subMinutes(40),
            'completed_at' => now(),
            'rpe' => fake()->numberBetween(4, 8),
            'calories_burned' => $calories ?? fake()->randomFloat(1, 120, 420),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => SessionStatus::EnCours->value,
            'started_at' => now()->subMinutes(10),
        ]);
    }

    /**
     * Activité libre (course, vélo…) déjà terminée.
     */
    public function activity(?Sport $sport = null): static
    {
        return $this->state(function () use ($sport) {
            $sport ??= Sport::query()->where('is_public', true)->inRandomOrder()->first();

            return [
                'kind' => SessionKind::Activite->value,
                'source' => SessionSource::Activite->value,
                'status' => SessionStatus::Terminee->value,
                'title' => $sport?->name ?? 'Course à pied',
                'sport_id' => $sport?->id,
                'sport_name' => $sport?->name ?? 'Course à pied',
                'intensity' => 'moderee',
                'lieu' => 'exterieur',
                'goal' => null,
                'level' => null,
                'equipment' => null,
                'completed_at' => now(),
                'calories_burned' => fake()->randomFloat(1, 150, 500),
            ];
        });
    }
}
