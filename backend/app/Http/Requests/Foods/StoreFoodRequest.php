<?php

namespace App\Http\Requests\Foods;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /foods — création d'un aliment (code-barres facultatif, sémantique firstOrCreate).
 */
class StoreFoodRequest extends FormRequest
{
    public const BARCODE_REGEX = '/^\d{8,14}$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'barcode' => ['nullable', 'string', 'max:32', 'regex:'.self::BARCODE_REGEX],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'calories' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'proteins' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'fiber' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'sugar' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'salt' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'serving_size_g' => ['nullable', 'numeric', 'min:0.1', 'max:5000'],
            'serving_label' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:100'],
            'per_unit' => ['nullable', Rule::in(['100g', '100ml'])],
            'density_g_per_ml' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
            'source_type' => ['nullable', Rule::in(['manual', 'open_food_facts'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'barcode.regex' => 'Le code-barres doit contenir entre 8 et 14 chiffres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::frenchAttributes();
    }

    /**
     * @return array<string, string>
     */
    public static function frenchAttributes(): array
    {
        return [
            'barcode' => 'code-barres',
            'name' => 'nom',
            'brand' => 'marque',
            'image_url' => 'image',
            'calories' => 'calories',
            'fat' => 'lipides',
            'carbs' => 'glucides',
            'proteins' => 'protéines',
            'fiber' => 'fibres',
            'sugar' => 'sucres',
            'salt' => 'sel',
            'serving_size_g' => 'taille de portion',
            'serving_label' => 'libellé de portion',
            'category' => 'catégorie',
            'per_unit' => 'base nutritionnelle',
            'density_g_per_ml' => 'densité',
            'source_type' => 'source',
        ];
    }
}
