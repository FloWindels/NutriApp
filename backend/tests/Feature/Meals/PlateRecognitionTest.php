<?php

namespace Tests\Feature\Meals;

use App\Contracts\LlmVisionClient;
use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePlateVisionClient;
use Tests\TestCase;

/**
 * Reconnaissance de photo d'assiette : l'analyse propose, l'utilisateur dispose.
 * La règle la plus importante est qu'aucune écriture n'a lieu avant confirmation.
 */
class PlateRecognitionTest extends TestCase
{
    use RefreshDatabase;

    /** Un pixel JPEG minimal, suffisant pour franchir la validation. */
    private function photo(int $octets = 64): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(str_repeat('a', $octets));
    }

    private function actingAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_analyse_ne_cree_aucun_repas(): void
    {
        $this->actingAsUser();
        $this->app->instance(LlmVisionClient::class, new FakePlateVisionClient);

        $this->postJson('/api/meals/analyze-photo', ['image' => $this->photo()])
            ->assertOk()
            ->assertJsonPath('data.source', 'ia');

        $this->assertDatabaseCount('meals', 0);
        $this->assertDatabaseCount('meal_items', 0);
    }

    public function test_une_ligne_reconnue_est_rattachee_au_catalogue(): void
    {
        $this->actingAsUser();
        $food = Food::factory()->create(['name' => 'Riz blanc cuit', 'calories' => 130, 'is_verified' => true]);
        $this->app->instance(LlmVisionClient::class, new FakePlateVisionClient);

        $data = $this->postJson('/api/meals/analyze-photo', ['image' => $this->photo()])
            ->assertOk()
            ->json('data');

        $this->assertSame($food->id, $data['aliments'][0]['food']['id']);
        // Les macros retenues viennent de la fiche, pas du modèle (qui annonçait 195 kcal).
        $this->assertSame(130.0, (float) $data['aliments'][0]['food']['calories']);
        $this->assertNull($data['aliments'][0]['valeurs_proposees']);
    }

    public function test_une_ligne_inconnue_garde_les_valeurs_proposees(): void
    {
        $this->actingAsUser();
        Http::fake(['*' => Http::response(['products' => []], 200)]);
        $this->app->instance(LlmVisionClient::class, FakePlateVisionClient::canned([
            'aliments' => [[
                'nom' => 'Préparation maison introuvable',
                'marque' => null, 'quantite' => 200, 'unite' => 'g', 'confiance' => 0.4,
                'calories' => 320, 'proteines' => 12, 'glucides' => 30, 'lipides' => 14,
            ]],
            'description' => '', 'confiance_globale' => 0.4, 'avertissements' => [],
        ]));

        $ligne = $this->postJson('/api/meals/analyze-photo', ['image' => $this->photo()])
            ->assertOk()
            ->json('data.aliments.0');

        $this->assertNull($ligne['food']);
        $this->assertSame(320.0, (float) $ligne['valeurs_proposees']['calories']);
    }

    public function test_reconnaissance_indisponible_repond_200_avec_une_liste_vide(): void
    {
        $this->actingAsUser();
        $this->app->instance(LlmVisionClient::class, FakePlateVisionClient::throwing());

        $this->postJson('/api/meals/analyze-photo', ['image' => $this->photo()])
            ->assertOk()
            ->assertJsonPath('data.source', 'indisponible')
            ->assertJsonPath('data.aliments', []);
    }

    public function test_une_photo_sans_aliment_identifiable_bascule_sur_la_saisie_manuelle(): void
    {
        $this->actingAsUser();
        $this->app->instance(LlmVisionClient::class, FakePlateVisionClient::canned([
            'aliments' => [], 'description' => '', 'confiance_globale' => 0.0,
            'avertissements' => ['Aucun aliment visible.'],
        ]));

        $this->postJson('/api/meals/analyze-photo', ['image' => $this->photo()])
            ->assertOk()
            ->assertJsonPath('data.source', 'indisponible');
    }

    public function test_une_photo_trop_lourde_est_refusee_sans_appeler_le_modele(): void
    {
        $this->actingAsUser();
        $fake = new FakePlateVisionClient;
        $this->app->instance(LlmVisionClient::class, $fake);

        $this->postJson('/api/meals/analyze-photo', ['image' => $this->photo(600 * 1024)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertSame(0, $fake->calls);
    }

    public function test_un_format_non_pris_en_charge_est_refuse(): void
    {
        $this->actingAsUser();
        $this->app->instance(LlmVisionClient::class, new FakePlateVisionClient);

        $this->postJson('/api/meals/analyze-photo', ['image' => 'data:application/pdf;base64,QQ=='])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->postJson('/api/meals/analyze-photo', ['image' => 'pas-du-tout-une-image'])
            ->assertStatus(422);
    }

    public function test_le_contexte_envoye_au_modele_est_anonyme(): void
    {
        $user = $this->actingAsUser();
        $user->profile()->create([
            'nom' => 'Jean Dupont', 'sexe' => 'homme', 'age' => 30, 'taille' => 175, 'poids' => 70,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'vegetarien',
        ]);
        $fake = new FakePlateVisionClient;
        $this->app->instance(LlmVisionClient::class, $fake);

        $this->postJson('/api/meals/analyze-photo', ['image' => $this->photo()])->assertOk();

        $this->assertSame('vegetarien', $fake->lastContext['regime_alimentaire']);
        $serialise = json_encode($fake->lastContext);
        $this->assertStringNotContainsString('Jean', $serialise);
        $this->assertStringNotContainsString($user->email, $serialise);
    }

    public function test_la_capacite_est_annoncee_a_l_interface(): void
    {
        $this->actingAsUser();

        config(['services.llm.provider' => 'none']);
        $this->getJson('/api/meals/photo-capability')
            ->assertOk()
            ->assertJsonPath('data.disponible', false)
            ->assertJsonPath('data.llm_model', null);

        config([
            'services.llm.provider' => 'ollama',
            'services.ollama.enabled' => true,
            'services.ollama.base_url' => 'http://127.0.0.1:11434',
            'services.ollama.vision_model' => 'llava',
        ]);
        $this->getJson('/api/meals/photo-capability')
            ->assertOk()
            ->assertJsonPath('data.disponible', true)
            ->assertJsonPath('data.llm_model', 'llava');
    }
}
