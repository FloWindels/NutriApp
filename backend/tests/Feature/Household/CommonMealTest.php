<?php

namespace Tests\Feature\Household;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Household\CommonMealService;

/**
 * Repas commun (brief §10) : aperçu des portions (budget, parts, bornes 0,5–2,0, profil non
 * partagé, mineur) et création d'un élément de repas par membre.
 */
class CommonMealTest extends HouseholdTestCase
{
    /**
     * Recette publique : 1 000 kcal pour 2 portions → 500 kcal / portion.
     */
    private function recipe(array $attributes = []): Recipe
    {
        return Recipe::factory()->create(array_merge([
            'title' => 'Gratin de brocolis',
            'calories' => 1000,
            'proteins' => 60,
            'carbs' => 90,
            'fat' => 40,
            'servings' => 2,
            'is_public' => true,
            'is_estimate' => false,
        ], $attributes));
    }

    public function test_preview_calcule_les_portions_par_membre(): void
    {
        // Propriétaire : cibles 2 000 kcal, rien d'enregistré → budget déjeuner = 2000 × 0,35 = 700 → 1,4 → 1,5 portion.
        $owner = $this->login($this->userWithProfile(['calories_cibles' => 2000, 'regime_alimentaire' => 'omnivore'], ['name' => 'Alice']));
        $household = $this->householdOwnedBy($owner);

        // Membre profil non partagé : cibles 1 600 → 1600 × 0,35 = 560 → 1,12 → 1,0 (estimation).
        $bob = $this->userWithProfile(['calories_cibles' => 1600, 'regime_alimentaire' => 'omnivore'], ['name' => 'Bob']);
        $this->joinHousehold($bob, $household);
        $this->service->updateShareProfile($this->service->membershipOf($bob), false);

        // Gros budget : 4 000 × 0,35 = 1 400 → 2,8 → 3,0 → borné à 2,0.
        $carl = $this->userWithProfile(['calories_cibles' => 4000, 'regime_alimentaire' => 'omnivore'], ['name' => 'Carl']);
        $this->joinHousehold($carl, $household);

        // Petit budget : 300 × 0,35 = 105 → plancher 150 → 0,3 → 0,5.
        $dina = $this->userWithProfile(['calories_cibles' => 300, 'regime_alimentaire' => 'omnivore'], ['name' => 'Dina']);
        $this->joinHousehold($dina, $household);

        // Mineur (15 ans) : label « portion indicative ».
        $emma = $this->userWithProfile(['calories_cibles' => 2000, 'age' => 15, 'regime_alimentaire' => 'omnivore'], ['name' => 'Emma']);
        $this->joinHousehold($emma, $household);

        $recipe = $this->recipe();

        $response = $this->postJson('/api/household/common-meal/preview', [
            'recipe_id' => $recipe->id,
            'meal_type' => 'dejeuner',
        ])->assertOk();

        $data = $response->json('data');
        $this->assertEquals(['recipe', 'meal_type', 'date', 'members'], array_keys($data));
        $this->assertEquals(['id' => $recipe->id, 'title' => 'Gratin de brocolis', 'per_serving' => ['calories' => 500.0, 'proteins' => 30.0, 'carbs' => 45.0, 'fat' => 20.0]], $data['recipe']);
        $this->assertEquals('dejeuner', $data['meal_type']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['date']);
        $this->assertCount(5, $data['members']);

        $members = collect($data['members'])->keyBy('user_id');
        $this->assertEquals(
            ['user_id', 'name', 'is_me', 'share_profile', 'is_minor', 'target_kcal', 'portions', 'calories', 'is_estimate', 'label', 'factors'],
            array_keys($members[$owner->id])
        );

        $alice = $members[$owner->id];
        $this->assertTrue($alice['is_me']);
        $this->assertEquals(700.0, $alice['target_kcal']);
        $this->assertEquals(1.5, $alice['portions']);
        $this->assertEquals(750.0, $alice['calories']);
        $this->assertFalse($alice['is_estimate']);
        $this->assertEquals(CommonMealService::LABEL_ADAPTEE, $alice['label']);
        $this->assertEquals([CommonMealService::FACTEUR_BUDGET], $alice['factors']);

        $b = $members[$bob->id];
        $this->assertFalse($b['share_profile']);
        $this->assertEquals(560.0, $b['target_kcal']);
        $this->assertEquals(1.0, $b['portions']);
        $this->assertEquals(500.0, $b['calories']);
        $this->assertTrue($b['is_estimate']);
        $this->assertEquals(CommonMealService::LABEL_ESTIMATION, $b['label']);
        $this->assertContains('profil non partagé — estimation sur les cibles', $b['factors']);

        $c = $members[$carl->id];
        $this->assertEquals(1400.0, $c['target_kcal']);
        $this->assertEquals(2.0, $c['portions']);
        $this->assertEquals(1000.0, $c['calories']);

        $d = $members[$dina->id];
        $this->assertEquals(150.0, $d['target_kcal']);
        $this->assertEquals(0.5, $d['portions']);
        $this->assertEquals(250.0, $d['calories']);

        $e = $members[$emma->id];
        $this->assertTrue($e['is_minor']);
        $this->assertEquals('portion indicative', $e['label']);
        $this->assertTrue($e['is_estimate']);
        $this->assertContains(CommonMealService::FACTEUR_MINEUR, $e['factors']);
    }

