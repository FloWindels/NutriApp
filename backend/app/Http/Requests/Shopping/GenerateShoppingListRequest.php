<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateShoppingListRequest extends FormRequest
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
            // La génération rend la liste complète : elle accepte donc les mêmes paramètres de
            // lecture que GET /shopping-list, sinon l'écran devrait recharger juste après.
            'magasin_id' => ['nullable', 'integer', 'min:1'],
            'tri' => ['nullable', 'string', Rule::in(IndexShoppingListRequest::TRIS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'week_start' => 'début de semaine',
            'magasin_id' => 'magasin',
            'tri' => 'tri',
        ];
    }
}
