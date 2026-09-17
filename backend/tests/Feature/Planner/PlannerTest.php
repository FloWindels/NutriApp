<?php

namespace Tests\Feature\Planner;

use App\Models\Food;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerTest extends TestCase
{
    use ModuleM7Helpers;
    use RefreshDatabase;

    private const PLAN_KEYS = [
        'id', 'user_id', 'household_id', 'date', 'meal_type', 'meal_type_label', 'recipe_id', 'food_id', 'title',
        'servings', 'notes', 'status', 'status_label', 'meal_id', 'calories', 'is_estimate', 'recipe', 'food',
        'created_at', 'updated_at',
    ];

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/planner')->assertStatus(401)->assertJson(['message' => 'Non authentifié.']);
    }

    public function test_index_returns_week_shape_with_seven_days_and_four_slots(): void
    {
        $user = $this->login($this->userWithProfile());

        $response = $this->getJson('/api/planner')->assertOk();

        $weekStart = $this->weekStart($user);
        $response->assertJsonPath('data.week_start', $weekStart);
        $this->assertSame(1, CarbonImmutable::parse($weekStart)->dayOfWeekIso, 'week_start doit être un lundi');

        $days = $response->json('data.days');
        $this->assertCount(7, $days);
        foreach ($days as $i => $day) {
            $this->assertSame($this->weekDay($user, $i), $day['date']);
            $this->assertEqualsCanonicalizing(['petit_dejeuner', 'dejeuner', 'diner', 'collation'], array_keys($day['slots']));
            foreach ($day['slots'] as $slot) {
                $this->assertSame([], $slot);
            }
        }

        $totals = $response->json('data.totals_per_day');
        $this->assertCount(7, $totals);
        $this->assertSame(['date', 'calories'], array_keys($totals[0]));
        $this->assertEquals(0, $totals[0]['calories']);
    }

    public function test_index_normalises_week_start_to_monday_and_places_plans_in_slots(): void
    {
        $user = $this->login($this->userWithProfile());
        $recipe = $this->publicRecipe(['calories' => 1000, 'servings' => 2]);

        $monday = CarbonImmutable::parse('2026-09-14');
        MealPlan::factory()->create(['user_id' => $user->id, 'date' => '2026-09-14', 'meal_type' => 'dejeuner', 'recipe_id' => $recipe->id, 'title' => $recipe->title, 'servings' => 2]);
        MealPlan::factory()->create(['user_id' => $user->id, 'date' => '2026-09-14', 'meal_type' => 'diner', 'title' => 'Soupe', 'status' => 'annule']);
        MealPlan::factory()->create(['user_id' => $user->id, 'date' => '2026-09-20', 'meal_type' => 'petit_dejeuner', 'title' => 'Porridge aux flocons d’avoine, banane et amandes']);
        MealPlan::factory()->create(['user_id' => $user->id, 'date' => '2026-09-21', 'meal_type' => 'dejeuner', 'title' => 'Semaine suivante']);

        $response = $this->getJson('/api/planner?week_start=2026-09-17')->assertOk();

        $response->assertJsonPath('data.week_start', $monday->toDateString());
        $response->assertJsonCount(1, 'data.days.0.slots.dejeuner');
        $response->assertJsonCount(1, 'data.days.0.slots.diner');
        $response->assertJsonCount(1, 'data.days.6.slots.petit_dejeuner');

        $plan = $response->json('data.days.0.slots.dejeuner.0');
        $this->assertEqualsCanonicalizing(self::PLAN_KEYS, array_keys($plan));
        $this->assertSame('2026-09-14', $plan['date']);
        $this->assertEquals(2, $plan['servings']);
        $this->assertIsNumeric($plan['servings']);
        $this->assertEquals(1000, $plan['calories']); // 500 kcal/portion × 2
        $this->assertFalse($plan['is_estimate']);
        $this->assertSame('Déjeuner', $plan['meal_type_label']);
        $this->assertSame('Prévu', $plan['status_label']);
        $this->assertSame($recipe->id, $plan['recipe']['id']);
        $this->assertEquals(500, $plan['recipe']['per_serving']['calories']);

        // Le plan annulé n'entre pas dans les totaux ; l'idée de repas connue compte 420 kcal.
        $response->assertJsonPath('data.totals_per_day.0.calories', 1000);
        $response->assertJsonPath('data.totals_per_day.6.calories', 420);
        $this->assertTrue($response->json('data.days.6.slots.petit_dejeuner.0.is_estimate'));

        // La semaine suivante n'apparaît pas.
        $titles = collect($response->json('data.days'))->flatMap(fn ($d) => collect($d['slots'])->flatten(1))->pluck('title');
        $this->assertNotContains('Semaine suivante', $titles);
    }

    public function test_index_validates_week_start(): void
    {
        $this->login($this->userWithProfile());

        $this->getJson('/api/planner?week_start=lundi')->assertStatus(422)->assertJsonValidationErrors(['week_start']);
    }

    public function test_index_isolates_personal_plans_and_shares_household_plans(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $household = $this->household($owner, $member);
        $owner = $owner->fresh();

        MealPlan::factory()->create(['user_id' => $member->id, 'household_id' => $household->id, 'date' => $this->weekDay($owner, 1), 'meal_type' => 'diner', 'title' => 'Plan du foyer']);
        MealPlan::factory()->create(['user_id' => $owner->id, 'household_id' => null, 'date' => $this->weekDay($owner, 1), 'meal_type' => 'diner', 'title' => 'Ancien plan perso']);
        MealPlan::factory()->create(['user_id' => $stranger->id, 'household_id' => null, 'date' => $this->weekDay($owner, 1), 'meal_type' => 'diner', 'title' => 'Plan étranger']);

        $this->login($owner);
        $titles = collect($this->getJson('/api/planner')->assertOk()->json('data.days.1.slots.diner'))->pluck('title')->all();
        $this->assertSame(['Plan du foyer'], $titles);

        $this->login($stranger);
        $titles = collect($this->getJson('/api/planner')->assertOk()->json('data.days.1.slots.diner'))->pluck('title')->all();
        $this->assertSame(['Plan étranger'], $titles);
    }

    public function test_store_recipe_plan_uses_recipe_title_and_computes_calories(): void
    {
        $user = $this->login($this->userWithProfile());
        $recipe = $this->publicRecipe(['title' => 'Poulet basquaise', 'calories' => 1200, 'servings' => 2]);

        $response = $this->postJson('/api/planner', [
            'date' => $this->weekDay($user, 2),
            'meal_type' => 'diner',
            'recipe_id' => $recipe->id,
            'servings' => 1.5,
            'notes' => 'Avec du riz',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Repas planifié.')
            ->assertJsonPath('data.title', 'Poulet basquaise')
            ->assertJsonPath('data.recipe_id', $recipe->id)
            ->assertJsonPath('data.servings', 1.5)
            ->assertJsonPath('data.calories', 900)
            ->assertJsonPath('data.status', 'prevu')
            ->assertJsonPath('data.meal_id', null)
            ->assertJsonPath('data.household_id', null)
            ->assertJsonPath('data.notes', 'Avec du riz');

        $this->assertEqualsCanonicalizing(self::PLAN_KEYS, array_keys($response->json('data')));
        $this->assertDatabaseHas('meal_plans', ['user_id' => $user->id, 'recipe_id' => $recipe->id, 'meal_type' => 'diner', 'status' => 'prevu']);
    }

    public function test_store_title_only_plan_matches_meal_ideas_and_food_plan_uses_portion(): void
    {
        $user = $this->login($this->userWithProfile());

        $this->postJson('/api/planner', ['date' => $this->weekDay($user, 0), 'meal_type' => 'petit_dejeuner', 'title' => 'Yaourt nature, muesli et pomme'])
            ->assertStatus(201)
            ->assertJsonPath('data.recipe_id', null)
            ->assertJsonPath('data.calories', 340)
            ->assertJsonPath('data.is_estimate', true);

        $this->postJson('/api/planner', ['date' => $this->weekDay($user, 0), 'meal_type' => 'collation', 'title' => 'Un plat inconnu'])
            ->assertStatus(201)
            ->assertJsonPath('data.calories', null)
            ->assertJsonPath('data.is_estimate', true);

        $food = Food::factory()->create(['name' => 'Banane', 'calories' => 90, 'serving_size_g' => 120]);
        $this->postJson('/api/planner', ['date' => $this->weekDay($user, 0), 'meal_type' => 'collation', 'food_id' => $food->id])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Banane')
            ->assertJsonPath('data.food_id', $food->id)
            ->assertJsonPath('data.calories', 108)
            ->assertJsonPath('data.is_estimate', true)
            ->assertJsonPath('data.food.name', 'Banane');
    }

    public function test_store_validates_in_french(): void
    {
        $this->login($this->userWithProfile());

        $response = $this->postJson('/api/planner', ['date' => '2026-9-1', 'meal_type' => 'brunch', 'servings' => 0.1]);

        $response->assertStatus(422)->assertJsonValidationErrors(['date', 'meal_type', 'title', 'servings']);
        $this->assertStringContainsString('titre', $response->json('errors.title.0'));
        $this->assertStringContainsString('type de repas', $response->json('errors.meal_type.0'));
    }

    public function test_store_rejects_recipe_and_food_together_and_private_foreign_recipe(): void
    {
        $user = $this->login($this->userWithProfile());
        $recipe = $this->publicRecipe();
        $food = Food::factory()->create();

        $this->postJson('/api/planner', ['date' => $this->weekDay($user, 0), 'meal_type' => 'dejeuner', 'recipe_id' => $recipe->id, 'food_id' => $food->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.recipe_id.0', 'Choisis une recette ou un aliment, pas les deux.');

        $private = Recipe::factory()->privee()->create();
        $this->postJson('/api/planner', ['date' => $this->weekDay($user, 0), 'meal_type' => 'dejeuner', 'recipe_id' => $private->id])
            ->assertStatus(404)
            ->assertJson(['message' => 'Introuvable.']);

        $mine = Recipe::factory()->privee()->create(['created_by_user_id' => $user->id]);
        $this->postJson('/api/planner', ['date' => $this->weekDay($user, 0), 'meal_type' => 'dejeuner', 'recipe_id' => $mine->id])
            ->assertStatus(201);
    }

    public function test_store_in_household_sets_household_id(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $this->login($owner->fresh());

        $this->postJson('/api/planner', ['date' => $this->weekDay($owner, 0), 'meal_type' => 'dejeuner', 'title' => 'Gratin'])
            ->assertStatus(201)
            ->assertJsonPath('data.household_id', $household->id)
            ->assertJsonPath('data.user_id', $owner->id);
    }

    public function test_update_changes_fields_and_switches_target(): void
    {
        $user = $this->login($this->userWithProfile());
        $recipe = $this->publicRecipe(['title' => 'Curry de légumes', 'calories' => 600, 'servings' => 1]);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 0), 'meal_type' => 'dejeuner', 'title' => 'Titre libre']);

        $this->putJson('/api/planner/'.$plan->id, ['servings' => 2, 'notes' => 'Double portion', 'meal_type' => 'diner', 'status' => 'annule'])
            ->assertOk()
            ->assertJsonPath('message', 'Plan mis à jour.')
            ->assertJsonPath('data.servings', 2)
            ->assertJsonPath('data.notes', 'Double portion')
            ->assertJsonPath('data.meal_type', 'diner')
            ->assertJsonPath('data.status', 'annule')
            ->assertJsonPath('data.title', 'Titre libre');

        $this->putJson('/api/planner/'.$plan->id, ['recipe_id' => $recipe->id, 'title' => null])
            ->assertOk()
            ->assertJsonPath('data.recipe_id', $recipe->id)
            ->assertJsonPath('data.title', 'Curry de légumes')
            ->assertJsonPath('data.calories', 1200);

        $this->putJson('/api/planner/'.$plan->id, ['status' => 'termine'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_update_and_delete_foreign_plan_return_404(): void
    {
        $this->login($this->userWithProfile());
        $foreign = MealPlan::factory()->create();

        $this->putJson('/api/planner/'.$foreign->id, ['title' => 'Piraté'])->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->deleteJson('/api/planner/'.$foreign->id)->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->postJson('/api/planner/'.$foreign->id.'/log')->assertStatus(404)->assertJson(['message' => 'Introuvable.']);

        $this->assertDatabaseHas('meal_plans', ['id' => $foreign->id, 'status' => 'prevu']);
    }

    public function test_destroy_removes_own_plan(): void
    {
        $user = $this->login($this->userWithProfile());
        $plan = MealPlan::factory()->create(['user_id' => $user->id]);

        $this->deleteJson('/api/planner/'.$plan->id)->assertOk()->assertJson(['message' => 'Plan supprimé.']);
        $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
    }
}
