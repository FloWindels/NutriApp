<?php

namespace Database\Seeders;

use App\Enums\Enseigne;
use App\Models\Magasin;
use App\Models\MagasinProduit;
use App\Services\Magasins\LibelleProduit;
use Illuminate\Database\Seeder;

/**
 * Assortiment de départ des quatre enseignes, avec des prix INDICATIFS.
 *
 * Il faut être clair sur ce que ce fichier est, et sur ce qu'il n'est pas.
 *
 * Ce ne sont PAS les prix du jour. Ce sont des ordres de grandeur relevés en Belgique à la date
 * portée par `RELEVE_LE`, écrits pour que l'application ait quelque chose d'utile à montrer dès
 * l'installation. Chaque ligne est publiée avec cette date, l'API le répète et l'écran devra
 * l'afficher : personne ne doit croire qu'il consulte une caisse.
 *
 * Aucune donnée n'est aspirée du site des enseignes : elles l'interdisent, et ce n'est pas ce
 * qui a été choisi. Le propriétaire corrige et complète par `php artisan mavioh:magasin-importer`,
 * sans toucher au code.
 *
 * L'assortiment est commun aux quatre enseignes — ce sont les produits de base, elles les vendent
 * toutes — et seuls la marque et le niveau de prix changent. Prétendre connaître les références
 * exactes de chaque magasin serait inventer.
 *
 * Idempotent : upsert par (magasin, libellé normalisé).
 */
class MagasinSeeder extends Seeder
{
    /** Date du relevé des prix de base. Elle voyage avec chaque ligne, jusqu'à l'écran. */
    public const RELEVE_LE = '2026-09-01';

    /**
     * Positionnement tarifaire relatif, Colruyt servant de référence à 1,00.
     *
     * C'est volontairement grossier : un coefficient unique par enseigne dit une tendance
     * observable — les enseignes à bas prix sont moins chères — sans prétendre à une précision
     * qu'on n'a pas.
     *
     * @var array<string, array{marque: string|null, coefficient: float}>
     */
    private const POSITIONNEMENT = [
        'aldi' => ['marque' => 'Aldi', 'coefficient' => 0.92],
        'lidl' => ['marque' => 'Lidl', 'coefficient' => 0.94],
        'colruyt' => ['marque' => 'Boni Selection', 'coefficient' => 1.00],
        'delhaize' => ['marque' => 'Delhaize', 'coefficient' => 1.12],
    ];

    public function run(): void
    {
        $maintenant = now();
        $base = self::assortiment();

        foreach (Enseigne::cases() as $enseigne) {
            $magasin = Magasin::query()->updateOrCreate(
                ['enseigne' => $enseigne->value, 'nom' => $enseigne->label()],
                ['pays' => 'BE', 'actif' => true],
            );

            $position = self::POSITIONNEMENT[$enseigne->value];

            $lignes = [];
            foreach ($base as $produit) {
                [$libelle, $rayon, $unite, $quantite, $prix] = $produit;

                $lignes[] = [
                    'magasin_id' => $magasin->id,
                    'libelle' => $libelle,
                    'libelle_normalise' => LibelleProduit::normaliser($libelle),
                    'marque' => $position['marque'],
                    'rayon' => $rayon,
                    'code_barres' => null,
                    'prix_indicatif' => round($prix * $position['coefficient'], 2),
                    'unite' => $unite,
                    'quantite_reference' => $quantite,
                    'food_id' => null,
                    'prix_maj_le' => self::RELEVE_LE,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ];
            }

            foreach (array_chunk($lignes, 50) as $paquet) {
                MagasinProduit::upsert(
                    $paquet,
                    ['magasin_id', 'libelle_normalise'],
                    ['libelle', 'marque', 'rayon', 'prix_indicatif', 'unite', 'quantite_reference', 'prix_maj_le', 'updated_at'],
                );
            }

            $this->command?->info($enseigne->label().' : '.count($lignes).' produits synchronisés (prix indicatifs du '.self::RELEVE_LE.').');
        }
    }

