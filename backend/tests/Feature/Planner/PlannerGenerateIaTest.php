<?php

namespace Tests\Feature\Planner;

use App\Contracts\LlmPlannerClient;
use App\Models\MealPlan;
use App\Models\User;
use App\Services\Planner\PlannerAiComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\FakeLlmPlannerClient;
use Tests\TestCase;

/**
 * « Organise ma semaine comme ceci » : la demande en français passée à POST /planner/generate.
 *
 * Ce qui se joue ici tient en deux garanties. Sans demande, la génération est exactement celle
 * d'avant — l'application mobile en dépend. Avec une demande, le modèle ne fait que choisir
 * parmi des recettes que le serveur a déjà validées : un identifiant inventé ou une recette
 * interdite par le profil ne peut pas se retrouver au plan, et le repli par règles est toujours
 * annoncé honnêtement.
 */
class PlannerGenerateIaTest extends TestCase
{
    use ModuleM7Helpers;
    use RefreshDatabase;

    private function fake(FakeLlmPlannerClient $client): FakeLlmPlannerClient
    {
        $this->app->instance(LlmPlannerClient::class, $client);

        return $client;
    }

    /** Un modèle est branché : le sélecteur de fournisseur doit se déclarer configuré. */
    private function avecIa(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        config()->set('services.llm.provider', 'auto');
    }

    private function sansIa(): void
    {
        config()->set('services.llm.provider', 'none');
    }

