<?php

namespace App\Http\Requests\Sport;

use App\Enums\Equipment;
use App\Enums\ExerciseCategory;
use App\Enums\ExerciseLevel;
use App\Enums\MuscleGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /sport/exercises — catalogue public (brief §13.2), accessible sans authentification :
 * cette requête n'hérite donc pas de SportFormRequest (qui exige un utilisateur connecté).
 */
class IndexExercisesRequest extends FormRequest
{
    public const PER_PAGE_DEFAULT = 50;

    public const PER_PAGE_MAX = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'equipment' => ['nullable', 'string', Rule::in(Equipment::values())],
            'muscle' => ['nullable', 'string', Rule::enum(MuscleGroup::class)],
            'level' => ['nullable', 'string', Rule::enum(ExerciseLevel::class)],
            'category' => ['nullable', 'string', Rule::enum(ExerciseCategory::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'recherche',
            'equipment' => 'matériel',
            'muscle' => 'groupe musculaire',
            'level' => 'niveau',
            'category' => 'catégorie',
            'page' => 'page',
            'per_page' => 'éléments par page',
        ];
    }
}
