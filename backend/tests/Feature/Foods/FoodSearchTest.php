<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodSearchTest extends TestCase
{
    use OffFixtures;
    use RefreshDatabase;

    public function test_search_renvoie_data_et_meta_avec_20_par_page_par_defaut(): void
    {
        Food::factory()->count(23)->create(['name' => 'Yaourt nature', 'brand' => null]);

        $response = $this->getJson('/api/foods/search?q=yaourt');

        $response->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJson([
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 2,
                    'per_page' => 20,
                    'total' => 23,
                    'off_queried' => false,
                ],
            ]);

        $this->assertIsBool($response->json('meta.off_queried'));
    }

    public function test_per_page_plafonne_a_50_et_page_respectee(): void
    {
        Food::factory()->count(55)->create(['name' => 'Riz basmati', 'brand' => null]);

        $this->getJson('/api/foods/search?q=riz&per_page=200')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/foods/search?q=riz&per_page=200&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_les_jokers_like_sont_echappes(): void
    {
        Food::factory()->create(['name' => 'Jus 100% pur fruit', 'brand' => null, 'barcode' => null]);
        Food::factory()->create(['name' => 'Jus 100 pur fruit', 'brand' => null, 'barcode' => null]);
        Food::factory()->create(['name' => 'Riz_complet', 'brand' => null, 'barcode' => null]);
        Food::factory()->create(['name' => 'Riza complet', 'brand' => null, 'barcode' => null]);

        $percent = $this->getJson('/api/foods/search?q='.urlencode('100%'));
        $percent->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Jus 100% pur fruit');

        $underscore = $this->getJson('/api/foods/search?q='.urlencode('riz_'));
        $underscore->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Riz_complet');
    }

    public function test_recherche_insensible_a_la_casse_sur_nom_marque_et_code_barres(): void
    {
        Food::factory()->create(['name' => 'Pâtes complètes', 'brand' => 'Barilla', 'barcode' => '8076809513784']);
        Food::factory()->create(['name' => 'Autre', 'brand' => null, 'barcode' => '1111111111111']);

        $this->getJson('/api/foods/search?q=BARILLA')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/foods/search?q=pâtes')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/foods/search?q=80768095')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.barcode', '8076809513784');
    }

    public function test_le_parametre_barcode_filtre_exactement_avec_les_candidats(): void
    {
        Food::factory()->create(['name' => 'UPC', 'barcode' => '0123456789012']);
        Food::factory()->create(['name' => 'Autre', 'barcode' => '9999999999999']);

        $this->getJson('/api/foods/search?barcode=123456789012')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'UPC')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_les_aliments_verifies_sont_en_tete(): void
    {
        $old = Food::factory()->verified()->create(['name' => 'Lentilles vertes', 'updated_at' => now()->subDays(10)]);
        Food::factory()->create(['name' => 'Lentilles corail', 'updated_at' => now()]);

        $response = $this->getJson('/api/foods/search?q=lentilles');

        $response->assertOk()->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.0.is_verified', true);
    }

    public function test_off_est_ignore_pour_un_visiteur_anonyme(): void
    {
        Http::fake();

        $this->getJson('/api/foods/search?q=nutella&off=1')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.off_queried', false);

        Http::assertNothingSent();
    }

    public function test_off_complete_la_recherche_et_upsert_les_fiches_a_code_numerique(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->fakeOffSearch([
            $this->offNutella(),
            $this->offKjOnly('3560070000000'),
            ['code' => 'abc', 'product_name' => 'Nutella sans code', 'nutriments' => ['energy_100g' => 2000]],
        ]);

        $response = $this->getJson('/api/foods/search?q=nutella&off=1');

        $response->assertOk()
            ->assertJsonPath('meta.off_queried', true)
            ->assertJsonPath('meta.total', 1);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/cgi/search.pl')
            && str_contains($request->url(), 'search_terms=nutella')
            && str_contains($request->url(), 'page_size=10'));

        $this->assertDatabaseCount('food', 2);

        $nutella = Food::where('barcode', '3017620422003')->firstOrFail();
        $this->assertSame('Nutella pâte à tartiner', $nutella->name);
        $this->assertSame('Ferrero', $nutella->brand);
        $this->assertSame(539.0, $nutella->calories);
        $this->assertSame('open_food_facts', $nutella->source_type);
        $this->assertNull($nutella->created_by_user_id);
        $this->assertFalse($nutella->is_verified);
        $this->assertNotNull($nutella->source_fetched_at);
        $this->assertNotNull($nutella->off_last_checked_at);
        $this->assertSame(['en:milk', 'en:nuts'], $nutella->allergens);

        $kj = Food::where('barcode', '3560070000000')->firstOrFail();
        $this->assertSame(round(1900 / 4.184, 1), $kj->calories);

        $this->assertNull(Food::where('name', 'Nutella sans code')->first());

        $row = $response->json('data.0');
        $this->assertSame('3017620422003', $row['barcode']);
        $this->assertTrue($row['is_estimate']);
        $this->assertFalse($row['is_owner']);
    }

    public function test_off_non_interroge_si_terme_trop_court(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake();

        $this->getJson('/api/foods/search?q=nu&off=1')->assertOk()->assertJsonPath('meta.off_queried', false);

        Http::assertNothingSent();
    }

    public function test_off_non_interroge_si_au_moins_5_resultats_locaux(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Food::factory()->count(5)->create(['name' => 'Nutella', 'brand' => null]);
        Http::fake();

        $this->getJson('/api/foods/search?q=nutella&off=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.off_queried', false);

        Http::assertNothingSent();
    }

    public function test_off_non_interroge_sans_le_parametre_off(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake();

        $this->getJson('/api/foods/search?q=nutella')->assertOk()->assertJsonPath('meta.off_queried', false);

        Http::assertNothingSent();
    }

    public function test_panne_off_pendant_la_recherche_ne_casse_pas_la_reponse(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Food::factory()->create(['name' => 'Nutella maison', 'brand' => null]);
        $this->fakeOffSearch([], 500);

        $this->getJson('/api/foods/search?q=nutella&off=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.off_queried', false);
    }

    public function test_upsert_off_ne_touche_pas_un_aliment_local_verifie_ou_manuel(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $verified = Food::factory()->verified()->create([
            'name' => 'Nutella vérifié', 'barcode' => '3017620422003', 'calories' => 500, 'brand' => 'Local',
        ]);
        $manual = Food::factory()->create([
            'name' => 'Nutella manuel', 'barcode' => '3560070000000', 'calories' => 400, 'source_type' => 'manual',
        ]);
        Food::factory()->openFoodFacts()->create([
            'name' => 'Nutella OFF ancien', 'barcode' => '4000000000001', 'calories' => 100, 'brand' => null,
        ]);

        $this->fakeOffSearch([
            $this->offNutella(),
            $this->offKjOnly('3560070000000'),
            $this->offNutella(['code' => '4000000000001', 'product_name_fr' => 'Nutella OFF rafraîchi']),
        ]);

        $this->getJson('/api/foods/search?q=nutella&off=1')->assertOk()->assertJsonPath('meta.off_queried', true);

        $this->assertSame(500.0, $verified->fresh()->calories);
        $this->assertSame('Nutella vérifié', $verified->fresh()->name);
        $this->assertSame(400.0, $manual->fresh()->calories);
        $this->assertSame('manual', $manual->fresh()->source_type);

        $refreshed = Food::where('barcode', '4000000000001')->firstOrFail();
        $this->assertSame('Nutella OFF rafraîchi', $refreshed->name);
        $this->assertSame(539.0, $refreshed->calories);
        $this->assertDatabaseCount('food', 3);
    }

    public function test_validation_de_la_recherche_en_francais(): void
    {
        $this->getJson('/api/foods/search?per_page=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/foods/search?page=0')
            ->assertStatus(422)
            ->assertJsonPath('errors.page.0', fn (string $m) => str_contains($m, 'page'));
    }

    public function test_is_favorite_depend_du_lecteur_sans_n_plus_1(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();
        $foods = Food::factory()->count(3)->create(['name' => 'Amandes', 'brand' => null]);
        $viewer->favoriteFoods()->attach($foods[0]->id);
        $other->favoriteFoods()->attach($foods[1]->id);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/foods/search?q=amandes');
        $response->assertOk();

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$foods[0]->id]['is_favorite']);
        $this->assertFalse($byId[$foods[1]->id]['is_favorite']);
        $this->assertFalse($byId[$foods[2]->id]['is_favorite']);
    }
}
