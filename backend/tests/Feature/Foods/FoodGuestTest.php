<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Routes du module M3 exigeant un jeton : 401 « Non authentifié. » sans Bearer.
 */
class FoodGuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_routes_authentifiees_repondent_401(): void
    {
        $food = Food::factory()->create();
        $recipe = Recipe::factory()->create();

        $expected = ['message' => 'Non authentifié.'];

        $this->getJson('/api/foods/'.$food->id)->assertStatus(401)->assertExactJson($expected);
        $this->postJson('/api/foods', ['name' => 'X'])->assertStatus(401)->assertExactJson($expected);
        $this->putJson('/api/foods/'.$food->id, ['name' => 'X'])->assertStatus(401);
        $this->getJson('/api/foods/favorites')->assertStatus(401);
        $this->postJson('/api/foods/'.$food->id.'/favorite')->assertStatus(401);
        $this->deleteJson('/api/foods/'.$food->id.'/favorite')->assertStatus(401);

        $this->getJson('/api/recipes')->assertStatus(401);
        $this->getJson('/api/recipes/'.$recipe->id)->assertStatus(401);
        $this->postJson('/api/recipes/estimate', ['ingredients' => []])->assertStatus(401);
        $this->postJson('/api/recipes', ['title' => 'X', 'calories' => 1])->assertStatus(401);
    }

    public function test_les_catalogues_exigent_un_jeton(): void
    {
        Http::fake();
        Food::factory()->create(['name' => 'Pomme', 'barcode' => '3000000000001']);

        $expected = ['message' => 'Non authentifié.'];

        foreach (['/api', '/api/v1'] as $prefix) {
            $this->getJson($prefix.'/foods/search?q=pomme&off=1')->assertStatus(401)->assertExactJson($expected);
            $this->getJson($prefix.'/foods/barcode/3000000000001')->assertStatus(401)->assertExactJson($expected);
            $this->getJson($prefix.'/portions')->assertStatus(401)->assertExactJson($expected);
        }

        // Aucun appel à Open Food Facts pour un visiteur anonyme.
        Http::assertNothingSent();
    }

    public function test_les_catalogues_restent_accessibles_avec_un_jeton(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Food::factory()->create(['name' => 'Pomme', 'barcode' => '3000000000001']);

        $this->getJson('/api/foods/search?q=pomme')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/foods/barcode/3000000000001')->assertOk()->assertJsonPath('data.is_owner', false);
        $this->getJson('/api/v1/foods/search?q=pomme')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/portions')->assertOk();
    }
}
