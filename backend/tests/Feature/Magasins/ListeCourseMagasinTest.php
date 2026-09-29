<?php

namespace Tests\Feature\Magasins;

use App\Models\ShoppingItem;
use App\Models\User;
use App\Services\Magasins\PanierEstime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planner\ModuleM7Helpers;
use Tests\TestCase;

/**
 * La liste de courses regardée depuis un magasin.
 *
 * Trois promesses sont vérifiées ici, et ce sont celles sur lesquelles quelqu'un peut perdre de
 * l'argent : le total n'est jamais présenté comme une addition, un article sans correspondance
 * n'invente pas de prix, et un produit d'une autre enseigne n'apparaît jamais.
 */
class ListeCourseMagasinTest extends TestCase
{
    use MagasinsHelpers;
    use ModuleM7Helpers;
    use RefreshDatabase;

    private function article(User $user, string $label, array $attributs = []): ShoppingItem
    {
        return ShoppingItem::query()->forceCreate(array_replace([
            'user_id' => $user->id,
            'household_id' => null,
            'food_id' => null,
            'label' => $label,
            'quantity' => null,
            'unit' => null,
            'checked' => false,
            'source' => 'manuel',
        ], $attributs));
    }

    public function test_chaque_ligne_porte_son_produit_son_rayon_et_son_prix_indicatif(): void
    {
        $user = $this->login($this->userWithProfile());
        $magasin = $this->magasin('lidl');
        $this->produit($magasin, 'Riz basmati 1 kg', [
            'rayon' => 'feculents', 'prix_indicatif' => 1.79, 'unite' => 'kg', 'quantite_reference' => 1,
        ]);

        $this->article($user, 'Riz basmati', ['quantity' => 500, 'unit' => 'g']);

        $ligne = $this->getJson('/api/shopping-list?magasin_id='.$magasin->id)->assertOk()->json('data.0');

        $this->assertSame('Riz basmati 1 kg', $ligne['magasin_produit']['libelle']);
        $this->assertSame('feculents', $ligne['magasin_produit']['rayon']);
        $this->assertSame('Pâtes, riz et féculents', $ligne['magasin_produit']['rayon_libelle']);
        $this->assertSame(1.79, $ligne['magasin_produit']['prix_indicatif']);
        $this->assertSame('2026-09-01', $ligne['magasin_produit']['prix_maj_le']);

        // 500 g demandés, paquets d'un kilo : un seul paquet suffit.
        $this->assertSame(1, $ligne['magasin_produit']['emballages_estimes']);
        $this->assertSame(1.79, $ligne['magasin_produit']['prix_ligne_estime']);
    }

    public function test_le_total_est_annonce_comme_une_estimation_jamais_comme_un_prix(): void
    {
        $user = $this->login($this->userWithProfile());
        $magasin = $this->magasin('lidl');
        $this->produit($magasin, 'Bananes', ['rayon' => 'fruits_legumes', 'prix_indicatif' => 1.99]);
        $this->produit($magasin, 'Lait demi-écrémé', ['rayon' => 'cremerie', 'prix_indicatif' => 1.09, 'unite' => 'l']);

        $this->article($user, 'Bananes');
        $this->article($user, 'Lait demi-écrémé');

        $reponse = $this->getJson('/api/shopping-list?magasin_id='.$magasin->id)->assertOk();

        $this->assertSame(3.08, $reponse->json('estimation.total_estime'));
        $this->assertSame(2, $reponse->json('estimation.lignes_estimees'));
        $this->assertTrue($reponse->json('estimation.indicatif'));
        $this->assertSame('EUR', $reponse->json('estimation.devise'));
        $this->assertSame('2026-09-01', $reponse->json('estimation.prix_les_plus_anciens'));

        // La clé se nomme « total_estime », et son avertissement dit en toutes lettres que ce
        // n'est pas ce qu'on paiera. Aucune clé ne doit s'appeler « total » tout court.
        $this->assertArrayNotHasKey('total', $reponse->json('estimation'));
        $this->assertSame(PanierEstime::AVERTISSEMENT, $reponse->json('estimation.avertissement'));
        $this->assertStringContainsString('estimé', mb_strtolower($reponse->json('estimation.avertissement')));
        $this->assertStringContainsString('caisse', mb_strtolower($reponse->json('estimation.avertissement')));
    }