    /**
     * Produits de base, communs aux quatre enseignes.
     *
     * `prix` est le prix de RÉFÉRENCE (Colruyt) pour `quantite` unité(s) de `unite` — pas un prix
     * au kilo. C'est le prix affiché en rayon pour le conditionnement courant, ce qui permet à la
     * fois d'estimer un panier et de recalculer un prix au kilo comparable.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: float, 4: float}>
     */
    public static function assortiment(): array
    {
        return [
            // --- Fruits et légumes ---
            ['Pommes Jonagold', 'fruits_legumes', 'kg', 1.0, 2.49],
            ['Bananes', 'fruits_legumes', 'kg', 1.0, 1.99],
            ['Oranges', 'fruits_legumes', 'kg', 1.0, 2.29],
            ['Clémentines', 'fruits_legumes', 'kg', 1.0, 2.99],
            ['Poires Conférence', 'fruits_legumes', 'kg', 1.0, 2.69],
            ['Raisins blancs', 'fruits_legumes', 'kg', 1.0, 3.99],
            ['Fraises', 'fruits_legumes', 'kg', 0.5, 3.49],
            ['Citrons', 'fruits_legumes', 'kg', 1.0, 2.79],
            ['Tomates grappe', 'fruits_legumes', 'kg', 1.0, 2.99],
            ['Carottes', 'fruits_legumes', 'kg', 1.0, 1.39],
            ['Pommes de terre', 'fruits_legumes', 'kg', 5.0, 5.49],
            ['Oignons', 'fruits_legumes', 'kg', 1.0, 1.49],
            ['Courgettes', 'fruits_legumes', 'kg', 1.0, 2.29],
            ['Brocoli', 'fruits_legumes', 'piece', 1.0, 1.69],
            ['Chou-fleur', 'fruits_legumes', 'piece', 1.0, 1.99],
            ['Poivrons rouges', 'fruits_legumes', 'kg', 1.0, 3.99],
            ['Salade iceberg', 'fruits_legumes', 'piece', 1.0, 1.29],
            ['Épinards frais', 'fruits_legumes', 'kg', 0.3, 1.89],
            ['Champignons de Paris', 'fruits_legumes', 'kg', 0.25, 1.79],
            ['Ail', 'fruits_legumes', 'piece', 1.0, 0.89],
            ['Concombre', 'fruits_legumes', 'piece', 1.0, 0.99],
            ['Poireaux', 'fruits_legumes', 'kg', 1.0, 2.49],
            ['Haricots verts', 'fruits_legumes', 'kg', 1.0, 4.49],
            ['Avocat', 'fruits_legumes', 'piece', 1.0, 1.49],

            // --- Pain et boulangerie ---
            ['Pain gris', 'boulangerie', 'piece', 1.0, 1.79],
            ['Pain complet', 'boulangerie', 'piece', 1.0, 1.99],
            ['Baguette', 'boulangerie', 'piece', 1.0, 0.99],
            ['Pain pita', 'boulangerie', 'piece', 6.0, 1.29],
            ['Wraps de blé', 'boulangerie', 'piece', 8.0, 1.99],
            ['Biscottes', 'boulangerie', 'kg', 0.25, 1.39],

            // --- Boucherie et volaille ---
            ['Filet de poulet', 'boucherie', 'kg', 1.0, 9.99],
            ['Cuisses de poulet', 'boucherie', 'kg', 1.0, 5.49],
            ['Haché de bœuf', 'boucherie', 'kg', 1.0, 11.99],
            ['Haché porc et veau', 'boucherie', 'kg', 1.0, 9.49],
            ['Steak de bœuf', 'boucherie', 'kg', 1.0, 17.99],
            ['Rôti de porc', 'boucherie', 'kg', 1.0, 9.99],
            ['Côtes de porc', 'boucherie', 'kg', 1.0, 8.49],
            ['Lardons fumés', 'boucherie', 'kg', 0.2, 2.29],
            ['Jambon cuit', 'boucherie', 'kg', 0.15, 2.19],
            ['Saucisses de volaille', 'boucherie', 'kg', 0.4, 3.99],

            // --- Poissonnerie ---
            ['Filet de saumon', 'poissonnerie', 'kg', 1.0, 24.99],
            ['Filet de cabillaud', 'poissonnerie', 'kg', 1.0, 19.99],
            ['Filet de colin', 'poissonnerie', 'kg', 1.0, 13.99],
            ['Crevettes grises', 'poissonnerie', 'kg', 0.1, 4.49],
            ['Truite fumée', 'poissonnerie', 'kg', 0.1, 3.49],

            // --- Crèmerie et œufs ---
            ['Lait demi-écrémé', 'cremerie', 'l', 1.0, 1.09],
            ['Lait entier', 'cremerie', 'l', 1.0, 1.19],
            ['Beurre', 'cremerie', 'kg', 0.25, 2.69],
            ['Œufs frais', 'cremerie', 'piece', 10.0, 2.79],
            ['Yaourt nature', 'cremerie', 'kg', 1.0, 2.49],
            ['Yaourt grec', 'cremerie', 'kg', 0.5, 2.19],
            ['Fromage blanc maigre', 'cremerie', 'kg', 0.5, 1.89],
            ['Gouda en tranches', 'cremerie', 'kg', 0.2, 2.49],
            ['Emmental râpé', 'cremerie', 'kg', 0.2, 2.29],
            ['Mozzarella', 'cremerie', 'kg', 0.125, 1.09],
            ['Crème fraîche', 'cremerie', 'l', 0.2, 1.19],
            ['Feta', 'cremerie', 'kg', 0.2, 2.29],

            // --- Pâtes, riz et féculents ---
            ['Riz basmati', 'feculents', 'kg', 1.0, 2.99],
            ['Riz long grain', 'feculents', 'kg', 1.0, 2.19],
            ['Spaghetti', 'feculents', 'kg', 0.5, 1.19],
            ['Penne', 'feculents', 'kg', 0.5, 1.19],
            ['Macaroni', 'feculents', 'kg', 0.5, 1.09],
            ['Couscous', 'feculents', 'kg', 0.5, 1.39],
            ['Quinoa', 'feculents', 'kg', 0.5, 3.49],
            ['Lentilles sèches', 'feculents', 'kg', 0.5, 1.79],
            ['Farine de froment', 'feculents', 'kg', 1.0, 1.09],
            ['Flocons d’avoine', 'feculents', 'kg', 0.5, 1.49],

            // --- Épicerie ---
            ['Huile d’olive', 'epicerie', 'l', 1.0, 7.99],
            ['Huile de tournesol', 'epicerie', 'l', 1.0, 2.49],
            ['Thon au naturel', 'epicerie', 'kg', 0.145, 1.79],
            ['Tomates pelées', 'epicerie', 'kg', 0.4, 0.89],
            ['Pois chiches', 'epicerie', 'kg', 0.4, 0.99],
            ['Haricots rouges', 'epicerie', 'kg', 0.4, 0.99],
            ['Sauce tomate', 'epicerie', 'kg', 0.5, 1.29],
            ['Sel fin', 'epicerie', 'kg', 1.0, 0.59],
            ['Poivre noir moulu', 'epicerie', 'kg', 0.05, 1.29],
            ['Sucre', 'epicerie', 'kg', 1.0, 1.39],
            ['Miel', 'epicerie', 'kg', 0.5, 4.49],
            ['Beurre de cacahuète', 'epicerie', 'kg', 0.35, 2.49],
            ['Café moulu', 'epicerie', 'kg', 0.5, 4.99],
            ['Thé noir', 'epicerie', 'piece', 20.0, 1.49],
            ['Vinaigre balsamique', 'epicerie', 'l', 0.5, 2.29],
            ['Moutarde', 'epicerie', 'kg', 0.3, 1.29],

            // --- Surgelés ---
            ['Petits pois surgelés', 'surgeles', 'kg', 1.0, 2.19],
            ['Épinards surgelés', 'surgeles', 'kg', 0.45, 1.29],
            ['Poêlée de légumes surgelée', 'surgeles', 'kg', 1.0, 3.29],
            ['Poisson pané', 'surgeles', 'kg', 0.4, 3.49],
            ['Frites surgelées', 'surgeles', 'kg', 1.0, 2.19],

            // --- Boissons ---
            ['Eau plate', 'boissons', 'l', 6.0, 1.99],
            ['Eau pétillante', 'boissons', 'l', 6.0, 2.49],
            ['Jus d’orange', 'boissons', 'l', 1.0, 1.69],
            ['Jus de pomme', 'boissons', 'l', 1.0, 1.39],
            ['Boisson à l’amande', 'boissons', 'l', 1.0, 1.99],
        ];
    }
}
