<?php

namespace App\Http\Resources;

use App\Enums\Rayon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Produit d'un assortiment d'enseigne.
 *
 * `prix_indicatif` s'accompagne toujours de `prix_maj_le` et de `prix_indicatif_avertissement` :
 * un prix sans sa date et sans sa réserve serait lu comme le prix du jour, ce qu'il n'est pas.
 *
 * @mixin \App\Models\MagasinProduit
 */
class MagasinProduitResource extends JsonResource
{
    public static $wrap = null;

    public const AVERTISSEMENT = 'Prix indicatif, relevé à la date indiquée. Ce n’est pas le prix du jour en magasin.';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rayon = $this->rayon instanceof Rayon ? $this->rayon : Rayon::Autre;

        return [
            'id' => $this->id,
            'magasin_id' => $this->magasin_id,
            'libelle' => $this->libelle,
            'marque' => $this->marque,
            'rayon' => $rayon->value,
            'rayon_libelle' => $rayon->label(),
            'rayon_ordre' => $rayon->ordre(),
            'code_barres' => $this->code_barres,
            'prix_indicatif' => $this->prix_indicatif,
            'unite' => $this->unite,
            'quantite_reference' => $this->quantite_reference,
            'prix_par_unite_base' => $this->prixParUniteBase(),
            'prix_maj_le' => $this->prix_maj_le?->format('Y-m-d'),
            'prix_indicatif_avertissement' => self::AVERTISSEMENT,
            'food_id' => $this->food_id,
        ];
    }
}
