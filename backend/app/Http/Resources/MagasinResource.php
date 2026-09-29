<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Magasin du catalogue public.
 *
 * @mixin \App\Models\Magasin
 */
class MagasinResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enseigne' => $this->enseigne->value,
            'enseigne_libelle' => $this->enseigne->label(),
            'nom' => $this->nom,
            'pays' => $this->pays,
            'actif' => (bool) $this->actif,
            'produits_count' => $this->whenCounted('produits'),
        ];
    }
}
