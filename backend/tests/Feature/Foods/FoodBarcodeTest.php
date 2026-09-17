<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodBarcodeTest extends TestCase
{
    use OffFixtures;
    use RefreshDatabase;

    public function test_aliment_local_renvoye_sans_appel_off(): void
    {
        Http::fake();
        $food = Food::factory()->create(['barcode' => '3017620422003', 'name' => 'Local']);

        $this->getJson('/api/foods/barcode/3017620422003')
            ->assertOk()
            ->assertJsonPath('data.id', $food->id)
            ->assertJsonPath('data.name', 'Local')
            ->assertJsonPath('data.is_owner', false);

        Http::assertNothingSent();
    }

    public function test_un_code_12_chiffres_retrouve_la_fiche_stockee_sur_13_chiffres(): void
    {
        Http::fake();
        $food = Food::factory()->create(['barcode' => '0123456789012']);

        $this->getJson('/api/foods/barcode/123456789012')->assertOk()->assertJsonPath('data.id', $food->id);
        Http::assertNothingSent();
    }

    public function test_un_code_13_chiffres_avec_zero_retrouve_la_fiche_stockee_sur_12_chiffres(): void
    {
        Http::fake();
        $food = Food::factory()->create(['barcode' => '123456789012']);

        $this->getJson('/api/foods/barcode/0123456789012')->assertOk()->assertJsonPath('data.id', $food->id);
        Http::assertNothingSent();
    }

    public function test_le_code_scanne_est_prioritaire_sur_les_candidats(): void
    {
        Http::fake();
        $exact = Food::factory()->create(['barcode' => '0123456789012', 'name' => 'Exact']);
        Food::factory()->create(['barcode' => '123456789012', 'name' => 'Sans zéro']);

        $this->getJson('/api/foods/barcode/0123456789012')->assertOk()->assertJsonPath('data.id', $exact->id);
    }

    public function test_absence_locale_importe_depuis_off_avec_kcal_derivees_des_kj(): void
    {
        $this->fakeOffProduct('3560070000000', $this->offKjOnly('3560070000000'));

        $response = $this->getJson('/api/foods/barcode/3560070000000');

        $response->assertOk()
            ->assertJsonPath('data.barcode', '3560070000000')
            ->assertJsonPath('data.name', 'Biscuits nature')
            ->assertJsonPath('data.brand', 'Marque Repère')
            ->assertJsonPath('data.source_type', 'open_food_facts')
            ->assertJsonPath('data.created_by_user_id', null)
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.is_estimate', true)
            ->assertJsonPath('data.is_verified', false);

        $this->assertSame(round(1900 / 4.184, 1), $response->json('data.calories'));
        $this->assertNotNull($response->json('data.source_fetched_at'));

        $food = Food::where('barcode', '3560070000000')->firstOrFail();
        $this->assertNotNull($food->off_last_checked_at);
        $this->assertNull($food->created_by_user_id);
    }

    public function test_import_off_respecte_la_priorite_des_noms_et_normalise_la_fiche(): void
    {
        $this->fakeOffProduct('3017620422003', $this->offNutella());

        $response = $this->getJson('/api/foods/barcode/3017620422003');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Nutella pâte à tartiner')
            ->assertJsonPath('data.brand', 'Ferrero')
            ->assertJsonPath('data.image_url', 'https://images.off.test/nutella.jpg')
            ->assertJsonPath('data.calories', 539)
            ->assertJsonPath('data.fat', 30.9)
            ->assertJsonPath('data.serving_size_g', 15)
            ->assertJsonPath('data.serving_label', '15 g')
            ->assertJsonPath('data.category', 'spreads')
            ->assertJsonPath('data.allergens', ['en:milk', 'en:nuts'])
            ->assertJsonPath('data.per_unit', '100g')
            ->assertJsonPath('data.is_estimate', true);
    }

    public function test_off_status_0_donne_404_avec_le_message_dedie(): void
    {
        $this->fakeOffProduct('3017620422003', null);

        $this->getJson('/api/foods/barcode/3017620422003')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Produit introuvable, même sur Open Food Facts.']);

        $this->assertDatabaseCount('food', 0);
    }

    public function test_code_non_numerique_donne_404_sans_appel_off(): void
    {
        Http::fake();

        $this->getJson('/api/foods/barcode/abc-def')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Produit introuvable, même sur Open Food Facts.']);

        Http::assertNothingSent();
    }

    public function test_off_en_erreur_500_donne_502(): void
    {
        $this->fakeOffProduct('3017620422003', null, 500);

        $this->getJson('/api/foods/barcode/3017620422003')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Open Food Facts indisponible.']);
    }

    public function test_fiche_off_non_verifiee_de_plus_de_90_jours_est_rafraichie(): void
    {
        $stale = now()->subDays(91);
        $food = Food::factory()->openFoodFacts()->create([
            'barcode' => '3017620422003', 'name' => 'Ancien nom', 'calories' => 100, 'fat' => null,
            'off_last_checked_at' => $stale, 'source_fetched_at' => $stale,
        ]);

        $this->fakeOffProduct('3017620422003', $this->offNutella());

        $this->getJson('/api/foods/barcode/3017620422003')
            ->assertOk()
            ->assertJsonPath('data.id', $food->id)
            ->assertJsonPath('data.name', 'Nutella pâte à tartiner')
            ->assertJsonPath('data.calories', 539)
            ->assertJsonPath('data.fat', 30.9);

        $food->refresh();
        $this->assertTrue($food->off_last_checked_at->gt(now()->subMinute()));
        $this->assertTrue($food->source_fetched_at->gt(now()->subMinute()));
        $this->assertSame('open_food_facts', $food->source_type);
    }

    public function test_fiche_off_verifiee_de_plus_de_90_jours_ne_recoit_que_ses_champs_manquants(): void
    {
        $food = Food::factory()->openFoodFacts()->verified()->create([
            'barcode' => '3017620422003', 'name' => 'Nom vérifié', 'calories' => 500, 'fat' => null, 'sugar' => null,
            'off_last_checked_at' => now()->subDays(91),
        ]);

        $this->fakeOffProduct('3017620422003', $this->offNutella());

        $this->getJson('/api/foods/barcode/3017620422003')
            ->assertOk()
            ->assertJsonPath('data.name', 'Nom vérifié')
            ->assertJsonPath('data.calories', 500)
            ->assertJsonPath('data.fat', 30.9)
            ->assertJsonPath('data.sugar', 56.3)
            ->assertJsonPath('data.is_estimate', false);

        $this->assertTrue($food->fresh()->off_last_checked_at->gt(now()->subMinute()));
    }

    public function test_fiche_off_recente_n_est_pas_rafraichie(): void
    {
        Http::fake();
        Food::factory()->openFoodFacts()->create([
            'barcode' => '3017620422003', 'off_last_checked_at' => now()->subDays(89),
        ]);

        $this->getJson('/api/foods/barcode/3017620422003')->assertOk();
        Http::assertNothingSent();
    }

    public function test_fiche_off_jamais_verifiee_est_rafraichie(): void
    {
        Food::factory()->openFoodFacts()->create([
            'barcode' => '3017620422003', 'name' => 'Ancien', 'off_last_checked_at' => null,
        ]);
        $this->fakeOffProduct('3017620422003', $this->offNutella());

        $this->getJson('/api/foods/barcode/3017620422003')->assertOk()->assertJsonPath('data.name', 'Nutella pâte à tartiner');
    }

    public function test_panne_off_lors_du_rafraichissement_est_ignoree(): void
    {
        $stale = now()->subDays(91);
        $food = Food::factory()->openFoodFacts()->create([
            'barcode' => '3017620422003', 'name' => 'Ancien nom', 'off_last_checked_at' => $stale,
        ]);
        $this->fakeOffProduct('3017620422003', null, 500);

        $this->getJson('/api/foods/barcode/3017620422003')->assertOk()->assertJsonPath('data.name', 'Ancien nom');

        $this->assertSame($stale->toDateString(), $food->fresh()->off_last_checked_at->toDateString());
    }

    public function test_aliment_manuel_ancien_jamais_rafraichi(): void
    {
        Http::fake();
        Food::factory()->create([
            'barcode' => '3017620422003', 'source_type' => 'manual', 'off_last_checked_at' => null,
        ]);

        $this->getJson('/api/foods/barcode/3017620422003')->assertOk();
        Http::assertNothingSent();
    }

    public function test_is_owner_et_is_favorite_pour_un_lecteur_connecte(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $food = Food::factory()->create(['barcode' => '3017620422003', 'created_by_user_id' => $user->id]);
        $user->favoriteFoods()->attach($food->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/foods/barcode/3017620422003')
            ->assertOk()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.is_favorite', true);
    }
}
