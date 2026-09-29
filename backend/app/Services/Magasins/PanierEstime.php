<?php

namespace App\Services\Magasins;

use App\Enums\Rayon;
use App\Http\Resources\ShoppingItemResource;
use App\Models\Magasin;
use App\Models\MagasinProduit;
use App\Models\Promotion;
use App\Models\ShoppingItem;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Collection;

/**
 * La liste de courses vue depuis un magasin : rayon, prix indicatif, promotions, total estimé.
 *
 * Ce service porte la promesse la plus délicate du module. Un total affiché ressemble à une
 * addition ; ce n'en est pas une. Trois règles le tiennent honnête :
 *
 *  - il ne compte que les lignes réellement rattachées à un produit d'enseigne. Une ligne sans
 *    correspondance ne reçoit AUCUN prix, pas même une moyenne : elle est signalée à part ;
 *  - il dit toujours de quand datent les prix retenus, et le mot « estimé » l'accompagne partout ;
 *  - il n'extrapole pas les quantités. Quand l'unité de l'article se ramène à celle du produit,
 *    il compte le nombre d'emballages nécessaires ; sinon il compte un exemplaire, et le dit.
 */
class PanierEstime
{
    public const AVERTISSEMENT = 'Total estimé à partir de prix indicatifs, pas des prix du jour. Ce n’est pas le montant que tu paieras en caisse.';

    /** Ramène une unité à sa base comparable : kg pour les masses, l pour les volumes. */
    private const BASES = [
        'g' => ['kg', 0.001],
        'kg' => ['kg', 1.0],
        'mg' => ['kg', 0.000001],
        'ml' => ['l', 0.001],
        'cl' => ['l', 0.01],
        'dl' => ['l', 0.1],
        'l' => ['l', 1.0],
    ];

    public function __construct(
        private readonly CorrespondanceProduits $correspondance,
        private readonly PromotionsActives $promotions,
    ) {
    }

    /**
     * @param  Collection<int, ShoppingItem>  $items
     * @param  string  $tri  « rayon » pour l'ordre de traversée du magasin, « ajout » sinon
     * @return array{data: list<array<string, mixed>>, magasin: array<string, mixed>|null, tri: string, rayons: list<array<string, mixed>>, estimation: array<string, mixed>}
     */
    public function composer(User $user, Collection $items, ?Magasin $magasin, string $tri = 'ajout'): array
    {
        $lignes = ShoppingItemResource::collection($items)->resolve();

        if ($magasin === null) {
            return [
                'data' => array_map(fn (array $ligne) => $ligne + ['magasin_produit' => null], $lignes),
                'magasin' => null,
                'tri' => 'ajout',
                'rayons' => [],
                'estimation' => $this->estimationVide(count($lignes)),
            ];
        }

        $produits = $this->correspondance->pourArticles($magasin, $items->map(fn (ShoppingItem $item) => [
            'ean' => $this->ean($item),
            'libelle' => (string) $item->label,
        ])->values()->all());

        $promotions = $this->promotionsParProduit($magasin, Clock::today($user));

        $total = 0.0;
        $economie = 0.0;
        $estimees = 0;
        $sansPrix = 0;
        $dates = [];
        $rayons = [];

        foreach ($items->values() as $rang => $item) {
            $produit = $produits[$rang] ?? null;
            $promotion = $produit !== null ? ($promotions[$produit->id] ?? null) : null;

            $detail = $this->detail($item, $produit, $promotion);
            $lignes[$rang]['magasin_produit'] = $detail;

            if ($detail === null || $detail['prix_ligne_estime'] === null) {
                $sansPrix++;

                continue;
            }

            $estimees++;
            $total += $detail['prix_ligne_estime'];
            $economie += $detail['economie_estimee'] ?? 0.0;

            if ($detail['prix_maj_le'] !== null) {
                $dates[] = $detail['prix_maj_le'];
            }

            $rayons[$detail['rayon']] = true;
        }

        if ($tri === 'rayon') {
            $lignes = $this->trierParRayon($lignes);
        }

        sort($dates);

        return [
            'data' => array_values($lignes),
            'magasin' => [
                'id' => $magasin->id,
                'enseigne' => $magasin->enseigne->value,
                'enseigne_libelle' => $magasin->enseigne->label(),
                'nom' => $magasin->nom,
                'pays' => $magasin->pays,
            ],
            'tri' => $tri === 'rayon' ? 'rayon' : 'ajout',
            'rayons' => $this->rayonsPresents(array_keys($rayons)),
            'estimation' => [
                'total_estime' => round($total, 2),
                'devise' => 'EUR',
                'indicatif' => true,
                'lignes_estimees' => $estimees,
                'lignes_sans_prix' => $sansPrix,
                'economie_promotions_estimee' => round($economie, 2),
                'prix_les_plus_anciens' => $dates[0] ?? null,
                'avertissement' => self::AVERTISSEMENT,
            ],
        ];
    }

    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function detail(ShoppingItem $item, ?MagasinProduit $produit, ?Promotion $promotion): ?array
    {
        if ($produit === null) {
            return null;
        }

        $rayon = $produit->rayon instanceof Rayon ? $produit->rayon : Rayon::Autre;
        $unitaire = $produit->prix_indicatif;
        $promo = $promotion?->prix_promotionnel;
        $retenu = $promo ?? $unitaire;

        $emballages = $this->emballages($item, $produit);
        $ligne = $retenu === null ? null : round($retenu * $emballages, 2);
        $economie = ($promo !== null && $unitaire !== null && $unitaire > $promo)
            ? round(($unitaire - $promo) * $emballages, 2)
            : null;

        return [
            'id' => $produit->id,
            'libelle' => $produit->libelle,
            'marque' => $produit->marque,
            'rayon' => $rayon->value,
            'rayon_libelle' => $rayon->label(),
            'rayon_ordre' => $rayon->ordre(),
            'prix_indicatif' => $unitaire,
            'unite' => $produit->unite,
            'quantite_reference' => $produit->quantite_reference,
            'prix_par_unite_base' => $produit->prixParUniteBase(),
            'prix_maj_le' => $produit->prix_maj_le?->format('Y-m-d'),
            'emballages_estimes' => $emballages,
            'prix_ligne_estime' => $ligne,
            'economie_estimee' => $economie,
            'promotion' => $promotion === null ? null : [
                'id' => $promotion->id,
                'prix_promotionnel' => $promotion->prix_promotionnel,
                'prix_avant' => $promotion->prix_avant,
                'remise_pourcent' => $promotion->remisePourcent(),
                'fin' => $promotion->fin?->format('Y-m-d'),
                'verifiee' => (bool) $promotion->verifiee,
                'source' => $promotion->source,
            ],
        ];
    }