    public function test_un_aliment_sans_correspondance_n_invente_aucun_prix(): void
    {
        $user = $this->login($this->userWithProfile());
        $magasin = $this->magasin('lidl');
        $this->produit($magasin, 'Bananes', ['prix_indicatif' => 1.99]);

        $this->article($user, 'Bananes');
        $this->article($user, 'Sirop de liège artisanal');

        $reponse = $this->getJson('/api/shopping-list?magasin_id='.$magasin->id)->assertOk();

        $lignes = collect($reponse->json('data'))->keyBy('label');

        $this->assertNull($lignes['Sirop de liège artisanal']['magasin_produit']);
        $this->assertSame(1, $reponse->json('estimation.lignes_sans_prix'));
        $this->assertSame(1, $reponse->json('estimation.lignes_estimees'));

        // Le total ne compte que ce qui a un prix : l'article inconnu n'est ni estimé, ni moyenné.
        $this->assertSame(1.99, $reponse->json('estimation.total_estime'));
    }

    public function test_un_produit_d_une_autre_enseigne_ne_se_retrouve_jamais_dans_la_liste(): void
    {
        $user = $this->login($this->userWithProfile());
        $lidl = $this->magasin('lidl');
        $colruyt = $this->magasin('colruyt');

        $this->produit($colruyt, 'Riz basmati', ['prix_indicatif' => 2.99]);

        $this->article($user, 'Riz basmati');

        $chezLidl = $this->getJson('/api/shopping-list?magasin_id='.$lidl->id)->assertOk();
        $this->assertNull($chezLidl->json('data.0.magasin_produit'));
        $this->assertEquals(0, $chezLidl->json('estimation.total_estime'));
        $this->assertSame(1, $chezLidl->json('estimation.lignes_sans_prix'));

        $chezColruyt = $this->getJson('/api/shopping-list?magasin_id='.$colruyt->id)->assertOk();
        $this->assertSame(2.99, $chezColruyt->json('data.0.magasin_produit')['prix_indicatif']);
        $this->assertSame($colruyt->id, $chezColruyt->json('magasin.id'));
    }

    public function test_la_liste_se_trie_dans_l_ordre_de_traversee_du_magasin(): void
    {
        $user = $this->login($this->userWithProfile());
        $magasin = $this->magasin('lidl');
        $this->produit($magasin, 'Sel fin', ['rayon' => 'epicerie']);
        $this->produit($magasin, 'Bananes', ['rayon' => 'fruits_legumes']);
        $this->produit($magasin, 'Beurre', ['rayon' => 'cremerie']);

        // Ordre d'ajout volontairement contraire à l'ordre du magasin.
        $this->article($user, 'Sel fin');
        $this->article($user, 'Beurre');
        $this->article($user, 'Bananes');
        $this->article($user, 'Produit inconnu au bataillon');

        $parAjout = $this->getJson('/api/shopping-list?magasin_id='.$magasin->id)->assertOk();
        $this->assertSame(
            ['Sel fin', 'Beurre', 'Bananes', 'Produit inconnu au bataillon'],
            array_column($parAjout->json('data'), 'label'),
        );
        $this->assertSame('ajout', $parAjout->json('tri'));

        $parRayon = $this->getJson('/api/shopping-list?magasin_id='.$magasin->id.'&tri=rayon')->assertOk();
        $this->assertSame(
            ['Bananes', 'Beurre', 'Sel fin', 'Produit inconnu au bataillon'],
            array_column($parRayon->json('data'), 'label'),
        );
        $this->assertSame('rayon', $parRayon->json('tri'));

        // Les rayons présents sont annoncés, eux aussi dans l'ordre de traversée.
        $this->assertSame(
            ['fruits_legumes', 'cremerie', 'epicerie'],
            array_column($parRayon->json('rayons'), 'cle'),
        );
    }

