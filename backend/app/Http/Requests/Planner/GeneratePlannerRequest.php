<?php

namespace App\Http\Requests\Planner;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeneratePlannerRequest extends FormRequest
{
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
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'meal_types' => ['nullable', 'array', 'min:1'],
            'meal_types.*' => ['distinct', Rule::enum(MealType::class)],
            'replace' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'week_start' => 'début de semaine',
            'meal_types' => 'types de repas',
            'meal_types.*' => 'type de repas',
            'replace' => 'remplacement',
        ];
    }
}