    private function weekPlans(User $user): Collection
    {
        return MealPlan::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$this->weekDay($user, 0), $this->weekDay($user, 6)])
            ->orderBy('date')
            ->orderBy('meal_type')
            ->get();
    }

    public function test_sans_demande_la_generation_ne_change_pas_et_le_modele_n_est_pas_sollicite(): void
    {
        $this->avecIa();
        $client = $this->fake(FakeLlmPlannerClient::canned());
        $user = $this->login($this->userWithProfile());

        $proche = $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);
        $this->publicRecipe(['title' => 'Assiette copieuse', 'calories' => 760, 'meal_types' => ['dejeuner']]);

        $reponse = $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk();

        $reponse->assertJsonPath('generated_count', 7);
        $reponse->assertJsonPath('generated_by', 'regles');
        $reponse->assertJsonPath('warnings', []);
        $reponse->assertJsonPath('explication', []);

        $this->assertSame(0, $client->calls, 'Sans demande, aucun appel au modèle.');

        // Les règles choisissent la plus proche du budget (700 kcal au déjeuner).
        $this->assertSame($proche->id, $this->weekPlans($user)->first()->recipe_id);
    }

    public function test_une_demande_vide_reste_une_generation_par_les_regles(): void
    {
        $this->avecIa();
        $client = $this->fake(FakeLlmPlannerClient::canned());
        $this->login($this->userWithProfile());
        $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'demande' => '   '])
            ->assertOk()
            ->assertJsonPath('generated_by', 'regles')
            ->assertJsonPath('warnings', []);

        $this->assertSame(0, $client->calls);
    }

    public function test_avec_une_demande_le_modele_compose_la_semaine_dans_l_ensemble_autorise(): void
    {
        $this->avecIa();
        $user = $this->login($this->userWithProfile());

        $proche = $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);
        $rapide = $this->publicRecipe(['title' => 'Assiette express', 'calories' => 760, 'meal_types' => ['dejeuner']]);

        $creneaux = [];
        for ($i = 0; $i < 7; $i++) {
            $creneaux[] = ['date' => $this->weekDay($user, $i), 'repas' => 'dejeuner', 'recette_id' => $rapide->id];
        }

        $client = $this->fake(FakeLlmPlannerClient::canned([
            'creneaux' => $creneaux,
            'explication' => ['Des plats rapides, comme demandé.'],
        ]));

        $reponse = $this->postJson('/api/planner/generate', [
            'meal_types' => ['dejeuner'],
            'demande' => 'le matin je n’ai pas le temps de cuisiner',
        ])->assertOk();

        $reponse->assertJsonPath('generated_by', 'ia');
        $reponse->assertJsonPath('generated_count', 7);
        $reponse->assertJsonPath('explication', ['Des plats rapides, comme demandé.']);
        $reponse->assertJsonPath('warnings', []);

        $this->assertSame(1, $client->calls);
        $this->assertSame('le matin je n’ai pas le temps de cuisiner', $client->lastDemande);

        // Le choix du modèle l'emporte sur la préférence des règles (qui auraient pris $proche).
        $plans = $this->weekPlans($user);
        $this->assertSame([$rapide->id], $plans->pluck('recipe_id')->unique()->values()->all());
        $this->assertNotContains($proche->id, $plans->pluck('recipe_id')->all());

        // Le contexte envoyé décrit les créneaux libres et les recettes permises, rien de plus.
        $this->assertCount(7, $client->lastContext['creneaux']);
        $this->assertEqualsCanonicalizing(
            [$proche->id, $rapide->id],
            array_column($client->lastContext['recettes_disponibles'], 'id'),
        );
    }

    public function test_sans_ia_configuree_la_demande_n_est_pas_interpretee_et_les_regles_reprennent(): void
    {
        $this->sansIa();
        $client = $this->fake(FakeLlmPlannerClient::canned());
        $user = $this->login($this->userWithProfile());

        $proche = $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);

        $reponse = $this->postJson('/api/planner/generate', [
            'meal_types' => ['dejeuner'],
            'demande' => 'des plats à préparer d’avance le dimanche',
        ])->assertOk();

        $reponse->assertJsonPath('generated_by', 'regles');
        $reponse->assertJsonPath('generated_count', 7);
        $reponse->assertJsonPath('warnings', [PlannerAiComposer::WARNING_SANS_IA]);

        $this->assertSame(0, $client->calls, 'Sans modèle branché, on ne l’appelle pas.');
        $this->assertSame($proche->id, $this->weekPlans($user)->first()->recipe_id);
    }

    public function test_une_panne_du_modele_bascule_sur_les_regles_et_le_dit(): void
    {
        $this->avecIa();
        $client = $this->fake(FakeLlmPlannerClient::throwing());
        $user = $this->login($this->userWithProfile());

        $proche = $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);

        $reponse = $this->postJson('/api/planner/generate', [
            'meal_types' => ['dejeuner'],
            'demande' => 'quelque chose de léger le soir',
        ])->assertOk();

        $reponse->assertJsonPath('generated_by', 'regles');
        $reponse->assertJsonPath('generated_count', 7);
        $reponse->assertJsonPath('warnings', [PlannerAiComposer::WARNING_FALLBACK]);

        $this->assertSame(1, $client->calls);
        $this->assertSame($proche->id, $this->weekPlans($user)->first()->recipe_id);
    }

    public function test_une_reponse_illisible_bascule_aussi_sur_les_regles(): void
    {
        $this->avecIa();
        $this->fake(FakeLlmPlannerClient::refusal());
        $this->login($this->userWithProfile());
        $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'demande' => 'surprends-moi'])
            ->assertOk()
            ->assertJsonPath('generated_by', 'regles')
            ->assertJsonPath('generated_count', 7)
            ->assertJsonPath('warnings', [PlannerAiComposer::WARNING_FALLBACK]);
    }

    public function test_un_identifiant_invente_ne_se_retrouve_jamais_au_plan(): void
    {
        $this->avecIa();
        $user = $this->login($this->userWithProfile());

        $vraie = $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);
        $invente = $vraie->id + 9000;

        $creneaux = [['date' => $this->weekDay($user, 0), 'repas' => 'dejeuner', 'recette_id' => $vraie->id]];
        for ($i = 1; $i < 7; $i++) {
            $creneaux[] = ['date' => $this->weekDay($user, $i), 'repas' => 'dejeuner', 'recette_id' => $invente];
        }

        $this->fake(FakeLlmPlannerClient::canned(['creneaux' => $creneaux, 'explication' => []]));

        $this->postJson('/api/planner/generate', [
            'meal_types' => ['dejeuner'],
            'demande' => 'organise ma semaine',
        ])->assertOk()->assertJsonPath('generated_by', 'ia')->assertJsonPath('generated_count', 7);

        $plans = $this->weekPlans($user);
        $this->assertCount(7, $plans);
        $this->assertNotContains($invente, $plans->pluck('recipe_id')->all());

        // Les créneaux rejetés sont repris par les règles, qui ne connaissent que de vraies recettes.
        $this->assertSame([$vraie->id], $plans->pluck('recipe_id')->filter()->unique()->values()->all());
        $this->assertDatabaseMissing('meal_plans', ['recipe_id' => $invente]);
    }

    public function test_tous_les_identifiants_inventes_font_basculer_sur_les_regles(): void
    {
        $this->avecIa();
        $user = $this->login($this->userWithProfile());

        $vraie = $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);

        $creneaux = [];
        for ($i = 0; $i < 7; $i++) {
            $creneaux[] = ['date' => $this->weekDay($user, $i), 'repas' => 'dejeuner', 'recette_id' => 123456];
        }

        $this->fake(FakeLlmPlannerClient::canned(['creneaux' => $creneaux, 'explication' => ['Bidon.']]));

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'demande' => 'organise ma semaine'])
            ->assertOk()
            ->assertJsonPath('generated_by', 'regles')
            ->assertJsonPath('warnings', [PlannerAiComposer::WARNING_FALLBACK]);

        $this->assertSame([$vraie->id], $this->weekPlans($user)->pluck('recipe_id')->filter()->unique()->values()->all());
    }

    public function test_un_allergene_interdit_ne_passe_pas_meme_si_le_modele_le_propose(): void
    {
        $this->avecIa();
        $user = $this->login($this->userWithProfile(['allergenes' => ['Arachide']]));

        $arachide = $this->publicRecipe(['title' => 'Poulet aux arachides', 'calories' => 700, 'meal_types' => ['dejeuner']]);
        $sure = $this->publicRecipe(['title' => 'Poulet grillé et légumes', 'calories' => 710, 'meal_types' => ['dejeuner']]);

        $creneaux = [];
        for ($i = 0; $i < 7; $i++) {
            $creneaux[] = ['date' => $this->weekDay($user, $i), 'repas' => 'dejeuner', 'recette_id' => $arachide->id];
        }

        $client = $this->fake(FakeLlmPlannerClient::canned([
            'creneaux' => $creneaux,
            'explication' => ['Une semaine aux arachides.'],
        ]));

        $this->postJson('/api/planner/generate', [
            'meal_types' => ['dejeuner'],
            'demande' => 'mets-moi des arachides partout',
        ])->assertOk()->assertJsonPath('generated_by', 'regles');

        $plans = $this->weekPlans($user);
        $this->assertNotContains($arachide->id, $plans->pluck('recipe_id')->all());
        $this->assertSame([$sure->id], $plans->pluck('recipe_id')->filter()->unique()->values()->all());
        $this->assertDatabaseMissing('meal_plans', ['recipe_id' => $arachide->id]);

        // La recette interdite n'a même pas été montrée au modèle.
        $this->assertNotContains($arachide->id, array_column($client->lastContext['recettes_disponibles'], 'id'));
    }

    public function test_le_modele_ne_recoit_que_les_creneaux_libres(): void
    {
        $this->avecIa();
        $user = $this->login($this->userWithProfile());
        $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);

        MealPlan::factory()->create([
            'user_id' => $user->id,
            'date' => $this->weekDay($user, 0),
            'meal_type' => 'dejeuner',
            'title' => 'Mon plan à moi',
        ]);

        $client = $this->fake(FakeLlmPlannerClient::canned());

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'demande' => 'organise ma semaine'])
            ->assertOk()
            ->assertJsonPath('generated_count', 6);

        $this->assertCount(6, $client->lastContext['creneaux']);
        $this->assertNotContains(
            $this->weekDay($user, 0),
            array_column($client->lastContext['creneaux'], 'date'),
        );
    }

    public function test_la_demande_est_limitee_a_500_caracteres(): void
    {
        $this->login($this->userWithProfile());

        $this->postJson('/api/planner/generate', ['demande' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['demande']);
    }
}