    /**
     * Combien d'emballages faut-il ? Seulement quand les deux unités se ramènent à la même base ;
     * sinon un seul, parce qu'un article « 1 pièce » ne se convertit pas en kilos sans mentir.
     */
    private function emballages(ShoppingItem $item, MagasinProduit $produit): int
    {
        $quantite = (float) ($item->quantity ?? 0);
        $reference = (float) $produit->quantite_reference;

        if ($quantite <= 0 || $reference <= 0) {
            return 1;
        }

        $article = self::BASES[mb_strtolower((string) $item->unit)] ?? null;
        $cible = self::BASES[mb_strtolower((string) $produit->unite)] ?? null;

        if ($article === null || $cible === null || $article[0] !== $cible[0]) {
            return 1;
        }

        $besoin = $quantite * $article[1];
        $paquet = $reference * $cible[1];

        return max(1, (int) ceil($besoin / $paquet));
    }

    /**
     * Promotions en cours indexées par produit d'enseigne. Une promotion sans produit rattaché ne
     * peut pas changer un prix de ligne : elle n'existe ici que pour l'écran des promotions.
     *
     * @return array<int, Promotion>
     */
    private function promotionsParProduit(Magasin $magasin, string $jour): array
    {
        $parProduit = [];

        foreach ($this->promotions->pour($magasin, $jour) as $promotion) {
            if ($promotion->magasin_produit_id !== null && ! isset($parProduit[$promotion->magasin_produit_id])) {
                $parProduit[$promotion->magasin_produit_id] = $promotion;
            }
        }

        return $parProduit;
    }

    /**
     * Ordre de traversée du magasin. Les lignes sans correspondance finissent ensemble à la fin :
     * ce sont celles qu'on cherchera au jugé, autant les regrouper.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array<string, mixed>>
     */
    private function trierParRayon(array $lignes): array
    {
        $indexes = array_keys($lignes);

        usort($indexes, function (int $a, int $b) use ($lignes) {
            $ordreA = $lignes[$a]['magasin_produit']['rayon_ordre'] ?? PHP_INT_MAX;
            $ordreB = $lignes[$b]['magasin_produit']['rayon_ordre'] ?? PHP_INT_MAX;

            // À rayon égal, l'ordre d'arrivée est conservé : la liste ne doit pas se réorganiser
            // sous les yeux de la personne à chaque rafraîchissement.
            return [$ordreA, $a] <=> [$ordreB, $b];
        });

        return array_map(fn (int $index) => $lignes[$index], $indexes);
    }

    /**
     * @param  list<string>  $cles
     * @return list<array<string, mixed>>
     */
    private function rayonsPresents(array $cles): array
    {
        $presents = array_filter(
            Rayon::catalogue(),
            fn (array $rayon) => in_array($rayon['cle'], $cles, true),
        );

        return array_values($presents);
    }

    /** @return array<string, mixed> */
    private function estimationVide(int $lignes): array
    {
        return [
            'total_estime' => null,
            'devise' => 'EUR',
            'indicatif' => true,
            'lignes_estimees' => 0,
            'lignes_sans_prix' => $lignes,
            'economie_promotions_estimee' => 0.0,
            'prix_les_plus_anciens' => null,
            'avertissement' => 'Aucun magasin choisi : aucun prix n’est estimé.',
        ];
    }

    private function ean(ShoppingItem $item): ?string
    {
        $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;
        $ean = trim((string) ($food?->barcode ?? ''));

        return $ean === '' ? null : $ean;
    }
}
