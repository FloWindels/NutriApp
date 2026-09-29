<?php

namespace Tests\Feature\Magasins;

use App\Models\Magasin;
use App\Models\MagasinProduit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import d'un assortiment par le propriétaire.
 *
 * Ce que ces tests protègent avant tout, c'est le refus d'inventer : une ligne dont le prix est
 * illisible entre au catalogue SANS prix, jamais à zéro. Un prix à zéro serait compté dans le
 * total estimé et mentirait sur le panier.
 */
class ImportMagasinTest extends TestCase
{
    use RefreshDatabase;

    private function fichier(string $nom, string $contenu): string
    {
        $chemin = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('mavioh-', true).'-'.$nom;
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    public function test_un_csv_cree_le_magasin_et_son_assortiment(): void
    {
        $csv = $this->fichier('lidl.csv', <<<'CSV'
        libelle;rayon;prix;unite;quantite;marque;code_barres
        Riz basmati 1 kg;feculents;1,79;kg;1;Lidl;20123456
        Bananes;fruits_legumes;1,89;kg;1;;
        CSV);

        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'lidl', 'fichier' => $csv, '--date' => '2026-10-05'])
            ->assertSuccessful();

        $magasin = Magasin::query()->where('enseigne', 'lidl')->firstOrFail();
        $this->assertSame(2, MagasinProduit::query()->where('magasin_id', $magasin->id)->count());

        $riz = MagasinProduit::query()->where('libelle_normalise', 'riz basmati')->firstOrFail();
        $this->assertSame(1.79, (float) $riz->prix_indicatif);
        $this->assertSame('feculents', $riz->rayon->value);
        $this->assertSame('20123456', $riz->code_barres);
        $this->assertSame('2026-10-05', $riz->prix_maj_le->format('Y-m-d'));
    }

    public function test_un_prix_illisible_laisse_la_ligne_sans_prix_plutot_qu_a_zero(): void
    {
        $csv = $this->fichier('lidl.csv', <<<'CSV'
        libelle;rayon;prix
        Pain gris;boulangerie;environ 2 euros
        CSV);

        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'lidl', 'fichier' => $csv])->assertSuccessful();

        $pain = MagasinProduit::query()->where('libelle_normalise', 'pain gri')->firstOrFail();
        $this->assertNull($pain->prix_indicatif);
        $this->assertNull($pain->prix_maj_le);
    }

    public function test_un_rayon_inconnu_range_le_produit_en_divers_sans_le_perdre(): void
    {
        $json = $this->fichier('aldi.json', json_encode([
            ['libelle' => 'Bougie parfumée', 'rayon' => 'decoration', 'prix' => 3.49],
        ]));

        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'aldi', 'fichier' => $json])->assertSuccessful();

        $bougie = MagasinProduit::query()->where('libelle_normalise', 'bougie parfumee')->firstOrFail();
        $this->assertSame('autre', $bougie->rayon->value);
    }

    public function test_deux_passages_sur_le_meme_fichier_donnent_le_meme_resultat(): void
    {
        $json = $this->fichier('colruyt.json', json_encode([
            ['libelle' => 'Riz basmati', 'rayon' => 'feculents', 'prix' => 2.99],
        ]));

        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'colruyt', 'fichier' => $json])->assertSuccessful();
        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'colruyt', 'fichier' => $json])->assertSuccessful();

        $this->assertSame(1, MagasinProduit::query()->count());
    }

    public function test_la_simulation_n_ecrit_rien(): void
    {
        $json = $this->fichier('lidl.json', json_encode([['libelle' => 'Riz basmati', 'prix' => 1.79]]));

        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'lidl', 'fichier' => $json, '--simulation' => true])
            ->assertSuccessful();

        $this->assertSame(0, MagasinProduit::query()->count());
    }

    public function test_une_enseigne_inconnue_est_refusee(): void
    {
        $json = $this->fichier('x.json', json_encode([['libelle' => 'Riz']]));

        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'carrefour', 'fichier' => $json])->assertFailed();
        $this->artisan('mavioh:magasin-importer', ['enseigne' => 'lidl', 'fichier' => 'nulle-part.csv'])->assertFailed();
    }
}