    public function test_preview_tient_compte_des_repas_deja_enregistres_et_de_la_part_du_type(): void
    {
        // Cibles 2 000, déjeuner déjà enregistré (600 kcal) → restant 1 400 ; dîner : 1400 × 0,30 / (0,25 + 0,10 + 0,30) = 646 → 1,29 → 1,5.
        $owner = $this->login($this->userWithProfile(['calories_cibles' => 2000, 'regime_alimentaire' => 'omnivore']));
        $this->householdOwnedBy($owner);
        $meal = Meal::factory()->ofType('dejeuner')->on(now('Europe/Paris')->toDateString())->create(['user_id' => $owner->id]);
        MealItem::factory()->create(['meal_id' => $meal->id, 'calories' => 600, 'proteins' => 30, 'carbs' => 60, 'fat' => 20]);

        $recipe = $this->recipe();
        $me = collect($this->postJson('/api/household/common-meal/preview', [
            'recipe_id' => $recipe->id,
            'meal_type' => 'diner',
            'date' => now('Europe/Paris')->toDateString(),
        ])->assertOk()->json('data.members'))->firstWhere('user_id', $owner->id);

        $this->assertEquals(646.0, $me['target_kcal']);
        $this->assertEquals(1.5, $me['portions']);
    }

    public function test_preview_recette_estimee_ou_sans_macros_marque_is_estimate(): void
    {
        $owner = $this->login($this->userWithProfile(['calories_cibles' => 2000]));
        $this->householdOwnedBy($owner);
        $recipe = $this->recipe(['proteins' => null, 'carbs' => null, 'fat' => null]);

        $me = collect($this->postJson('/api/household/common-meal/preview', [
            'recipe_id' => $recipe->id,
            'meal_type' => 'dejeuner',
        ])->assertOk()->json('data.members'))->firstWhere('user_id', $owner->id);

        $this->assertTrue($me['is_estimate']);
        $this->assertContains(CommonMealService::FACTEUR_RECETTE_ESTIMEE, $me['factors']);
    }

    public function test_preview_membre_sans_profil_portion_par_defaut(): void
    {
        $owner = $this->login($this->userWithProfile(['calories_cibles' => 2000]));
        $household = $this->householdOwnedBy($owner);
        $noProfile = User::factory()->create();
        $this->joinHousehold($noProfile, $household);

        $m = collect($this->postJson('/api/household/common-meal/preview', [
            'recipe_id' => $this->recipe()->id,
            'meal_type' => 'dejeuner',
        ])->assertOk()->json('data.members'))->firstWhere('user_id', $noProfile->id);

        $this->assertNull($m['target_kcal']);
        $this->assertEquals(1.0, $m['portions']);
        $this->assertTrue($m['is_estimate']);
        $this->assertEquals(CommonMealService::LABEL_PROFIL_INCOMPLET, $m['label']);
    }

