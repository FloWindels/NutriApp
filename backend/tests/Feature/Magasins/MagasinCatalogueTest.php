<?php

namespace Tests\Feature\Magasins;

use Database\Seeders\MagasinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalogue public des enseignes.
 *
 * Le fil de ces tests est le mot « indicatif ». Un prix affiché sans réserve et sans date serait
 * lu comme le prix du jour ; il ne l'est pas, et l'API doit le dire elle-même — pas seulement
 * l'écran, qui pourrait l'oublier.
 */
class MagasinCatalogueTest extends TestCase
{
    use MagasinsHelpers;
    use RefreshDatabase;

    public function test_le_catalogue_est_consultable_sans_compte_et_annonce_ses_rayons(): void
    {
        $this->magasin('lidl');
        $this->magasin('colruyt');

        $reponse = $this->getJson('/api/magasins')->assertOk();

        $this->assertCount(2, $reponse->json('data'));
        $this->assertNotEmpty($reponse->json('rayons'));
        $this->assertStringContainsString('indicatif', mb_strtolower((string) $reponse->json('avertissement')));

        // Les rayons sont rendus dans l'ordre de traversée du magasin, pas dans l'ordre alphabétique.
        $ordres = array_column($reponse->json('rayons'), 'ordre');
        $tries = $ordres;
        sort($tries);
        $this->assertSame($tries, $ordres);
        $this->assertSame('fruits_legumes', $reponse->json('rayons.0.cle'));
    }

    public function test_un_magasin_retire_du_service_disparait_du_catalogue(): void
    {
        $actif = $this->magasin('lidl');
        $retire = $this->magasin('aldi', ['actif' => false]);

        $reponse = $this->getJson('/api/magasins')->assertOk();

        $this->assertSame([$actif->id], array_column($reponse->json('data'), 'id'));

        // Et son assortiment n'est plus consultable non plus : ses prix ne valent plus rien.
        $this->getJson('/api/magasins/'.$retire->id.'/produits')->assertStatus(404);
    }

    public function test_chaque_prix_porte_sa_date_et_sa_reserve(): void
    {
        $magasin = $this->magasin('lidl');
        $this->produit($magasin, 'Riz basmati', ['rayon' => 'feculents', 'prix_indicatif' => 1.79, 'prix_maj_le' => '2026-09-01']);

        $ligne = $this->getJson('/api/magasins/'.$magasin->id.'/produits')->assertOk()->json('data.0');

        $this->assertSame(1.79, $ligne['prix_indicatif']);
        $this->assertSame('2026-09-01', $ligne['prix_maj_le']);
        $this->assertStringContainsString('indicatif', mb_strtolower((string) $ligne['prix_indicatif_avertissement']));
        $this->assertStringContainsString('pas le prix du jour', mb_strtolower((string) $ligne['prix_indicatif_avertissement']));

        // Prix au kilo comparable, recalculé depuis la quantité de référence.
        $this->assertSame(1.79, $ligne['prix_par_unite_base']);
    }

    public function test_l_assortiment_se_filtre_par_rayon_et_par_mot(): void
    {
        $magasin = $this->magasin('colruyt');
        $this->produit($magasin, 'Riz basmati', ['rayon' => 'feculents']);
        $this->produit($magasin, 'Spaghetti', ['rayon' => 'feculents']);
        $this->produit($magasin, 'Bananes', ['rayon' => 'fruits_legumes']);

        $parRayon = $this->getJson('/api/magasins/'.$magasin->id.'/produits?rayon=feculents')->assertOk();
        $this->assertSame(2, $parRayon->json('meta.total'));

        $parMot = $this->getJson('/api/magasins/'.$magasin->id.'/produits?q=banane')->assertOk();
        $this->assertSame(1, $parMot->json('meta.total'));
        $this->assertSame('Bananes', $parMot->json('data.0.libelle'));

        $this->getJson('/api/magasins/'.$magasin->id.'/produits?rayon=inexistant')->assertStatus(422);
    }

    public function test_l_assortiment_de_depart_couvre_les_quatre_enseignes(): void
    {
        $this->seed(MagasinSeeder::class);

        $magasins = $this->getJson('/api/magasins')->assertOk()->json('data');

        $this->assertCount(4, $magasins);
        $this->assertEqualsCanonicalizing(
            ['lidl', 'colruyt', 'delhaize', 'aldi'],
            array_column($magasins, 'enseigne'),
        );

        foreach ($magasins as $magasin) {
            $this->assertGreaterThanOrEqual(60, $magasin['produits_count'], $magasin['nom'].' doit proposer au moins 60 produits de base.');

            $premier = $this->getJson('/api/magasins/'.$magasin['id'].'/produits')->assertOk()->json('data.0');
            $this->assertSame(MagasinSeeder::RELEVE_LE, $premier['prix_maj_le']);
            $this->assertNotNull($premier['prix_indicatif']);
        }
    }

    public function test_le_seeder_se_rejoue_sans_dupliquer(): void
    {
        $this->seed(MagasinSeeder::class);
        $avant = $this->getJson('/api/magasins')->json('data.0.produits_count');

        $this->seed(MagasinSeeder::class);
        $apres = $this->getJson('/api/magasins')->json('data.0.produits_count');

        $this->assertSame($avant, $apres);
    }
}
