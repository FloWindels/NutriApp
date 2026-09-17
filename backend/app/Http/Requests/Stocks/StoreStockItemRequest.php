<?php

namespace App\Http\Requests\Stocks;

use App\Enums\ExpiryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /stocks/items — corps hérité (stock_id, food_id, food_name, food_barcode, food_brand,
 * quantity, unit, expires_at) + expiry_kind, min_quantity, opened_at, calories, fat, carbs,
 * proteins, image_url, source_type (brief §6.2).
 */
class StoreStockItemRequest extends FormRequest
{
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
            'stock_id' => ['nullable', 'integer'],
            'food_id' => ['nullable', 'integer', 'exists:food,id'],
            'food_name' => ['nullable', 'string', 'max:255', 'required_without:food_id'],
            'food_barcode' => ['nullable', 'string', 'max:32'],
            'food_brand' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'expires_at' => ['nullable', 'date'],
            'expiry_kind' => ['nullable', Rule::enum(ExpiryKind::class)],
            'min_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'opened_at' => ['nullable', 'date'],
            'calories' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'proteins' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'source_type' => ['nullable', Rule::in(['manual', 'open_food_facts'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'stock_id' => 'lieu de stock',
            'food_id' => 'aliment',
            'food_name' => 'nom de l’aliment',
            'food_barcode' => 'code-barres',
            'food_brand' => 'marque',
            'quantity' => 'quantité',
            'unit' => 'unité',
            'expires_at' => 'date de péremption',
            'expiry_kind' => 'type de date limite',
            'min_quantity' => 'quantité minimale',
            'opened_at' => 'date d’ouverture',
            'calories' => 'calories',
            'fat' => 'lipides',
            'carbs' => 'glucides',
            'proteins' => 'protéines',
            'image_url' => 'image',
            'source_type' => 'source',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'food_name.required_without' => 'Indique le nom de l’aliment ou choisis un aliment existant.',
        ];
    }
}
