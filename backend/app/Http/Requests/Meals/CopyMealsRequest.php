<?php

namespace App\Http\Requests\Meals;

use App\Enums\MealType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /meals/copy {from_date, to_date, type?} — copie des instantanés d'une journée vers une autre.
 */
class CopyMealsRequest extends FormRequest
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
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'different:from_date'],
            'type' => ['nullable', Rule::enum(MealType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from_date' => 'date d’origine',
            'to_date' => 'date de destination',
            'type' => 'type de repas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_date.different' => 'La date de destination doit être différente de la date d’origine.',
        ];
    }
}
