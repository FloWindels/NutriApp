<?php

namespace App\Http\Requests\Meals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /meals/history?from=&to= — bornes optionnelles (la période effective est résolue et
 * plafonnée à 92 jours par App\Services\Meals\MealHistoryService::resolveRange).
 */
class MealHistoryRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'date de début',
            'to' => 'date de fin',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }
}