    public function test_preview_validation_422(): void
    {
        $owner = $this->login($this->userWithProfile());
        $this->householdOwnedBy($owner);

        $this->postJson('/api/household/common-meal/preview', ['meal_type' => 'brunch'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipe_id', 'meal_type'])
            ->assertJsonPath('errors.recipe_id.0', 'Le champ recette est obligatoire.');

        $this->postJson('/api/household/common-meal/preview', ['recipe_id' => $this->recipe()->id, 'meal_type' => 'diner', 'date' => '16/09/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_preview_sans_foyer_422_et_recette_privee_d_autrui_404(): void
    {
        $user = $this->login($this->userWithProfile());
        $recipe = $this->recipe();

        $this->postJson('/api/household/common-meal/preview', ['recipe_id' => $recipe->id, 'meal_type' => 'dejeuner'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tu ne fais partie d’aucun foyer.');

        $this->householdOwnedBy($user);
        $private = Recipe::factory()->privee()->create(['created_by_user_id' => User::factory()->create()->id]);

        $this->postJson('/api/household/common-meal/preview', ['recipe_id' => $private->id, 'meal_type' => 'dejeuner'])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Introuvable.']);
    }

    public function test_create_ecrit_un_element_de_repas_par_membre(): void
    {
        $owner = $this->login($this->userWithProfile(['calories_cibles' => 2000], ['name' => 'Alice']));
        $household = $this->householdOwnedBy($owner);
        $bob = $this->userWithProfile(['calories_cibles' => 1800], ['name' => 'Bob']);
        $this->joinHousehold($bob, $household);
        $recipe = $this->recipe();
        $date = '2026-09-16';

        $response = $this->postJson('/api/household/common-meal', [
            'recipe_id' => $recipe->id,
            'meal_type' => 'diner',
            'date' => $date,
            'portions' => [(string) $owner->id => 1.5, (string) $bob->id => 1],
        ])->assertCreated()
            ->assertJsonPath('message', 'Repas commun enregistré pour 2 membres.');

        $created = $response->json('data.created');
        $this->assertCount(2, $created);
        $this->assertEquals(['user_id', 'meal_id', 'meal_item_id', 'portions', 'calories'], array_keys($created[0]));

        $byUser = collect($created)->keyBy('user_id');
        $this->assertEquals(1.5, $byUser[$owner->id]['portions']);
        $this->assertEquals(750.0, $byUser[$owner->id]['calories']);
        $this->assertEquals(1.0, $byUser[$bob->id]['portions']);
        $this->assertEquals(500.0, $byUser[$bob->id]['calories']);

        foreach ([$owner, $bob] as $user) {
            $meal = Meal::query()->where('user_id', $user->id)->where('date', $date)->firstOrFail();
            $this->assertEquals('diner', $meal->type->value);
            $this->assertEquals((int) $byUser[$user->id]['meal_id'], (int) $meal->id);

            $items = MealItem::query()->where('meal_id', $meal->id)->get();
            $this->assertCount(1, $items);
            $this->assertEquals('recipe', $items[0]->source_type);
            $this->assertEquals($recipe->id, (int) $items[0]->recipe_id);
            $this->assertEquals('portion', $items[0]->unit);
            $this->assertEquals('Gratin de brocolis', $items[0]->label);
        }

        $this->assertEquals(2, MealItem::query()->count());
        $this->assertHouseholdInvariant();
    }

    public function test_create_refuse_un_membre_qui_ne_partage_pas_son_profil_422(): void
    {
        $owner = $this->login($this->userWithProfile());
        $household = $this->householdOwnedBy($owner);
        $bob = $this->userWithProfile([], ['name' => 'Bob']);
        $this->joinHousehold($bob, $household);
        $this->service->updateShareProfile($this->service->membershipOf($bob), false);

        $errors = $this->postJson('/api/household/common-meal', [
            'recipe_id' => $this->recipe()->id,
            'meal_type' => 'dejeuner',
            'date' => '2026-09-16',
            'portions' => [(string) $bob->id => 1],
        ])->assertStatus(422)
            ->json('errors');

        $this->assertSame(
            ['Bob ne partage pas son profil : tu ne peux pas enregistrer un repas pour cette personne.'],
            $errors['portions.'.$bob->id] ?? null
        );
        $this->assertEquals(0, MealItem::query()->count());

        // Bob peut toujours enregistrer pour lui-même.
        $this->login($bob);
        $this->postJson('/api/household/common-meal', [
            'recipe_id' => Recipe::query()->firstOrFail()->id,
            'meal_type' => 'dejeuner',
            'date' => '2026-09-16',
            'portions' => [(string) $bob->id => 1],
        ])->assertCreated();
        $this->assertEquals(1, MealItem::query()->count());
    }

    public function test_create_refuse_un_non_membre_et_ne_cree_rien(): void
    {
        $owner = $this->login($this->userWithProfile());
        $this->householdOwnedBy($owner);
        $stranger = User::factory()->create();

        $errors = $this->postJson('/api/household/common-meal', [
            'recipe_id' => $this->recipe()->id,
            'meal_type' => 'dejeuner',
            'date' => '2026-09-16',
            'portions' => [(string) $owner->id => 1, (string) $stranger->id => 1],
        ])->assertStatus(422)
            ->json('errors');

        $this->assertSame([CommonMealService::MSG_NON_MEMBRE], $errors['portions.'.$stranger->id] ?? null);
        $this->assertArrayNotHasKey('portions.'.$owner->id, $errors);

        $this->assertEquals(0, MealItem::query()->count());
        $this->assertEquals(0, Meal::query()->count());
    }

    public function test_create_recette_privee_seulement_pour_soi(): void
    {
        $owner = $this->login($this->userWithProfile());
        $household = $this->householdOwnedBy($owner);
        $bob = $this->userWithProfile();
        $this->joinHousehold($bob, $household);
        $private = $this->recipe(['is_public' => false, 'created_by_user_id' => $owner->id]);

        $this->postJson('/api/household/common-meal', [
            'recipe_id' => $private->id,
            'meal_type' => 'dejeuner',
            'date' => '2026-09-16',
            'portions' => [(string) $owner->id => 1, (string) $bob->id => 1],
        ])->assertStatus(422)
            ->assertJsonPath('errors.recipe_id.0', CommonMealService::MSG_RECETTE_PRIVEE);

        $this->postJson('/api/household/common-meal', [
            'recipe_id' => $private->id,
            'meal_type' => 'dejeuner',
            'date' => '2026-09-16',
            'portions' => [(string) $owner->id => 2],
        ])->assertCreated()
            ->assertJsonPath('message', 'Repas commun enregistré.')
            ->assertJsonPath('data.created.0.portions', 2)
            ->assertJsonPath('data.created.0.calories', 1000);
    }

    public function test_create_validation_422(): void
    {
        $owner = $this->login($this->userWithProfile());
        $this->householdOwnedBy($owner);
        $recipe = $this->recipe();

        $this->postJson('/api/household/common-meal', [
            'recipe_id' => $recipe->id,
            'meal_type' => 'dejeuner',
            'portions' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['date', 'portions']);

        $this->postJson('/api/household/common-meal', [
            'recipe_id' => $recipe->id,
            'meal_type' => 'dejeuner',
            'date' => '2026-09-16',
            'portions' => [(string) $owner->id => 0.1],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['portions.'.$owner->id]);

        $this->postJson('/api/household/common-meal', [
            'recipe_id' => $recipe->id,
            'meal_type' => 'dejeuner',
            'date' => '2026-09-16',
            'portions' => ['abc' => 1],
        ])->assertStatus(422)
            ->assertJsonPath('errors.portions.0', 'Les clés de « portions » doivent être des identifiants de membres.');

        $this->assertEquals(0, MealItem::query()->count());
    }

    public function test_clamp_portions_pas_de_0_5_et_bornes(): void
    {
        $this->assertEquals(0.5, CommonMealService::clampPortions(0.1));
        $this->assertEquals(0.5, CommonMealService::clampPortions(0.7));
        $this->assertEquals(1.0, CommonMealService::clampPortions(0.76));
        $this->assertEquals(1.0, CommonMealService::clampPortions(1.24));
        $this->assertEquals(1.5, CommonMealService::clampPortions(1.25));
        $this->assertEquals(1.5, CommonMealService::clampPortions(1.4));
        $this->assertEquals(2.0, CommonMealService::clampPortions(1.75));
        $this->assertEquals(2.0, CommonMealService::clampPortions(4.2));
    }
}
