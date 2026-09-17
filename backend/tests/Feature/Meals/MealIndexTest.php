<?php

namespace Tests\Feature\Meals;

use App\Enums\MealType;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Clock;

class MealIndexTest extends MealsTestCase
{
    public function test_index_shape_and_serialization_types(): void
    {
        $food = $this->makeFood();
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);

        $response = $this->getJson('/api/meals?date='.self::DATE);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertExactKeys(self::DAY_KEYS, $data, 'data');
        $this->assertExactKeys(self::MEAL_KEYS, $data['meals'][0], 'meal');
        $this->assertExactKeys(self::ITEM_KEYS, $data['meals'][0]['items'][0], 'item');
        $this->assertExactKeys(self::TOTALS_KEYS, $data['totals'], 'totals');
        $this->assertExactKeys(self::TOTALS_KEYS, $data['targets'], 'targets');
        $this->assertExactKeys(self::TOTALS_KEYS, $data['remaining'], 'remaining');
        $this->assertExactKeys(['calories_burned', 'calories_bonus', 'coefficient', 'explication', 'is_estimate'], $data['sport'], 'sport');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['meals'][0]['date']);
        $this->assertIsFloat($data['meals'][0]['items'][0]['quantity']);
        $this->assertIsFloat($data['meals'][0]['items'][0]['calories']);
        $this->assertIsBool($data['meals'][0]['items'][0]['is_estimate']);
        $this->assertIsFloat($data['totals']['calories']);
        $this->assertIsBool($data['totals']['is_partial']);
        $this->assertIsBool($data['sport']['is_estimate']);
        $this->assertIsInt($data['plancher_kcal']);
        $this->assertContains($data['next_meal_type'], MealType::values());

        $this->assertSame(self::TARGET_CALORIES, $data['targets']['calories']);
        $this->assertSame(self::TARGET_PROTEINS, $data['targets']['proteins']);
        $this->assertSame(1500, $data['plancher_kcal'], 'Plancher homme, maintenir = 1500 kcal.');
        $this->assertSame(250.0, $data['totals']['calories']);
        $this->assertSame(1750.0, $data['remaining']['calories']);
        $this->assertSame(130.0, $data['remaining']['proteins']);
    }

    public function test_remaining_adds_the_sport_bonus_of_completed_sessions(): void
    {
        $food = $this->makeFood();
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);

        WorkoutSession::factory()->completed(300)->create(['user_id' => $this->user->id, 'date' => self::DATE]);
        // Une séance seulement prévue ne compte pas.
        WorkoutSession::factory()->create(['user_id' => $this->user->id, 'date' => self::DATE, 'calories_burned' => 500]);
        // Une séance terminée un autre jour ne compte pas.
        WorkoutSession::factory()->completed(400)->create(['user_id' => $this->user->id, 'date' => '2026-09-09']);

        $response = $this->getJson('/api/meals?date='.self::DATE)->assertOk();

        $this->assertSame(300.0, $response->json('data.sport.calories_burned'));
        $this->assertSame(300, $response->json('data.sport.calories_bonus'));
        $this->assertSame(100, $response->json('data.sport.coefficient'));
        $this->assertTrue($response->json('data.sport.is_estimate'));
        $this->assertSame(2000 + 300 - 250.0, $response->json('data.remaining.calories'));
    }

    public function test_sport_bonus_respects_the_profile_coefficient(): void
    {
        $this->user->profile()->update(['sport_coef_calories' => 50]);
        WorkoutSession::factory()->completed(300)->create(['user_id' => $this->user->id, 'date' => self::DATE]);

        $response = $this->getJson('/api/meals?date='.self::DATE)->assertOk();

        $this->assertSame(150, $response->json('data.sport.calories_bonus'));
        $this->assertSame(2150.0, $response->json('data.remaining.calories'));
    }

    public function test_meals_are_ordered_by_default_hour(): void
    {
        foreach (['diner', 'petit_dejeuner', 'collation', 'dejeuner'] as $type) {
            Meal::factory()->on(self::DATE)->ofType($type)->create(['user_id' => $this->user->id]);
        }

        $types = collect($this->getJson('/api/meals?date='.self::DATE)->assertOk()->json('data.meals'))->pluck('type')->all();

        $this->assertSame(['petit_dejeuner', 'dejeuner', 'collation', 'diner'], $types);
    }

    public function test_index_only_returns_the_current_user_meals(): void
    {
        $other = User::factory()->create();
        $meal = Meal::factory()->on(self::DATE)->ofType('dejeuner')->create(['user_id' => $other->id]);
        MealItem::factory()->create(['meal_id' => $meal->id, 'calories' => 900]);

        $response = $this->getJson('/api/meals?date='.self::DATE)->assertOk();

        $this->assertSame([], $response->json('data.meals'));
        $this->assertSame(0.0, $response->json('data.totals.calories'));
    }

    public function test_index_defaults_to_today_in_the_user_timezone(): void
    {
        $response = $this->getJson('/api/meals')->assertOk();

        $this->assertSame(Clock::today($this->user), $response->json('data.date'));
    }

    public function test_index_rejects_an_invalid_date(): void
    {
        $this->getJson('/api/meals?date=hier')
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', 'Le champ date doit respecter le format Y-m-d.');
    }

    public function test_totals_extras_are_null_when_no_item_provides_them(): void
    {
        $this->postItem('collation', [
            'custom' => ['label' => 'Bonbon', 'calories' => 50, 'proteins' => 0, 'carbs' => 12, 'fat' => 0],
            'quantity' => 1,
            'unit' => 'piece',
        ])->assertStatus(201);

        $response = $this->getJson('/api/meals?date='.self::DATE)->assertOk();

        $this->assertNull($response->json('data.totals.fiber'));
        $this->assertNull($response->json('data.totals.sugar'));
        $this->assertNull($response->json('data.totals.salt'));
        $this->assertNull($response->json('data.targets.sugar'));
        $this->assertSame(50.0, $response->json('data.totals.calories'));
    }

    public function test_next_meal_type_skips_logged_types_on_a_past_day(): void
    {
        $food = $this->makeFood();
        foreach (['petit_dejeuner', 'dejeuner', 'collation'] as $type) {
            $this->postItem($type, ['food_id' => $food->id, 'quantity' => 10, 'unit' => 'g'])->assertStatus(201);
        }

        // Journée passée : toutes les fenêtres sont écoulées → dernier type non enregistré.
        $this->getJson('/api/meals?date='.self::DATE)->assertOk()->assertJsonPath('data.next_meal_type', 'diner');
    }

    public function test_targets_are_null_without_profile(): void
    {
        $bare = User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($bare);

        $response = $this->getJson('/api/meals?date='.self::DATE)->assertOk();

        $this->assertNull($response->json('data.targets.calories'));
        $this->assertNull($response->json('data.remaining.calories'));
        $this->assertSame(0, $response->json('data.plancher_kcal'));
    }
}
