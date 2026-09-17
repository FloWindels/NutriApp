<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodFavoritesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_ajout_aux_favoris_201_puis_200_idempotent(): void
    {
        $food = Food::factory()->create();

        $this->postJson('/api/foods/'.$food->id.'/favorite')
            ->assertCreated()
            ->assertJsonPath('message', 'Ajouté aux favoris.')
            ->assertJsonPath('data.id', $food->id)
            ->assertJsonPath('data.is_favorite', true);

        $this->postJson('/api/foods/'.$food->id.'/favorite')
            ->assertOk()
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseCount('food_favorites', 1);
        $this->assertDatabaseHas('food_favorites', ['user_id' => $this->user->id, 'food_id' => $food->id]);
    }

    public function test_liste_des_favoris_du_lecteur_uniquement_plus_recents_d_abord(): void
    {
        $other = User::factory()->create();
        [$a, $b, $c] = Food::factory()->count(3)->create();

        $this->user->favoriteFoods()->attach($a->id, ['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->user->favoriteFoods()->attach($b->id, ['created_at' => now(), 'updated_at' => now()]);
        $other->favoriteFoods()->attach($c->id);

        $response = $this->getJson('/api/foods/favorites');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$b->id, $a->id], array_column($response->json('data'), 'id'));
        $this->assertSame([true, true], array_column($response->json('data'), 'is_favorite'));
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_retrait_des_favoris(): void
    {
        $food = Food::factory()->create();
        $this->user->favoriteFoods()->attach($food->id);

        $this->deleteJson('/api/foods/'.$food->id.'/favorite')
            ->assertOk()
            ->assertExactJson(['message' => 'Retiré des favoris.']);

        $this->assertDatabaseCount('food_favorites', 0);
        $this->getJson('/api/foods/favorites')->assertOk()->assertJsonCount(0, 'data');

        // Idempotent.
        $this->deleteJson('/api/foods/'.$food->id.'/favorite')->assertOk();
    }

    public function test_favori_sur_un_aliment_inconnu_donne_404(): void
    {
        $this->postJson('/api/foods/999999/favorite')->assertStatus(404)->assertExactJson(['message' => 'Introuvable.']);
        $this->deleteJson('/api/foods/999999/favorite')->assertStatus(404);
    }

    public function test_la_route_favorites_n_est_pas_capturee_par_show(): void
    {
        $this->getJson('/api/foods/favorites')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_les_favoris_sont_aussi_servis_sous_v1(): void
    {
        $food = Food::factory()->create();

        $this->postJson('/api/v1/foods/'.$food->id.'/favorite')->assertCreated();
        $this->getJson('/api/v1/foods/favorites')->assertOk()->assertJsonCount(1, 'data');
    }
}
