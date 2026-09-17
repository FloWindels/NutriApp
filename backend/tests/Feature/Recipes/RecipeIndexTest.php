<?php

namespace Tests\Feature\Recipes;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->other = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_liste_publiques_et_les_miennes_avec_meta_par_defaut_100(): void
    {
        $minePrivate = Recipe::factory()->privee()->create(['created_by_user_id' => $this->user->id, 'title' => 'Ma privée']);
        $minePublic = Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'title' => 'Ma publique']);
        $otherPublic = Recipe::factory()->create(['created_by_user_id' => $this->other->id, 'title' => 'Sa publique']);
        Recipe::factory()->privee()->create(['created_by_user_id' => $this->other->id, 'title' => 'Sa privée']);

        $response = $this->getJson('/api/recipes');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 3);

        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$minePrivate->id, $minePublic->id, $otherPublic->id], $ids);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$minePublic->id]['is_owner']);
        $this->assertFalse($byId[$otherPublic->id]['is_owner']);
    }

    public function test_filtre_mine(): void
    {
        Recipe::factory()->create(['created_by_user_id' => $this->user->id]);
        Recipe::factory()->privee()->create(['created_by_user_id' => $this->user->id]);
        Recipe::factory()->create(['created_by_user_id' => $this->other->id]);

        $response = $this->getJson('/api/recipes?mine=1');

        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        foreach ($response->json('data') as $row) {
            $this->assertTrue($row['is_owner']);
        }
    }

    public function test_filtre_q_sur_le_titre_avec_echappement(): void
    {
        Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'title' => 'Poulet 100% maison', 'description' => null]);
        Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'title' => 'Poulet 100 maison', 'description' => null]);
        Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'title' => 'Salade', 'description' => 'Avec du poulet froid']);

        $this->getJson('/api/recipes?q=POULET')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/recipes?q='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Poulet 100% maison');
        $this->getJson('/api/recipes?q=salade')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filtre_tag_via_json(): void
    {
        $vegan = Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'tags' => ['vegan', 'rapide']]);
        Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'tags' => ['rapide']]);
        Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'tags' => []]);

        $response = $this->getJson('/api/recipes?tag=vegan');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $vegan->id);
        $this->getJson('/api/recipes?tag=rapide')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filtre_meal_type(): void
    {
        $breakfast = Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'meal_types' => ['petit_dejeuner']]);
        Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'meal_types' => ['dejeuner', 'diner']]);

        $this->getJson('/api/recipes?meal_type=petit_dejeuner')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $breakfast->id);
    }

    public function test_filtre_max_calories_s_applique_par_portion(): void
    {
        $light = Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'calories' => 1200, 'servings' => 4]); // 300 / portion
        Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'calories' => 900, 'servings' => 1]); // 900 / portion

        $this->getJson('/api/recipes?max_calories=350')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $light->id)
            ->assertJsonPath('data.0.per_serving.calories', 300);

        $this->getJson('/api/recipes?max_calories=250')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pagination_page_et_per_page(): void
    {
        Recipe::factory()->count(5)->create(['created_by_user_id' => $this->user->id]);

        $this->getJson('/api/recipes?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJson(['meta' => ['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5]]);

        $this->getJson('/api/recipes?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);

        $this->getJson('/api/recipes?per_page=1000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_tri_par_mise_a_jour_decroissante(): void
    {
        $old = Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'updated_at' => now()->subDay()]);
        $new = Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'updated_at' => now()]);

        $response = $this->getJson('/api/recipes');

        $this->assertSame([$new->id, $old->id], array_column($response->json('data'), 'id'));
    }

    public function test_filtres_invalides_422_en_francais(): void
    {
        $this->getJson('/api/recipes?tag=inconnu')->assertStatus(422)->assertJsonValidationErrors(['tag']);
        $this->getJson('/api/recipes?meal_type=brunch')->assertStatus(422)->assertJsonValidationErrors(['meal_type']);

        $response = $this->getJson('/api/recipes?max_calories=abc');
        $response->assertStatus(422)->assertJsonValidationErrors(['max_calories']);
        $this->assertStringContainsString('calories maximales par portion', $response->json('errors.max_calories.0'));
    }
}
