<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodStoreUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_creation_d_un_aliment_manuel(): void
    {
        $response = $this->postJson('/api/foods', [
            'barcode' => '3017620422003',
            'name' => 'Pâte à tartiner',
            'brand' => 'Ferrero',
            'calories' => 539.5,
            'fat' => 30.9,
            'carbs' => 57.5,
            'proteins' => 6.3,
            'fiber' => 0,
            'sugar' => 56.3,
            'salt' => 0.107,
            'serving_size_g' => 15,
            'serving_label' => '1 cuillère',
            'category' => 'spreads',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Produit ajouté à la base publique.')
            ->assertJsonPath('data.barcode', '3017620422003')
            ->assertJsonPath('data.source_type', 'manual')
            ->assertJsonPath('data.created_by_user_id', $this->user->id)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.is_estimate', false)
            ->assertJsonPath('data.is_favorite', false)
            ->assertJsonPath('data.serving_size_g', 15)
            ->assertJsonPath('data.per_unit', '100g');

        $this->assertIsFloat($response->json('data.calories'));
        $this->assertSame(539.5, $response->json('data.calories'));
        $this->assertDatabaseHas('food', ['barcode' => '3017620422003', 'created_by_user_id' => $this->user->id]);
    }

    public function test_code_barres_deja_present_renvoie_200_et_l_existant(): void
    {
        $existing = Food::factory()->create(['barcode' => '3017620422003', 'name' => 'Existant']);

        $response = $this->postJson('/api/foods', [
            'barcode' => '3017620422003',
            'name' => 'Doublon',
            'calories' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Produit déjà présent dans la base.')
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.name', 'Existant');

        $this->assertDatabaseCount('food', 1);
    }

    public function test_code_barres_facultatif(): void
    {
        $response = $this->postJson('/api/foods', ['name' => 'Soupe maison', 'calories' => 45]);

        $response->assertCreated()->assertJsonPath('data.barcode', null);

        $this->postJson('/api/foods', ['name' => 'Autre soupe maison', 'calories' => 50])->assertCreated();
        $this->assertDatabaseCount('food', 2);
    }

    public function test_code_barres_invalide_refuse_en_francais(): void
    {
        $this->postJson('/api/foods', ['barcode' => '12AB', 'name' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('errors.barcode.0', 'Le code-barres doit contenir entre 8 et 14 chiffres.');

        $this->postJson('/api/foods', ['barcode' => '1234567', 'name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barcode']);

        $this->postJson('/api/foods', ['barcode' => '123456789012345', 'name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barcode']);
    }

    public function test_nom_obligatoire_message_francais(): void
    {
        $response = $this->postJson('/api/foods', ['calories' => 10]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->assertStringContainsString('obligatoire', $response->json('errors.name.0'));
    }

    public function test_source_type_open_food_facts_accepte_par_les_clients_legacy(): void
    {
        $response = $this->postJson('/api/foods', [
            'barcode' => '3017620422003',
            'name' => 'Nutella',
            'calories' => 539,
            'source_type' => 'open_food_facts',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.source_type', 'open_food_facts')
            ->assertJsonPath('data.is_estimate', true);

        $this->assertNotNull($response->json('data.source_fetched_at'));
        $this->assertNotNull(Food::first()->off_last_checked_at);
    }

    public function test_source_type_invalide_refuse(): void
    {
        $this->postJson('/api/foods', ['name' => 'X', 'source_type' => 'recipe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source_type']);
    }

    public function test_le_createur_peut_modifier_son_aliment_qui_devient_verifie(): void
    {
        $food = Food::factory()->create(['created_by_user_id' => $this->user->id, 'barcode' => '3017620422003']);

        $response = $this->putJson('/api/foods/'.$food->id, [
            'barcode' => '3017620422003',
            'name' => 'Nom corrigé',
            'calories' => 120,
            'fat' => 1,
            'carbs' => 2,
            'proteins' => 3,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Produit mis à jour.')
            ->assertJsonPath('data.name', 'Nom corrigé')
            ->assertJsonPath('data.source_type', 'manual')
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.calories', 120);
    }

    public function test_une_fiche_off_jamais_reprise_peut_etre_corrigee_par_tout_utilisateur(): void
    {
        $food = Food::factory()->openFoodFacts()->create(['barcode' => '3017620422003', 'created_by_user_id' => null]);

        $response = $this->putJson('/api/foods/'.$food->id, [
            'barcode' => '3017620422003',
            'name' => 'Corrigé par la communauté',
            'calories' => 500,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.source_type', 'manual')
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.is_estimate', false)
            ->assertJsonPath('data.created_by_user_id', $this->user->id)
            ->assertJsonPath('data.is_owner', true);
    }

    public function test_modifier_l_aliment_manuel_d_un_autre_donne_403_legacy(): void
    {
        $other = User::factory()->create();
        $food = Food::factory()->create(['created_by_user_id' => $other->id]);

        $this->putJson('/api/foods/'.$food->id, ['name' => 'Pirate', 'barcode' => $food->barcode])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Seul le createur peut modifier cet aliment.']);

        $this->assertNotSame('Pirate', $food->fresh()->name);
    }

    public function test_une_fiche_off_deja_attribuee_ne_peut_pas_etre_modifiee_par_un_autre(): void
    {
        $other = User::factory()->create();
        $food = Food::factory()->create(['source_type' => 'open_food_facts', 'created_by_user_id' => $other->id]);

        $this->putJson('/api/foods/'.$food->id, ['name' => 'Pirate', 'barcode' => $food->barcode])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Seul le createur peut modifier cet aliment.']);
    }

    public function test_le_403_precede_la_validation(): void
    {
        $other = User::factory()->create();
        $food = Food::factory()->create(['created_by_user_id' => $other->id]);

        $this->putJson('/api/foods/'.$food->id, [])->assertStatus(403);
    }

    public function test_validation_de_la_modification(): void
    {
        $food = Food::factory()->create(['created_by_user_id' => $this->user->id]);
        Food::factory()->create(['barcode' => '9999999999999']);

        $this->putJson('/api/foods/'.$food->id, ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->putJson('/api/foods/'.$food->id, ['name' => 'Ok', 'barcode' => '9999999999999'])
            ->assertStatus(422)
            ->assertJsonPath('errors.barcode.0', 'Ce code-barres est déjà utilisé par un autre aliment.');
    }

    public function test_modification_d_un_id_inconnu_donne_404(): void
    {
        $this->putJson('/api/foods/999999', ['name' => 'X'])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Introuvable.']);
    }

    public function test_show_renvoie_l_aliment_ou_404(): void
    {
        $food = Food::factory()->create(['created_by_user_id' => $this->user->id]);

        $this->getJson('/api/foods/'.$food->id)
            ->assertOk()
            ->assertJsonPath('data.id', $food->id)
            ->assertJsonPath('data.is_owner', true);

        $this->getJson('/api/foods/999999')->assertStatus(404)->assertExactJson(['message' => 'Introuvable.']);
    }
}
