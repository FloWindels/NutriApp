<?php

namespace App\Http\Requests\Diets;

use App\Services\Diets\DietEvaluator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluateDietRequest extends FormRequest
{
    public const JOURS_DEFAUT = 7;
    public const JOURS_MIN = 3;
    public const JOURS_MAX = 92;

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
            'days' => ['nullable', 'integer', 'min:'.self::JOURS_MIN, 'max:'.self::JOURS_MAX],
            'regime' => ['nullable', 'string', Rule::in(DietEvaluator::regimes())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'days' => 'nombre de jours',
            'regime' => 'régime',
        ];
    }

    public function days(): int
    {
        $days = $this->input('days');

        return is_numeric($days) ? (int) $days : self::JOURS_DEFAUT;
    }
}