    public function test_sans_magasin_la_liste_reste_celle_d_avant(): void
    {
        $user = $this->login($this->userWithProfile());
        $this->article($user, 'Bananes');

        $reponse = $this->getJson('/api/shopping-list')->assertOk();

        $this->assertNull($reponse->json('magasin'));
        $this->assertNull($reponse->json('data.0.magasin_produit'));
        $this->assertNull($reponse->json('estimation.total_estime'));
        $this->assertSame(1, $reponse->json('counts.total'));
    }

    public function test_le_magasin_prefere_des_reglages_sert_de_defaut(): void
    {
        $user = $this->login($this->userWithProfile());
        $magasin = $this->magasin('aldi');
        $this->produit($magasin, 'Bananes', ['prix_indicatif' => 1.83]);
        $this->article($user, 'Bananes');

        $this->putJson('/api/settings', ['magasin_prefere_id' => $magasin->id])
            ->assertOk()
            ->assertJsonPath('data.magasin_prefere_id', $magasin->id);

        $reponse = $this->getJson('/api/shopping-list')->assertOk();

        $this->assertSame($magasin->id, $reponse->json('magasin.id'));
        $this->assertSame(1.83, $reponse->json('data.0.magasin_produit')['prix_indicatif']);
    }

    public function test_un_magasin_retire_du_service_ne_peut_pas_etre_choisi(): void
    {
        $this->login($this->userWithProfile());
        $retire = $this->magasin('aldi', ['actif' => false]);

        $this->putJson('/api/settings', ['magasin_prefere_id' => $retire->id])->assertStatus(422);

        // Et le demander à la lecture ne le ressuscite pas : la liste répond sans magasin.
        $this->getJson('/api/shopping-list?magasin_id='.$retire->id)->assertOk()->assertJsonPath('magasin', null);
    }

    public function test_une_promotion_en_cours_baisse_le_total_estime_et_se_signale(): void
    {
        $user = $this->login($this->userWithProfile());
        $magasin = $this->magasin('lidl');
        $produit = $this->produit($magasin, 'Filet de poulet', ['rayon' => 'boucherie', 'prix_indicatif' => 9.99]);

        $this->promotion($magasin, 'Filet de poulet', now()->subDay()->toDateString(), now()->addDays(3)->toDateString(), [
            'magasin_produit_id' => $produit->id,
            'prix_promotionnel' => 6.99,
            'prix_avant' => 9.99,
        ]);

        $this->article($user, 'Filet de poulet');

        $reponse = $this->getJson('/api/shopping-list?magasin_id='.$magasin->id)->assertOk();
        $detail = $reponse->json('data.0.magasin_produit');

        $this->assertSame(6.99, $detail['prix_ligne_estime']);
        $this->assertSame(9.99, $detail['prix_indicatif']);
        $this->assertSame(30, $detail['promotion']['remise_pourcent']);
        $this->assertTrue($detail['promotion']['verifiee']);
        $this->assertEquals(3, $reponse->json('estimation.economie_promotions_estimee'));
    }

    public function test_une_promotion_expiree_ne_change_plus_aucun_prix(): void
    {
        $user = $this->login($this->userWithProfile());
        $magasin = $this->magasin('lidl');
        $produit = $this->produit($magasin, 'Filet de poulet', ['prix_indicatif' => 9.99]);

        $this->promotion($magasin, 'Filet de poulet', now()->subDays(10)->toDateString(), now()->subDay()->toDateString(), [
            'magasin_produit_id' => $produit->id,
            'prix_promotionnel' => 6.99,
        ]);

        $this->article($user, 'Filet de poulet');

        $detail = $this->getJson('/api/shopping-list?magasin_id='.$magasin->id)->assertOk()->json('data.0.magasin_produit');

        $this->assertNull($detail['promotion']);
        $this->assertSame(9.99, $detail['prix_ligne_estime']);
    }

    public function test_un_compte_gratuit_ne_voit_rien_de_tout_cela(): void
    {
        $magasin = $this->magasin('lidl');
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $this->getJson('/api/shopping-list?magasin_id='.$magasin->id)->assertStatus(402);
        $this->getJson('/api/magasins/'.$magasin->id.'/promotions')->assertStatus(402);

        // Le catalogue, lui, reste public : c'est une donnée de référence.
        $this->getJson('/api/magasins')->assertOk();
    }
}
