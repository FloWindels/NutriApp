<?php

namespace App\Http\Requests\Foods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /foods/search?q=&barcode=&page=&per_page=&off=1 (public).
 */
class SearchFoodsRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'off' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'recherche',
            'barcode' => 'code-barres',
            'page' => 'page',
            'per_page' => 'éléments par page',
            'off' => 'recherche Open Food Facts',
        ];
    }
}
