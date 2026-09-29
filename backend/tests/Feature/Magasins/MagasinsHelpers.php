<?php

namespace Tests\Feature\Magasins;

use App\Models\Magasin;
use App\Models\MagasinProduit;
use App\Models\Promotion;
use App\Services\Magasins\LibelleProduit;

/**
 * Aides partagées par les tests des magasins.
 *
 * Les produits passent délibérément par ces fabriques plutôt que par des `forceCreate` recopiés :
 * le libellé normalisé est la clé du rattachement, et un test qui l'écrirait à la main pourrait
 * passer alors que le code de production, lui, normalise autrement.
 */
trait MagasinsHelpers
{
    protected function magasin(string $enseigne = 'lidl', array $attributs = []): Magasin
    {
        return Magasin::query()->create(array_replace([
            'enseigne' => $enseigne,
            'nom' => ucfirst($enseigne),
            'pays' => 'BE',
            'actif' => true,
        ], $attributs));
    }

    protected function produit(Magasin $magasin, string $libelle, array $attributs = []): MagasinProduit
    {
        return MagasinProduit::query()->create(array_replace([
            'magasin_id' => $magasin->id,
            'libelle' => $libelle,
            'libelle_normalise' => LibelleProduit::normaliser($libelle),
            'rayon' => 'epicerie',
            'prix_indicatif' => 2.50,
            'unite' => 'kg',
            'quantite_reference' => 1,
            'prix_maj_le' => '2026-09-01',
        ], $attributs));
    }

    protected function promotion(Magasin $magasin, string $libelle, string $debut, string $fin, array $attributs = []): Promotion
    {
        return Promotion::query()->create(array_replace([
            'magasin_id' => $magasin->id,
            'libelle' => $libelle,
            'libelle_normalise' => LibelleProduit::normaliser($libelle),
            'prix_promotionnel' => 1.50,
            'prix_avant' => 2.50,
            'debut' => $debut,
            'fin' => $fin,
            'source' => Promotion::SOURCE_MANUELLE,
            'verifiee' => true,
        ], $attributs));
    }
}
