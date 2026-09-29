<?php

namespace App\Http\Requests\Magasins;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction d'une promotion. C'est ici qu'on passe `verifiee` à vrai : vérifier, c'est avoir
 * regardé le prix en magasin ou sur le prospectus, et c'est un geste humain.
 */
class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['sometimes', 'string', 'max:160'],
            'magasin_produit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'prix_promotionnel' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'prix_avant' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'debut' => ['sometimes', 'date_format:Y-m-d'],
            'fin' => ['sometimes', 'date_format:Y-m-d'],
            'verifiee' => ['sometimes', 'boolean'],
        ];
    }
}
