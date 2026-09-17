<?php

namespace Database\Factories;

use App\Enums\Equipment;
use App\Enums\ExerciseCategory;
use App\Enums\ExerciseLevel;
use App\Enums\MuscleGroup;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    protected $model = Exercise::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'category' => ExerciseCategory::Force->value,
            'muscle_group' => fake()->randomElement(MuscleGroup::values()),
            'equipment' => Equipment::Aucun->value,
            'level' => ExerciseLevel::Debutant->value,
            'met' => fake()->randomFloat(1, 2.5, 8.0),
            'default_sets' => 3,
            'default_reps' => 12,
            'default_duration_sec' => null,
            'instructions' => fake()->sentence(12),
            'contraindications' => [],
            'is_public' => true,
            'created_by_user_id' => null,
        ];
    }

    public function cardio(): static
    {
        return $this->state(fn () => [
            'category' => ExerciseCategory::Cardio->value,
            'muscle_group' => MuscleGroup::Cardio->value,
            'default_sets' => null,
            'default_reps' => null,
            'default_duration_sec' => 40,
            'met' => 8.0,
        ]);
    }

    public function mobilite(): static
    {
        return $this->state(fn () => [
            'category' => ExerciseCategory::Mobilite->value,
            'muscle_group' => MuscleGroup::Mobilite->value,
            'default_sets' => null,
            'default_reps' => null,
            'default_duration_sec' => 45,
            'met' => 2.5,
        ]);
    }
}
