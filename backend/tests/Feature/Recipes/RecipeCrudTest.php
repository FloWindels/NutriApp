<?php

namespace Tests\Feature\Recipes;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeCrudTest extends TestCase
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

    // ------------------------------------------------------------------ show

    public function test_show_publique_d_un_autre_et_ma_privee_ok(): void
    {
        $public = Recipe::factory()->create(['created_by_user_id' => $this->other->id]);
        $mine = Recipe::factory()->privee()->create(['created_by_user_id' => $this->user->id]);

        $this->getJson('/api/recipes/'.$public->id)->assertOk()->assertJsonPath('data.id', $public->id)->assertJsonPath('data.is_owner', false);
        $this->getJson('/api/recipes/'.$mine->id)->assertOk()->assertJsonPath('data.id', $mine->id)->assertJsonPath('data.is_owner', true);
    }

    public function test_show_privee_d_un_autre_ou_inconnue_donne_404(): void
    {
        $private = Recipe::factory()->privee()->create(['created_by_user_id' => $this->other->id]);

        $this->getJson('/api/recipes/'.$private->id)->assertStatus(404)->assertExactJson(['message' => 'Introuvable.']);
        $this->getJson('/api/recipes/999999')->assertStatus(404)->assertExactJson(['message' => 'Introuvable.']);
    }

    // ------------------------------------------------------------------ store

    public function test_creation_avec_les_nouveaux_champs_et_par_portion(): void
    {
        $response = $this->postJson('/api/recipes', [
            'title' => 'Poulet riz brocoli',
            'description' => 'Un classique',
            'prep_time_minutes' => 25,
            'calories' => 800,
            'proteins' => 40,
            'carbs' => 100,
            'fat' => 20,
            'servings' => 4,
            // (les flottants entiers sont sérialisés sans décimale : les types sont vérifiés dans le test de contrat)
            'tags' => ['riche_en_proteines', 'rapide'],
            'meal_types' => ['dejeuner', 'diner'],
            'ingredients' => [
                ['name' => 'Poulet', 'ean' => '', 'amount' => '400', 'unit' => ' g '],
                ['name' => '', 'amount' => 10, 'unit' => 'g'],
                ['name' => ' Riz ', 'ean' => '3000000000001', 'amount' => null, 'unit' => ''],
            ],
            'is_public' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Recette enregistrée en privé.')
            ->assertJsonPath('data.title', 'Poulet riz brocoli')
            ->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.servings', 4)
            ->assertJsonPath('data.proteins', 40)
            ->assertJsonPath('data.tags', ['riche_en_proteines', 'rapide'])
            ->assertJsonPath('data.meal_types', ['dejeuner', 'diner'])
            ->assertJsonPath('data.per_serving', ['calories' => 200, 'proteins' => 10, 'carbs' => 25, 'fat' => 5])
            ->assertJsonPath('data.has_macros', true)
            ->assertJsonPath('data.is_estimate', false)
            ->assertJsonPath('data.ingredients_count', 2)
            ->assertJsonPath('data.ingredients', [
                ['name' => 'Poulet', 'ean' => null, 'amount' => 400, 'unit' => 'g'],
                ['name' => 'Riz', 'ean' => '3000000000001', 'amount' => null, 'unit' => null],
            ]);

        $this->assertSame(4.0, (float) $response->json('data.servings'));
        $this->assertSame(400.0, (float) $response->json('data.ingredients.0.amount'));
    }

    public function test_creation_sans_macros_ni_portions_garde_les_valeurs_legacy(): void
    {
        $response = $this->postJson('/api/recipes', ['title' => 'Simple', 'calories' => 500]);

        $response->assertCreated()
            ->assertJsonPath('data.servings', 1)
            ->assertJsonPath('data.proteins', null)
            ->assertJsonPath('data.tags', [])
            ->assertJsonPath('data.meal_types', [])
            ->assertJsonPath('data.per_serving', ['calories' => 500, 'proteins' => null, 'carbs' => null, 'fat' => null])
            ->assertJsonPath('data.has_macros', false)
            ->assertJsonPath('data.ingredients', [])
            ->assertJsonPath('data.ingredients_count', 0);
    }

    public function test_is_estimate_accepte_quand_les_macros_viennent_de_l_estimation(): void
    {
        $this->postJson('/api/recipes', ['title' => 'Estimée', 'calories' => 300, 'proteins' => 10, 'carbs' => 30, 'fat' => 5, 'is_estimate' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_estimate', true);
    }

    public function test_validation_des_nouveaux_champs(): void
    {
        $this->postJson('/api/recipes', ['title' => 'X', 'calories' => 1, 'tags' => ['inconnu']])
            ->assertStatus(422)->assertJsonValidationErrors(['tags.0']);

        $this->postJson('/api/recipes', ['title' => 'X', 'calories' => 1, 'tags' => ['vegan', 'vegan']])
            ->assertStatus(422)->assertJsonValidationErrors(['tags.0']);

        $this->postJson('/api/recipes', ['title' => 'X', 'calories' => 1, 'meal_types' => ['brunch']])
            ->assertStatus(422)->assertJsonValidationErrors(['meal_types.0']);

        $this->postJson('/api/recipes', ['title' => 'X', 'calories' => 1, 'servings' => 0.2])
            ->assertStatus(422)->assertJsonValidationErrors(['servings']);

        $this->postJson('/api/recipes', ['title' => 'X', 'calories' => 1, 'proteins' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['proteins']);
    }

    public function test_calories_et_titre_obligatoires_en_francais(): void
    {
        $response = $this->postJson('/api/recipes', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['title', 'calories']);
        $this->assertStringContainsString('titre', $response->json('errors.title.0'));
        $this->assertStringContainsString('calories', $response->json('errors.calories.0'));
    }

    public function test_regles_de_publication_legacy_conservees(): void
    {
        $response = $this->postJson('/api/recipes', ['title' => 'Publique', 'calories' => 300, 'is_public' => true]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.image_url.0', "Une photo de l'assiette est requise pour publier une recette publique.")
            ->assertJsonPath('errors.prep_time_minutes.0', 'Le temps de preparation est requis pour publier une recette publique.')
            ->assertJsonPath('errors.ingredients.0', 'Une recette publique doit contenir au moins un aliment.');

        $this->postJson('/api/recipes', [
            'title' => 'Publique', 'calories' => 300, 'is_public' => true, 'image_url' => 'pas une url',
            'prep_time_minutes' => 10, 'ingredients' => [['name' => 'Riz']],
        ])->assertStatus(422)
            ->assertJsonPath('errors.image_url.0', 'La photo doit etre une image importee ou une URL valide.');

        $this->postJson('/api/recipes', [
            'title' => 'Publique', 'calories' => 300, 'is_public' => true, 'image_url' => 'data:image/png;base64,AAAA',
            'prep_time_minutes' => 10, 'ingredients' => [['name' => 'Riz']],
        ])->assertCreated()->assertJsonPath('message', 'Recette publiée.')->assertJsonPath('data.is_public', true);
    }

    // ------------------------------------------------------------------ update / destroy

    public function test_modification_par_le_createur(): void
    {
        $recipe = Recipe::factory()->privee()->create(['created_by_user_id' => $this->user->id, 'servings' => 2, 'tags' => ['rapide']]);

        $response = $this->putJson('/api/recipes/'.$recipe->id, [
            'title' => 'Nouveau titre',
            'calories' => 600,
            'servings' => 3,
            'proteins' => 30,
            'carbs' => 60,
            'fat' => 12,
            'tags' => ['keto'],
            'meal_types' => ['diner'],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Recette privée mise à jour.')
            ->assertJsonPath('data.title', 'Nouveau titre')
            ->assertJsonPath('data.servings', 3)
            ->assertJsonPath('data.tags', ['keto'])
            ->assertJsonPath('data.meal_types', ['diner'])
            ->assertJsonPath('data.per_serving.calories', 200);

        $this->assertSame(3.0, $recipe->fresh()->servings);
    }

    public function test_modification_legacy_sans_nouveaux_champs_les_conserve(): void
    {
        $recipe = Recipe::factory()->create(['created_by_user_id' => $this->user->id, 'servings' => 4, 'tags' => ['vegan'], 'proteins' => 12, 'is_public' => false]);

        $this->putJson('/api/recipes/'.$recipe->id, ['title' => 'Legacy', 'calories' => 100])
            ->assertOk()
            ->assertJsonPath('data.servings', 4)
            ->assertJsonPath('data.tags', ['vegan'])
            ->assertJsonPath('data.proteins', 12);
    }

    public function test_modification_par_un_autre_donne_403_legacy_avant_validation(): void
    {
        $recipe = Recipe::factory()->create(['created_by_user_id' => $this->other->id]);

        $this->putJson('/api/recipes/'.$recipe->id, [])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Seul le createur peut modifier cette recette.']);
    }

    public function test_suppression_par_un_autre_403_legacy_et_par_le_createur_ok(): void
    {
        $recipe = Recipe::factory()->create(['created_by_user_id' => $this->other->id]);

        $this->deleteJson('/api/recipes/'.$recipe->id)
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Seul le createur peut supprimer cette recette.']);

        $mine = Recipe::factory()->create(['created_by_user_id' => $this->user->id]);
        $this->deleteJson('/api/recipes/'.$mine->id)->assertOk()->assertExactJson(['message' => 'Recette supprimée.']);
        $this->assertDatabaseMissing('recipes', ['id' => $mine->id]);
    }

    public function test_modification_ou_suppression_d_un_id_inconnu_donne_404(): void
    {
        $this->putJson('/api/recipes/999999', ['title' => 'X', 'calories' => 1])->assertStatus(404);
        $this->deleteJson('/api/recipes/999999')->assertStatus(404);
    }
}
