<?php

namespace Tests\Feature\Planner;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PlannerGenerateTest extends TestCase
{
    use ModuleM7Helpers;
    use RefreshDatabase;

    /**
     * Plans (tous types) de la semaine courante de l'utilisateur, triés par date puis type.
     */
    private function weekPlans(User $user): Collection
    {
        return MealPlan::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$this->weekDay($user, 0), $this->weekDay($user, 6)])
            ->orderBy('date')
            ->orderBy('meal_type')
            ->get();
    }

    public function test_generate_fills_lunch_and_dinner_by_default_within_calorie_tolerance(): void
    {
        $user = $this->login($this->userWithProfile()); // 2 000 kcal → déjeuner 700, dîner 600

        $inRangeLunch = $this->publicRecipe(['title' => 'Bowl équilibré', 'calories' => 700, 'meal_types' => ['dejeuner']]);
        $inRangeDinner = $this->publicRecipe(['title' => 'Soupe complète', 'calories' => 600, 'meal_types' => ['diner']]);
        $tooRich = $this->publicRecipe(['title' => 'Raclette géante', 'calories' => 1200]);
        $tooLight = $this->publicRecipe(['title' => 'Bouillon', 'calories' => 150]);

        $response = $this->postJson('/api/planner/generate')->assertOk();

        $response->assertJsonPath('generated_count', 14);
        $response->assertJsonPath('message', '14 repas planifiés.');
        $response->assertJsonPath('data.week_start', $this->weekStart($user));
        $this->assertCount(7, $response->json('data.days'));

        $plans = $this->weekPlans($user);
        $this->assertCount(14, $plans);
        $this->assertEqualsCanonicalizing(['dejeuner', 'diner'], $plans->pluck('meal_type')->unique()->values()->all());
        $this->assertTrue($plans->every(fn (MealPlan $p) => $p->status === 'prevu'));

        $recipeIds = $plans->pluck('recipe_id')->filter()->unique()->values()->all();
        $this->assertNotContains($tooRich->id, $recipeIds);
        $this->assertNotContains($tooLight->id, $recipeIds);
        $this->assertContains($inRangeLunch->id, $recipeIds);
        $this->assertContains($inRangeDinner->id, $recipeIds);

        // Le lundi, la recette la plus proche du budget est choisie pour chaque type.
        $monday = $plans->where('date', CarbonImmutable::parse($this->weekDay($user, 0)))->keyBy('meal_type');
        $this->assertSame($inRangeLunch->id, $monday['dejeuner']->recipe_id);
        $this->assertSame($inRangeDinner->id, $monday['diner']->recipe_id);
    }

    public function test_generate_picks_closest_to_budget_and_never_repeats_within_three_days(): void
    {
        $user = $this->login($this->userWithProfile());

        $b = $this->publicRecipe(['title' => 'B', 'calories' => 760]);
        $c = $this->publicRecipe(['title' => 'C', 'calories' => 620]);
        $a = $this->publicRecipe(['title' => 'A', 'calories' => 700]);
        $out = $this->publicRecipe(['title' => 'Trop riche', 'calories' => 950]); // > 700 × 1,3

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk()->assertJsonPath('generated_count', 7);

        $plans = $this->weekPlans($user)->values();
        $this->assertCount(7, $plans);
        $this->assertNotContains($out->id, $plans->pluck('recipe_id')->all());

        // Proximité : A (distance 0) puis B (60) puis C (80), puis rotation.
        $this->assertSame([$a->id, $b->id, $c->id, $a->id, $b->id, $c->id, $a->id], $plans->pluck('recipe_id')->all());

        foreach ($plans as $i => $plan) {
            foreach ($plans as $j => $other) {
                if ($i < $j && $plan->recipe_id === $other->recipe_id) {
                    $this->assertGreaterThanOrEqual(3, abs($plan->date->diffInDays($other->date)), 'Une recette ne doit pas revenir à moins de 3 jours.');
                }
            }
        }
    }

    public function test_generate_falls_back_to_meal_ideas_when_no_recipe_matches(): void
    {
        $user = $this->login($this->userWithProfile());
        $ideas = collect(config('meal_ideas'));

        $response = $this->postJson('/api/planner/generate', ['meal_types' => ['petit_dejeuner', 'diner']])->assertOk();
        $response->assertJsonPath('generated_count', 14);

        $plans = $this->weekPlans($user);
        $this->assertTrue($plans->every(fn (MealPlan $p) => $p->recipe_id === null && $p->food_id === null));

        foreach ($plans as $plan) {
            $idea = $ideas->firstWhere('title', $plan->title);
            $this->assertNotNull($idea, 'Le titre doit venir de config/meal_ideas.php : '.$plan->title);
            $this->assertContains($plan->meal_type, $idea['meal_types']);
        }

        // Le repli respecte aussi la règle « pas de répétition à 3 jours ».
        $breakfasts = $plans->where('meal_type', 'petit_dejeuner')->values();
        $this->assertNotSame($breakfasts[0]->title, $breakfasts[1]->title);
        $this->assertNotSame($breakfasts[0]->title, $breakfasts[2]->title);

        // Les calories sont exposées (estimation) dans la réponse.
        $first = $response->json('data.days.0.slots.petit_dejeuner.0');
        $this->assertNotNull($first['calories']);
        $this->assertTrue($first['is_estimate']);
    }

    public function test_generate_excludes_allergens_and_excluded_foods_in_title_and_ingredients(): void
    {
        $user = $this->login($this->userWithProfile(['allergenes' => ['Arachide'], 'aliments_exclus' => ['champignons']]));

        $peanut = $this->publicRecipe(['title' => 'Poulet aux arachides', 'calories' => 700]);
        $satay = $this->publicRecipe(['title' => 'Brochettes satay', 'calories' => 700, 'ingredients' => [['name' => 'Cacahuètes', 'ean' => null, 'amount' => 50, 'unit' => 'g'], ['name' => 'Arachides grillées', 'ean' => null, 'amount' => 20, 'unit' => 'g']]]);
        $mushroom = $this->publicRecipe(['title' => 'Risotto aux champignons', 'calories' => 700]);
        $safe = $this->publicRecipe(['title' => 'Poulet grillé et légumes', 'calories' => 720, 'ingredients' => [['name' => 'Poulet', 'ean' => null, 'amount' => 200, 'unit' => 'g']]]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk();

        $recipeIds = $this->weekPlans($user)->pluck('recipe_id')->filter()->unique()->values()->all();
        $this->assertSame([$safe->id], $recipeIds);
        $this->assertNotContains($peanut->id, $recipeIds);
        $this->assertNotContains($satay->id, $recipeIds);
        $this->assertNotContains($mushroom->id, $recipeIds);
    }

    public function test_generate_respects_vegan_regime_using_tags_and_title_keywords(): void
    {
        $user = $this->login($this->userWithProfile(['regime_alimentaire' => 'vegan']));

        $chicken = $this->publicRecipe(['title' => 'Poulet rôti', 'calories' => 700]);
        $cheese = $this->publicRecipe(['title' => 'Gratin de fromage', 'calories' => 700]);
        $veganBowl = $this->publicRecipe(['title' => 'Bowl de quinoa', 'calories' => 700, 'tags' => ['vegan']]);
        $oatMilk = $this->publicRecipe(['title' => 'Porridge au lait d’avoine', 'calories' => 700, 'tags' => ['vegan']]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner', 'diner']])->assertOk();

        $plans = $this->weekPlans($user);
        $recipeIds = $plans->pluck('recipe_id')->filter()->unique()->values()->all();
        $this->assertEqualsCanonicalizing([$veganBowl->id, $oatMilk->id], $recipeIds);
        $this->assertNotContains($chicken->id, $recipeIds);
        $this->assertNotContains($cheese->id, $recipeIds);

        // Les idées de repli sont elles aussi filtrées : uniquement des idées taguées vegan
        // ou sans mot-clé animal dans le titre.
        $ideas = collect(config('meal_ideas'))->keyBy('title');
        foreach ($plans->whereNull('recipe_id') as $plan) {
            $idea = $ideas->get($plan->title);
            $this->assertNotNull($idea);
            $this->assertTrue(
                in_array('vegan', $idea['tags'], true) || preg_match('/poulet|saumon|thon|oeuf|œuf|lait|fromage|yaourt|dinde|steak|cabillaud|feta|chèvre|miel/iu', $idea['title']) !== 1,
                'Idée incompatible vegan : '.$plan->title
            );
        }
    }

    public function test_generate_never_gives_keto_or_low_carb_recipes_to_minors(): void
    {
        $user = $this->login($this->userWithProfile(['age' => 15, 'objectif_type' => 'maintenir']));

        $keto = $this->publicRecipe(['title' => 'Assiette cétogène', 'calories' => 700, 'tags' => ['keto']]);
        $lowCarb = $this->publicRecipe(['title' => 'Assiette légère', 'calories' => 700, 'tags' => ['low_carb']]);
        $normal = $this->publicRecipe(['title' => 'Pâtes aux légumes', 'calories' => 740]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk();

        $plans = $this->weekPlans($user);
        $recipeIds = $plans->pluck('recipe_id')->filter()->unique()->values()->all();
        $this->assertSame([$normal->id], $recipeIds);
        $this->assertNotContains($keto->id, $recipeIds);
        $this->assertNotContains($lowCarb->id, $recipeIds);

        $ideas = collect(config('meal_ideas'))->keyBy('title');
        foreach ($plans->whereNull('recipe_id') as $plan) {
            $this->assertEmpty(array_intersect(['keto', 'low_carb'], $ideas->get($plan->title)['tags']), 'Idée interdite aux mineurs : '.$plan->title);
        }
    }

    public function test_generate_prefers_own_recipes_then_recipes_using_expiring_stock(): void
    {
        $user = $this->login($this->userWithProfile());
        $stock = $this->personalStock($user);

        StockItem::factory()->create(['stock_id' => $stock->id, 'food_name' => 'Saumon frais', 'quantity' => 2, 'unit' => 'piece', 'expires_at' => CarbonImmutable::now()->addDays(2)->toDateString()]);
        StockItem::factory()->expired()->create(['stock_id' => $stock->id, 'food_name' => 'Thon', 'quantity' => 1, 'unit' => 'piece']);

        $tuna = $this->publicRecipe(['title' => 'Salade de thon', 'calories' => 700, 'ingredients' => [['name' => 'Thon', 'ean' => null, 'amount' => 100, 'unit' => 'g']]]);
        $salmon = $this->publicRecipe(['title' => 'Saumon grillé', 'calories' => 700, 'ingredients' => [['name' => 'Saumon', 'ean' => null, 'amount' => 150, 'unit' => 'g']]]);
        $mine = Recipe::factory()->create(['created_by_user_id' => $user->id, 'is_public' => false, 'title' => 'Ma recette', 'calories' => 760, 'servings' => 1, 'tags' => [], 'meal_types' => ['dejeuner']]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])->assertOk();

        $plans = $this->weekPlans($user)->values();
        $this->assertSame($mine->id, $plans[0]->recipe_id, 'Mes recettes passent avant les publiques.');
        $this->assertSame($salmon->id, $plans[1]->recipe_id, 'Le stock qui périme sous 7 jours (non périmé) est préféré.');
        $this->assertSame($tuna->id, $plans[2]->recipe_id);
    }

    public function test_generate_without_replace_keeps_existing_plans_and_with_replace_only_replaces_prevu(): void
    {
        $user = $this->login($this->userWithProfile());
        $this->publicRecipe(['title' => 'Recette A', 'calories' => 700]);
        $this->publicRecipe(['title' => 'Recette B', 'calories' => 720]);
        $this->publicRecipe(['title' => 'Recette C', 'calories' => 740]);

        $kept = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 0), 'meal_type' => 'dejeuner', 'title' => 'Mon plan à moi']);
        $done = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 1), 'meal_type' => 'dejeuner', 'title' => 'Déjà mangé', 'status' => 'realise']);

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'replace' => false])
            ->assertOk()
            ->assertJsonPath('generated_count', 5);

        $this->assertDatabaseHas('meal_plans', ['id' => $kept->id, 'title' => 'Mon plan à moi']);
        $this->assertDatabaseHas('meal_plans', ['id' => $done->id, 'status' => 'realise']);
        $this->assertCount(7, $this->weekPlans($user));

        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner'], 'replace' => true])
            ->assertOk()
            ->assertJsonPath('generated_count', 6);

        $this->assertDatabaseMissing('meal_plans', ['id' => $kept->id]);
        $this->assertDatabaseHas('meal_plans', ['id' => $done->id, 'status' => 'realise', 'title' => 'Déjà mangé']);
        $plans = $this->weekPlans($user);
        $this->assertCount(7, $plans);
        $this->assertSame(1, $plans->where('status', 'realise')->count());

        // Rien à faire sans replace : 0 créneau libre.
        $this->postJson('/api/planner/generate', ['meal_types' => ['dejeuner']])
            ->assertOk()
            ->assertJsonPath('generated_count', 0)
            ->assertJsonPath('message', 'Aucun créneau à compléter : ta semaine est déjà planifiée.');
    }

    public function test_generate_is_deterministic(): void
    {
        $user = $this->login($this->userWithProfile());
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $i => $title) {
            $this->publicRecipe(['title' => $title, 'calories' => 660 + $i * 25]);
        }

        $this->postJson('/api/planner/generate', ['replace' => true])->assertOk();
        $first = $this->weekPlans($user)->map(fn (MealPlan $p) => $p->date->format('Y-m-d').'|'.$p->meal_type.'|'.$p->title)->all();

        $this->postJson('/api/planner/generate', ['replace' => true])->assertOk();
        $second = $this->weekPlans($user)->map(fn (MealPlan $p) => $p->date->format('Y-m-d').'|'.$p->meal_type.'|'.$p->title)->all();

        $this->assertSame($first, $second);
    }

    public function test_generate_validates_meal_types_and_week_start(): void
    {
        $this->login($this->userWithProfile());

        $this->postJson('/api/planner/generate', ['meal_types' => ['brunch'], 'week_start' => '2026/09/14', 'replace' => 'peut-être'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meal_types.0', 'week_start', 'replace']);
    }

    public function test_generate_without_profile_ignores_calorie_filter(): void
    {
        $user = $this->login(User::factory()->create());
        $recipe = $this->publicRecipe(['title' => 'Plat unique', 'calories' => 1500]);

        $this->postJson('/api/planner/generate', ['meal_types' => ['diner']])->assertOk()->assertJsonPath('generated_count', 7);

        $this->assertContains($recipe->id, $this->weekPlans($user)->pluck('recipe_id')->all());
    }

    public function test_generate_in_household_creates_household_plans_visible_to_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, $member);
        $this->login($owner->fresh());

        $this->postJson('/api/planner/generate', ['meal_types' => ['diner']])->assertOk()->assertJsonPath('generated_count', 7);

        $this->assertSame(7, MealPlan::query()->where('household_id', $household->id)->count());

        $this->login($member->fresh());
        $this->getJson('/api/planner')->assertOk()->assertJsonCount(1, 'data.days.0.slots.diner');
    }
}
