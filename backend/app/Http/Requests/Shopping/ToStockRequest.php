<?php

namespace App\Http\Requests\Shopping;

use Illuminate\Foundation\Http\FormRequest;

class ToStockRequest extends FormRequest
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
            'stock_id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999'],
            'unit' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'stock_id' => 'lieu de stock',
            'expires_at' => 'date de péremption',
            'quantity' => 'quantité',
            'unit' => 'unité',
        ];
    }
}
