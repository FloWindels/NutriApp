<?php

namespace App\Http\Requests\Magasins;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Promotion saisie à la main. `source` n'est pas demandé : une saisie manuelle a par définition
 * pour source la personne qui l'a tapée, et le serveur l'écrit lui-même.
 */
class StorePromotionRequest extends FormRequest
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
            'libelle' => ['required', 'string', 'max:160'],
            'magasin_produit_id' => ['nullable', 'integer', 'min:1'],
            'prix_promotionnel' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'prix_avant' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'debut' => ['required', 'date_format:Y-m-d'],
            'fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:debut'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fin.after_or_equal' => 'La fin de la promotion ne peut pas précéder son début.',
        ];
    }
}
