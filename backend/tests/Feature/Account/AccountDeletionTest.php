<?php

namespace Tests\Feature\Account;

use App\Models\DailyTarget;
use App\Models\Food;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\NotificationRead;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Recommendation;
use App\Models\ShoppingItem;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WeightLog;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_password_is_422_and_nothing_is_deleted(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        Profile::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/account', ['password' => 'mauvais']);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertSame('Mot de passe incorrect.', $response->json('errors.password.0'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('profiles', ['user_id' => $user->id]);
    }

    public function test_missing_password_is_422_french(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/account', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('mot de passe', $response->json('errors.password.0'));
    }

    public function test_deleting_account_leaves_zero_orphan_rows_and_keeps_others_data(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $other = User::factory()->create();

        // --- données de l'utilisateur
        Profile::factory()->for($user)->create();
        UserSetting::factory()->for($user)->create();
        WeightLog::factory()->for($user)->count(2)->sequence(['date' => '2026-09-01'], ['date' => '2026-09-02'])->create();
        DailyTarget::factory()->for($user)->create();
        $meal = Meal::factory()->for($user)->create();
        MealItem::factory()->for($meal)->count(2)->create();
        $stock = Stock::factory()->for($user)->create(['name' => 'Frigo']);
        StockItem::factory()->for($stock)->count(2)->create();
        $privateRecipe = Recipe::factory()->privee()->create(['created_by_user_id' => $user->id]);
        $publicRecipe = Recipe::factory()->create(['created_by_user_id' => $user->id]);
        $food = Food::factory()->create(['created_by_user_id' => $user->id]);
        $user->favoriteFoods()->attach($food->id);
        $session = WorkoutSession::factory()->for($user)->create();
        WorkoutExercise::factory()->count(2)->create(['session_id' => $session->id]);
        SportPlan::factory()->for($user)->create(['sport_id' => null, 'sport_name' => 'Course à pied']);
        $customSport = Sport::factory()->custom($user)->create();
        Recommendation::factory()->for($user)->create();
        NotificationRead::factory()->create(['user_id' => $user->id]);
        ShoppingItem::factory()->for($user)->create();
        MealPlan::factory()->for($user)->create();
        $user->createToken('mobile');

        // --- données d'un autre utilisateur (doivent survivre)
        Profile::factory()->for($other)->create();
        $otherMeal = Meal::factory()->for($other)->create();
        MealItem::factory()->for($otherMeal)->create();
        $otherStock = Stock::factory()->for($other)->create(['name' => 'Placard']);
        StockItem::factory()->for($otherStock)->create();
        Recipe::factory()->privee()->create(['created_by_user_id' => $other->id]);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/account', ['password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $uid = $user->id;
        $this->assertDatabaseMissing('users', ['id' => $uid]);
        $this->assertSame(0, Profile::where('user_id', $uid)->count());
        $this->assertSame(0, UserSetting::where('user_id', $uid)->count());
        $this->assertSame(0, WeightLog::where('user_id', $uid)->count());
        $this->assertSame(0, DailyTarget::where('user_id', $uid)->count());
        $this->assertSame(0, Meal::where('user_id', $uid)->count());
        $this->assertSame(0, MealItem::where('meal_id', $meal->id)->count());
        $this->assertSame(0, Stock::where('user_id', $uid)->count());
        $this->assertSame(0, StockItem::where('stock_id', $stock->id)->count());
        $this->assertSame(0, Recipe::where('id', $privateRecipe->id)->count());
        $this->assertDatabaseHas('recipes', ['id' => $publicRecipe->id, 'created_by_user_id' => null]);
        $this->assertDatabaseHas('food', ['id' => $food->id, 'created_by_user_id' => null]);
        $this->assertSame(0, DB::table('food_favorites')->where('user_id', $uid)->count());
        $this->assertSame(0, WorkoutSession::where('user_id', $uid)->count());
        $this->assertSame(0, WorkoutExercise::where('session_id', $session->id)->count());
        $this->assertSame(0, SportPlan::where('user_id', $uid)->count());
        $this->assertSame(0, Sport::where('id', $customSport->id)->count());
        $this->assertSame(0, Recommendation::where('user_id', $uid)->count());
        $this->assertSame(0, NotificationRead::where('user_id', $uid)->count());
        $this->assertSame(0, ShoppingItem::where('user_id', $uid)->count());
        $this->assertSame(0, MealPlan::where('user_id', $uid)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $uid)->count());

        // L'autre utilisateur est intact.
        $this->assertSame(1, Profile::where('user_id', $other->id)->count());
        $this->assertSame(1, Meal::where('user_id', $other->id)->count());
        $this->assertSame(1, MealItem::where('meal_id', $otherMeal->id)->count());
        $this->assertSame(1, StockItem::where('stock_id', $otherStock->id)->count());
        $this->assertSame(1, Recipe::where('created_by_user_id', $other->id)->count());
    }

    public function test_owner_with_members_transfers_household_to_earliest_member(): void
    {
        $owner = User::factory()->create(['password' => bcrypt('secret123')]);
        $household = Household::factory()->create(['owner_id' => $owner->id]);
        HouseholdMember::factory()->owner()->create(['household_id' => $household->id, 'user_id' => $owner->id, 'joined_at' => now()->subDays(10)]);

        $late = User::factory()->create();
        HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $late->id, 'joined_at' => now()->subDay()]);
        $early = User::factory()->create();
        HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $early->id, 'joined_at' => now()->subDays(5)]);

        foreach ([$owner, $late, $early] as $u) {
            $u->forceFill(['household_id' => $household->id])->save();
        }

        $sharedStock = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        StockItem::factory()->for($sharedStock)->create();
        $sharedPlan = MealPlan::factory()->create(['user_id' => $owner->id, 'household_id' => $household->id]);
        $sharedShopping = ShoppingItem::factory()->create(['user_id' => $owner->id, 'household_id' => $household->id]);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/account', ['password' => 'secret123'])->assertOk();

        $this->assertDatabaseHas('households', ['id' => $household->id, 'owner_id' => $early->id]);
        $this->assertDatabaseHas('household_members', ['user_id' => $early->id, 'role' => 'proprietaire']);
        $this->assertDatabaseHas('household_members', ['user_id' => $late->id, 'role' => 'membre']);
        $this->assertDatabaseMissing('household_members', ['user_id' => $owner->id]);
        $this->assertSame(2, HouseholdMember::where('household_id', $household->id)->count());

        // Le foyer garde ses stocks, plans et courses ; les lignes de l'ancien propriétaire sont réattribuées.
        $this->assertDatabaseHas('stocks', ['id' => $sharedStock->id, 'household_id' => $household->id]);
        $this->assertSame(1, StockItem::where('stock_id', $sharedStock->id)->count());
        $this->assertDatabaseHas('meal_plans', ['id' => $sharedPlan->id, 'household_id' => $household->id, 'user_id' => $early->id]);
        $this->assertDatabaseHas('shopping_items', ['id' => $sharedShopping->id, 'household_id' => $household->id, 'user_id' => $early->id]);
    }

    public function test_owner_alone_dissolves_household(): void
    {
        $owner = User::factory()->create(['password' => bcrypt('secret123')]);
        $household = Household::factory()->create(['owner_id' => $owner->id]);
        HouseholdMember::factory()->owner()->create(['household_id' => $household->id, 'user_id' => $owner->id]);
        $owner->forceFill(['household_id' => $household->id])->save();

        $sharedStock = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        StockItem::factory()->for($sharedStock)->create();
        MealPlan::factory()->create(['user_id' => $owner->id, 'household_id' => $household->id]);
        ShoppingItem::factory()->create(['user_id' => $owner->id, 'household_id' => $household->id]);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/account', ['password' => 'secret123'])->assertOk();

        $this->assertDatabaseMissing('households', ['id' => $household->id]);
        $this->assertSame(0, HouseholdMember::where('household_id', $household->id)->count());
        $this->assertSame(0, Stock::where('household_id', $household->id)->count());
        $this->assertSame(0, StockItem::where('stock_id', $sharedStock->id)->count());
        $this->assertSame(0, MealPlan::where('household_id', $household->id)->count());
        $this->assertSame(0, ShoppingItem::where('household_id', $household->id)->count());
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    }

    public function test_member_leaves_household_and_takes_nothing(): void
    {
        $owner = User::factory()->create();
        $household = Household::factory()->create(['owner_id' => $owner->id]);
        HouseholdMember::factory()->owner()->create(['household_id' => $household->id, 'user_id' => $owner->id]);
        $member = User::factory()->create(['password' => bcrypt('secret123')]);
        HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $member->id]);
        $owner->forceFill(['household_id' => $household->id])->save();
        $member->forceFill(['household_id' => $household->id])->save();

        $sharedStock = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        StockItem::factory()->for($sharedStock)->create();
        $memberPlan = MealPlan::factory()->create(['user_id' => $member->id, 'household_id' => $household->id]);

        Sanctum::actingAs($member);
        $this->deleteJson('/api/account', ['password' => 'secret123'])->assertOk();

        $this->assertDatabaseHas('households', ['id' => $household->id, 'owner_id' => $owner->id]);
        $this->assertDatabaseMissing('household_members', ['user_id' => $member->id]);
        $this->assertDatabaseHas('household_members', ['user_id' => $owner->id, 'role' => 'proprietaire']);
        $this->assertSame(1, StockItem::where('stock_id', $sharedStock->id)->count());
        $this->assertDatabaseHas('meal_plans', ['id' => $memberPlan->id, 'user_id' => $owner->id, 'household_id' => $household->id]);
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }
}
