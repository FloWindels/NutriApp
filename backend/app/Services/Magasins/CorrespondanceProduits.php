<?php

namespace App\Services\Magasins;

use App\Models\Magasin;
use App\Models\MagasinProduit;
use Illuminate\Support\Collection;

/**
 * Rattachement d'un aliment de la liste au produit correspondant d'une enseigne.
 *
 * Deux voies, dans cet ordre d'exigence décroissante :
 *  1. le code-barres, quand l'article en a un — c'est une identité, pas une ressemblance ;
 *  2. le libellé normalisé, d'abord à l'identique, puis par inclusion d'un libellé dans l'autre
 *     (« riz » ↔ « riz basmati »), le candidat le plus court l'emportant.
 *
 * Quand rien ne correspond, la méthode rend null. C'est un résultat, pas un échec : la ligne
 * restera dans la liste sans prix. Inventer une correspondance approximative coûterait plus cher
 * à la personne — au sens propre — que de ne rien afficher.
 *
 * Tout l'assortiment du magasin tient en mémoire (quelques centaines de lignes) : c'est très
 * largement moins coûteux qu'une requête LIKE par ligne de liste.
 */
final class CorrespondanceProduits
{
    /** @var array<int, array{exacts: array<string, MagasinProduit>, eans: array<string, MagasinProduit>, tous: list<MagasinProduit>}> */
    private array $index = [];

    /**
     * @param  list<array{ean: string|null, libelle: string}>  $articles
     * @return array<int, MagasinProduit|null> même ordre que $articles
     */
    public function pourArticles(Magasin $magasin, array $articles): array
    {
        $index = $this->index($magasin);

        return array_map(fn (array $article) => $this->trouver($index, $article['ean'], $article['libelle']), $articles);
    }

    /** Un seul article, quand l'appelant n'en a qu'un à rattacher. */
    public function pourArticle(Magasin $magasin, ?string $ean, string $libelle): ?MagasinProduit
    {
        return $this->trouver($this->index($magasin), $ean, $libelle);
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  array{exacts: array<string, MagasinProduit>, eans: array<string, MagasinProduit>, tous: list<MagasinProduit>}  $index
     */
    private function trouver(array $index, ?string $ean, string $libelle): ?MagasinProduit
    {
        $ean = trim((string) $ean);
        if ($ean !== '' && isset($index['eans'][$ean])) {
            return $index['eans'][$ean];
        }

        $cle = LibelleProduit::normaliser($libelle);
        if ($cle === '') {
            return null;
        }

        if (isset($index['exacts'][$cle])) {
            return $index['exacts'][$cle];
        }

        $meilleur = null;
        $meilleurRang = null;

        foreach ($index['tous'] as $produit) {
            $candidat = (string) $produit->libelle_normalise;

            if (! LibelleProduit::correspond($candidat, $cle)) {
                continue;
            }

            // Deux départages, dans cet ordre.
            //
            // D'abord le sens : un produit qui contient tout ce qu'on cherche (« riz » →
            // « riz basmati ») répond mieux qu'un produit dont on ne cherche qu'une partie
            // (« riz basmati complet » → « riz »).
            //
            // Puis la longueur : entre « riz » et « riz au lait », c'est « riz » qu'on voulait.
            $rang = [LibelleProduit::contient($candidat, $cle) ? 0 : 1, mb_strlen($candidat)];

            if ($meilleurRang === null || $rang < $meilleurRang) {
                $meilleur = $produit;
                $meilleurRang = $rang;
            }
        }

        return $meilleur;
    }

    /**
     * @return array{exacts: array<string, MagasinProduit>, eans: array<string, MagasinProduit>, tous: list<MagasinProduit>}
     */
    private function index(Magasin $magasin): array
    {
        if (isset($this->index[$magasin->id])) {
            return $this->index[$magasin->id];
        }

        /** @var Collection<int, MagasinProduit> $produits */
        $produits = MagasinProduit::query()
            ->where('magasin_id', $magasin->id)
            ->orderBy('id')
            ->get();

        $exacts = [];
        $eans = [];

        foreach ($produits as $produit) {
            $cle = (string) $produit->libelle_normalise;
            if ($cle !== '' && ! isset($exacts[$cle])) {
                $exacts[$cle] = $produit;
            }

            $ean = trim((string) $produit->code_barres);
            if ($ean !== '' && ! isset($eans[$ean])) {
                $eans[$ean] = $produit;
            }
        }

        return $this->index[$magasin->id] = [
            'exacts' => $exacts,
            'eans' => $eans,
            'tous' => $produits->all(),
        ];
    }
}
