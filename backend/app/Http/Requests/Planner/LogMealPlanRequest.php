<?php

namespace App\Http\Requests\Planner;

use Illuminate\Foundation\Http\FormRequest;

class LogMealPlanRequest extends FormRequest
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
            'decrement_stock' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decrement_stock' => 'retrait du stock',
        ];
    }
}
