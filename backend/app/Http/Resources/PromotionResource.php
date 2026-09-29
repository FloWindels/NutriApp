<?php

namespace App\Http\Resources;

use App\Enums\Rayon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Promotion d'une enseigne.
 *
 * `verifiee` et `source` sont en tête parce qu'ils sont ce qui compte : une promotion relevée par
 * un modèle sur une page web n'est pas un prix, c'est une piste. L'écran doit pouvoir le dire
 * sans aller chercher l'information ailleurs.
 *
 * @mixin \App\Models\Promotion
 */
class PromotionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $produit = $this->relationLoaded('produit') ? $this->getRelation('produit') : null;
        $rayon = $produit?->rayon instanceof Rayon ? $produit->rayon : null;

        return [
            'id' => $this->id,
            'magasin_id' => $this->magasin_id,
            'libelle' => $this->libelle,
            'prix_promotionnel' => $this->prix_promotionnel,
            'prix_avant' => $this->prix_avant,
            'remise_pourcent' => $this->remisePourcent(),
            'debut' => $this->debut?->format('Y-m-d'),
            'fin' => $this->fin?->format('Y-m-d'),
            'verifiee' => (bool) $this->verifiee,
            'source' => $this->source,
            'magasin_produit_id' => $this->magasin_produit_id,
            'produit' => $produit === null ? null : [
                'id' => $produit->id,
                'libelle' => $produit->libelle,
                'rayon' => $rayon?->value,
                'rayon_libelle' => $rayon?->label(),
                'prix_indicatif' => $produit->prix_indicatif,
                'unite' => $produit->unite,
                'quantite_reference' => $produit->quantite_reference,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
