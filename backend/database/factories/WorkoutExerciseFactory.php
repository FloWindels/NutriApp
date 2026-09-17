<?php

namespace Database\Factories;

use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkoutExercise>
 */
class WorkoutExerciseFactory extends Factory
{
    protected $model = WorkoutExercise::class;

    public function definition(): array
    {
        return [
            'session_id' => WorkoutSession::factory(),
            'exercise_id' => null,
            'block' => 'principal',
            'position' => fake()->numberBetween(1, 8),
            'name' => fake()->randomElement(['Squat au poids du corps', 'Pompes', 'Fentes avant', 'Planche', 'Pont fessier']),
            'sets' => 3,
            'reps' => 12,
            'duration_sec' => null,
            'weight_kg' => null,
            'rest_sec' => 60,
            'met' => 4.5,
            'completed' => false,
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['completed' => true]);
    }
}
