<?php

namespace App\Http\Requests\Weights;

use App\Support\Clock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /weights {date?, weight_kg} (brief §2.5). La date par défaut est « aujourd'hui »
 * dans le fuseau de l'utilisateur ; une pesée future est refusée.
 */
class StoreWeightRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.Clock::today($this->user())],
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'date',
            'weight_kg' => 'poids',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'La date de la pesée ne peut pas être dans le futur.',
        ];
    }
}
