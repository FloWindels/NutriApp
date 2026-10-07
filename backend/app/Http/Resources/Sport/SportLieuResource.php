<?php

namespace App\Http\Resources\Sport;

use App\Models\SportLieu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lieu d'entraînement (Sport v2 §1) : `{id, nom, type, type_libelle, materiel, par_defaut}`.
 *
 * @mixin SportLieu
 */
class SportLieuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SportLieu $lieu */
        $lieu = $this->resource;

        return [
            'id' => (int) $lieu->id,
            'nom' => (string) $lieu->nom,
            'type' => (string) $lieu->type,
            'type_libelle' => $lieu->typeLibelle(),
            'materiel' => $lieu->equipement(),
            'par_defaut' => (bool) $lieu->par_defaut,
        ];
    }
}
