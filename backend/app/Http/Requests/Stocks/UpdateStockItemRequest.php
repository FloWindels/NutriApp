<?php

namespace App\Http\Requests\Stocks;

use App\Enums\ExpiryKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /stocks/items/{item} — {quantity (min 0), unit, expires_at, expiry_kind, stock_id,
 * food_name, min_quantity, opened_at}. Une quantité > 0 lève l'état « épuisé ».
 */
class UpdateStockItemRequest extends FormRequest
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
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'unit' => ['nullable', 'string', 'max:32'],
            'expires_at' => ['nullable', 'date'],
            'expiry_kind' => ['nullable', Rule::enum(ExpiryKind::class)],
            'stock_id' => ['nullable', 'integer'],
            'food_name' => ['nullable', 'string', 'max:255'],
            'min_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'opened_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity' => 'quantité',
            'unit' => 'unité',
            'expires_at' => 'date de péremption',
            'expiry_kind' => 'type de date limite',
            'stock_id' => 'lieu de stock',
            'food_name' => 'nom de l’aliment',
            'min_quantity' => 'quantité minimale',
            'opened_at' => 'date d’ouverture',
        ];
    }
}
