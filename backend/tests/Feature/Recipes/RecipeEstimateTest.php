<?php

namespace Tests\Feature\Recipes;

use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function seedFoods(): void
    {
        Food::factory()->create([
            'name' => 'Blanc de poulet', 'barcode' => '3000000000001',
            'calories' => 100, 'proteins' => 10, 'carbs' => 20, 'fat' => 1, 'serving_size_g' => null, 'category' => null,
        ]);
        Food::factory()->create([
            'name' => 'Riz basmati', 'barcode' => null,
            'calories' => 350, 'proteins' => 7, 'carbs' => 78, 'fat' => 0.6, 'serving_size_g' => null, 'category' => null,
        ]);
        Food::factory()->create([
            'name' => 'Oeuf', 'barcode' => null,
            'calories' => 140, 'proteins' => 12, 'carbs' => 1, 'fat' => 10, 'serving_size_g' => 55, 'category' => null,
        ]);
    }

    public function test_estimation_par_code_barres_puis_nom_avec_conversions_de_portions(): void
    {
        $this->seedFoods();

        $response = $this->postJson('/api/recipes/estimate', [
            'ingredients' => [
                ['name' => 'Peu importe', 'ean' => '3000000000001', 'amount' => 200, 'unit' => 'g'],
                ['name' => 'riz', 'ean' => null, 'amount' => 2, 'unit' => 'cas'],
                ['name' => 'oeuf', 'amount' => 2, 'unit' => 'piece'],
                ['name' => 'Ingrédient inconnu', 'amount' => 100, 'unit' => 'g'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.resolved_count', 3)
            ->assertJsonPath('data.total_count', 4)
            ->assertJsonPath('data.is_estimate', true)
            ->assertJsonPath('data.details', [
                ['name' => 'Peu importe', 'resolved' => true, 'grams' => 200],
                ['name' => 'riz', 'resolved' => true, 'grams' => 30],
                ['name' => 'oeuf', 'resolved' => true, 'grams' => 110],
                ['name' => 'Ingrédient inconnu', 'resolved' => false, 'grams' => null],
            ]);

        // 200 g poulet (200/20/40/2) + 30 g riz (105/2.1/23.4/0.18) + 110 g œuf (154/13.2/1.1/11)
        $this->assertEqualsWithDelta(459.0, $response->json('data.calories'), 0.11);
        $this->assertEqualsWithDelta(35.3, $response->json('data.proteins'), 0.11);
        $this->assertEqualsWithDelta(64.5, $response->json('data.carbs'), 0.11);
        $this->assertEqualsWithDelta(13.2, $response->json('data.fat'), 0.11);
        $this->assertArrayNotHasKey('per_serving', $response->json('data'));
    }

    public function test_le_code_barres_prime_sur_le_nom_et_accepte_les_candidats_12_13(): void
    {
        $this->seedFoods();
        Food::factory()->create(['name' => 'UPC', 'barcode' => '0123456789012', 'calories' => 1000, 'proteins' => 0, 'carbs' => 0, 'fat' => 0]);

        $response = $this->postJson('/api/recipes/estimate', [
            'ingredients' => [
                ['name' => 'riz', 'ean' => '3000000000001', 'amount' => 100, 'unit' => 'g'], // → poulet via ean
                ['name' => 'quelque chose', 'ean' => '123456789012', 'amount' => 50, 'unit' => 'g'], // → UPC via candidat
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.resolved_count', 2);
        $this->assertEqualsWithDelta(100 + 500, $response->json('data.calories'), 0.11);
    }

    public function test_unite_inconnue_ou_quantite_absente_laisse_l_ingredient_non_resolu(): void
    {
        $this->seedFoods();

        $response = $this->postJson('/api/recipes/estimate', [
            'ingredients' => [
                ['name' => 'riz', 'amount' => 1, 'unit' => 'sachet'],
                ['name' => 'riz', 'amount' => null, 'unit' => 'g'],
                ['name' => 'riz', 'amount' => 0, 'unit' => 'g'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.resolved_count', 0)
            ->assertJsonPath('data.total_count', 3)
            ->assertJsonPath('data.calories', 0);
    }

    public function test_les_alias_d_unites_et_le_ml_sont_convertis(): void
    {
        Food::factory()->create(['name' => 'Lait demi-écrémé', 'barcode' => null, 'calories' => 46, 'proteins' => 3.2, 'carbs' => 4.8, 'fat' => 1.6, 'density_g_per_ml' => null]);

        $response = $this->postJson('/api/recipes/estimate', [
            'ingredients' => [
                ['name' => 'lait', 'amount' => 25, 'unit' => 'cl'], // 250 ml ≈ 250 g
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.details.0.grams', 250)
            ->assertJsonPath('data.calories', 115);
    }

    public function test_servings_ajoute_les_valeurs_par_portion(): void
    {
        $this->seedFoods();

        $response = $this->postJson('/api/recipes/estimate', [
            'servings' => 2,
            'ingredients' => [['name' => 'x', 'ean' => '3000000000001', 'amount' => 200, 'unit' => 'g']],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.calories', 200)
            ->assertJsonPath('data.servings', 2)
            ->assertJsonPath('data.per_serving.calories', 100)
            ->assertJsonPath('data.per_serving.proteins', 10);
    }

    public function test_les_lignes_sans_nom_sont_ignorees_du_total(): void
    {
        $this->seedFoods();

        $this->postJson('/api/recipes/estimate', [
            'ingredients' => [
                ['name' => '', 'amount' => 100, 'unit' => 'g'],
                ['name' => 'riz', 'amount' => 100, 'unit' => 'g'],
            ],
        ])->assertOk()->assertJsonPath('data.total_count', 1)->assertJsonPath('data.calories', 350);
    }

    public function test_validation_de_l_estimation(): void
    {
        $response = $this->postJson('/api/recipes/estimate', []);
        $response->assertStatus(422)->assertJsonValidationErrors(['ingredients']);
        $this->assertStringContainsString('ingrédients', $response->json('errors.ingredients.0'));

        $this->postJson('/api/recipes/estimate', ['ingredients' => [['name' => 'riz', 'amount' => 'abc']]])
            ->assertStatus(422)->assertJsonValidationErrors(['ingredients.0.amount']);

        $this->postJson('/api/recipes/estimate', ['ingredients' => [['name' => 'riz']], 'servings' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['servings']);
    }
}
