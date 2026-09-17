<?php

namespace Tests\Feature\Planner;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerLogTest extends TestCase
{
    use ModuleM7Helpers;
    use RefreshDatabase;

    private const DAY_KEYS = ['date', 'meals', 'totals', 'targets', 'remaining', 'sport', 'next_meal_type', 'plancher_kcal'];

    public function test_log_recipe_plan_creates_meal_item_and_marks_plan_realised(): void
    {
        $user = $this->login($this->userWithProfile());
        $recipe = $this->publicRecipe(['title' => 'Lasagnes', 'calories' => 1000, 'proteins' => 60, 'carbs' => 100, 'fat' => 30, 'servings' => 2]);
        $date = $this->weekDay($user, 2);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $date, 'meal_type' => 'diner', 'recipe_id' => $recipe->id, 'title' => 'Lasagnes', 'servings' => 2]);

        $response = $this->postJson('/api/planner/'.$plan->id.'/log')->assertOk();

        $response->assertJsonPath('message', 'Repas enregistré dans ta journée.');
        $response->assertJsonPath('data.status', 'realise');
        $response->assertJsonPath('data.status_label', 'Réalisé');
        $this->assertSame([], $response->json('stock_decrements'));

        $meal = Meal::query()->where('user_id', $user->id)->where('date', $date)->where('type', 'diner')->firstOrFail();
        $response->assertJsonPath('data.meal_id', $meal->id);

        $item = MealItem::query()->where('meal_id', $meal->id)->firstOrFail();
        $this->assertSame('recipe', $item->source_type);
        $this->assertSame($recipe->id, $item->recipe_id);
        $this->assertSame(2.0, $item->quantity);
        $this->assertSame('portion', $item->unit);
        $this->assertSame(1000.0, $item->calories); // 500 kcal/portion × 2
        $this->assertSame(60.0, $item->proteins);
        $this->assertFalse($item->is_estimate);

        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id, 'status' => 'realise', 'meal_id' => $meal->id]);

        $day = $response->json('day');
        $this->assertEqualsCanonicalizing(self::DAY_KEYS, array_keys($day));
        $this->assertSame($date, $day['date']);
        $this->assertCount(1, $day['meals']);
        $this->assertSame('diner', $day['meals'][0]['type']);
        $this->assertEquals(1000, $day['totals']['calories']);
        $this->assertSame(2000, $day['targets']['calories']);
        $this->assertEquals(1000, $day['remaining']['calories']);
        $this->assertArrayHasKey('calories_bonus', $day['sport']);

        // Les cibles du jour ont été figées par MealService.
        $this->assertDatabaseHas('daily_targets', ['user_id' => $user->id, 'date' => $date, 'calories' => 2000]);
    }

    public function test_log_title_only_plan_uses_meal_ideas_calories_flagged_as_estimate(): void
    {
        $user = $this->login($this->userWithProfile());
        $date = $this->weekDay($user, 0);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $date, 'meal_type' => 'petit_dejeuner', 'title' => 'porridge aux flocons d’avoine, banane et amandes', 'servings' => 1.5]);

        $response = $this->postJson('/api/planner/'.$plan->id.'/log')->assertOk();

        $item = MealItem::query()->whereHas('meal', fn ($q) => $q->where('user_id', $user->id))->firstOrFail();
        $this->assertSame('custom', $item->source_type);
        $this->assertSame('porridge aux flocons d’avoine, banane et amandes', $item->label);
        $this->assertSame(630.0, $item->calories); // 420 × 1,5
        $this->assertSame(21.0, $item->proteins); // 14 × 1,5
        $this->assertSame(1.5, $item->quantity);
        $this->assertSame('portion', $item->unit);
        $this->assertTrue($item->is_estimate);

        $this->assertEquals(630, $response->json('day.totals.calories'));
        $this->assertTrue($response->json('day.totals.is_partial'));
    }

    public function test_log_unknown_title_creates_zero_kcal_estimated_item(): void
    {
        $user = $this->login($this->userWithProfile());
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 0), 'meal_type' => 'collation', 'title' => 'Reste de la veille']);

        $this->postJson('/api/planner/'.$plan->id.'/log')->assertOk()->assertJsonPath('data.status', 'realise');

        $item = MealItem::query()->firstOrFail();
        $this->assertSame('custom', $item->source_type);
        $this->assertSame('Reste de la veille', $item->label);
        $this->assertSame(0.0, $item->calories);
        $this->assertTrue($item->is_estimate);
    }

    public function test_log_food_plan_with_decrement_stock_uses_one_portion_and_decrements_stock(): void
    {
        $user = $this->login($this->userWithProfile());
        $food = Food::factory()->create(['name' => 'Yaourt nature', 'calories' => 60, 'proteins' => 4, 'carbs' => 5, 'fat' => 3, 'serving_size_g' => 125]);
        $stock = $this->personalStock($user);
        $stockItem = StockItem::factory()->create(['stock_id' => $stock->id, 'food_id' => $food->id, 'food_name' => 'Yaourt nature', 'quantity' => 4, 'unit' => 'piece', 'expires_at' => null]);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 0), 'meal_type' => 'collation', 'food_id' => $food->id, 'title' => 'Yaourt nature', 'servings' => 1]);

        $response = $this->postJson('/api/planner/'.$plan->id.'/log', ['decrement_stock' => true])->assertOk();

        $item = MealItem::query()->firstOrFail();
        $this->assertSame('food', $item->source_type);
        $this->assertSame($food->id, $item->food_id);
        $this->assertSame($stockItem->id, $item->stock_item_id);
        $this->assertSame(1.0, $item->quantity);
        $this->assertSame('portion', $item->unit);
        $this->assertSame(125.0, $item->grams_equivalent);
        $this->assertSame(75.0, $item->calories); // 60 kcal/100 g × 125 g
        $this->assertTrue($item->is_estimate);

        $response->assertJsonCount(1, 'stock_decrements');
        $response->assertJsonPath('stock_decrements.0.stock_item_id', $stockItem->id);
        $response->assertJsonPath('stock_decrements.0.previous_quantity', 4);
        $response->assertJsonPath('stock_decrements.0.new_quantity', 3);
        $response->assertJsonPath('stock_decrements.0.depleted', false);
        $this->assertDatabaseHas('stock_items', ['id' => $stockItem->id, 'quantity' => 3]);
    }

    public function test_log_recipe_plan_with_decrement_stock_decrements_matching_ingredients(): void
    {
        $user = $this->login($this->userWithProfile());
        $stock = $this->personalStock($user);
        StockItem::factory()->create(['stock_id' => $stock->id, 'food_name' => 'Riz basmati', 'quantity' => 500, 'unit' => 'g', 'expires_at' => null]);
        $untouched = StockItem::factory()->create(['stock_id' => $stock->id, 'food_name' => 'Lait', 'quantity' => 1, 'unit' => 'l', 'expires_at' => null]);

        $recipe = $this->publicRecipe(['calories' => 700, 'servings' => 2, 'ingredients' => [
            ['name' => 'Riz', 'ean' => null, 'amount' => 200, 'unit' => 'g'],
            ['name' => 'Poulet', 'ean' => null, 'amount' => 300, 'unit' => 'g'],
        ]]);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 0), 'meal_type' => 'dejeuner', 'recipe_id' => $recipe->id, 'title' => $recipe->title, 'servings' => 1]);

        $response = $this->postJson('/api/planner/'.$plan->id.'/log', ['decrement_stock' => true])->assertOk();

        // 1 portion sur 2 → 100 g de riz retirés ; le poulet absent du stock est ignoré.
        $response->assertJsonCount(1, 'stock_decrements');
        $response->assertJsonPath('stock_decrements.0.previous_quantity', 500);
        $response->assertJsonPath('stock_decrements.0.new_quantity', 400);
        $this->assertDatabaseHas('stock_items', ['id' => $untouched->id, 'quantity' => 1]);
    }

    public function test_log_without_decrement_leaves_stock_untouched(): void
    {
        $user = $this->login($this->userWithProfile());
        $food = Food::factory()->create(['calories' => 100]);
        $stock = $this->personalStock($user);
        $stockItem = StockItem::factory()->create(['stock_id' => $stock->id, 'food_id' => $food->id, 'quantity' => 2, 'unit' => 'piece']);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 0), 'meal_type' => 'collation', 'food_id' => $food->id, 'title' => $food->name]);

        $this->postJson('/api/planner/'.$plan->id.'/log', ['decrement_stock' => false])->assertOk()->assertJsonCount(0, 'stock_decrements');

        $this->assertDatabaseHas('stock_items', ['id' => $stockItem->id, 'quantity' => 2]);
        $this->assertNull(MealItem::query()->firstOrFail()->stock_item_id);
    }

    public function test_log_twice_is_rejected_with_422(): void
    {
        $user = $this->login($this->userWithProfile());
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $this->weekDay($user, 0), 'meal_type' => 'dejeuner', 'title' => 'Salade']);

        $this->postJson('/api/planner/'.$plan->id.'/log')->assertOk();

        $this->postJson('/api/planner/'.$plan->id.'/log')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Ce repas est déjà enregistré.');

        $this->assertSame(1, MealItem::query()->count());
    }

    public function test_log_reuses_existing_meal_of_same_date_and_type(): void
    {
        $user = $this->login($this->userWithProfile());
        $date = $this->weekDay($user, 0);
        $meal = Meal::factory()->create(['user_id' => $user->id, 'date' => $date, 'type' => 'dejeuner']);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'date' => $date, 'meal_type' => 'dejeuner', 'title' => 'Salade']);

        $this->postJson('/api/planner/'.$plan->id.'/log')->assertOk()->assertJsonPath('data.meal_id', $meal->id);

        $this->assertSame(1, Meal::query()->where('user_id', $user->id)->count());
    }

    public function test_log_validates_decrement_stock_and_foreign_plan_is_404(): void
    {
        $user = $this->login($this->userWithProfile());
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'title' => 'Salade']);

        $this->postJson('/api/planner/'.$plan->id.'/log', ['decrement_stock' => 'oui'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decrement_stock']);

        $foreign = MealPlan::factory()->create();
        $this->postJson('/api/planner/'.$foreign->id.'/log')->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->assertDatabaseHas('meal_plans', ['id' => $foreign->id, 'status' => 'prevu']);
    }

    public function test_household_member_can_log_a_household_plan_into_their_own_day(): void
    {
        $owner = User::factory()->create();
        $member = $this->userWithProfile();
        $household = $this->household($owner, $member);
        $member = $this->login($member->fresh());

        $plan = MealPlan::factory()->create(['user_id' => $owner->id, 'household_id' => $household->id, 'date' => $this->weekDay($member, 0), 'meal_type' => 'diner', 'title' => 'Soupe de légumes maison et tartine de chèvre']);

        $this->postJson('/api/planner/'.$plan->id.'/log')->assertOk()->assertJsonPath('data.status', 'realise');

        $meal = Meal::query()->firstOrFail();
        $this->assertSame($member->id, $meal->user_id);
        $this->assertSame(380.0, MealItem::query()->firstOrFail()->calories);
    }
}
