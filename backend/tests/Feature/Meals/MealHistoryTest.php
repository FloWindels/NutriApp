<?php

namespace Tests\Feature\Meals;

use App\Models\DailyTarget;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Clock;
use Carbon\CarbonImmutable;

class MealHistoryTest extends MealsTestCase
{
    public const ROW_KEYS = [
        'date', 'calories', 'proteins', 'carbs', 'fat',
        'target_calories', 'target_proteins', 'target_carbs', 'target_fat',
        'meals_count', 'sport_minutes', 'calories_burned',
    ];

    public function test_history_returns_one_row_per_day_with_totals_and_meals_count(): void
    {
        $food = $this->makeFood();
        $this->postItem('petit_dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'], '2026-09-08')->assertStatus(201);
        $this->postItem('diner', ['food_id' => $food->id, 'quantity' => 200, 'unit' => 'g'], '2026-09-08')->assertStatus(201);
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 50, 'unit' => 'g'], '2026-09-10')->assertStatus(201);
        // Repas vide : ne compte pas comme repas enregistré.
        Meal::factory()->on('2026-09-09')->ofType('collation')->create(['user_id' => $this->user->id]);
        // Hors période.
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 500, 'unit' => 'g'], '2026-09-11')->assertStatus(201);

        $response = $this->getJson('/api/meals/history?from=2026-09-08&to=2026-09-10');

        $response->assertOk()->assertJsonPath('from', '2026-09-08')->assertJsonPath('to', '2026-09-10');
        $this->assertExactKeys(['data', 'from', 'to'], $response->json());
        $rows = $response->json('data');
        $this->assertCount(3, $rows);
        $this->assertExactKeys(self::ROW_KEYS, $rows[0], 'history row');

        $this->assertSame(['2026-09-08', '2026-09-09', '2026-09-10'], array_column($rows, 'date'));

        $this->assertSame(750.0, $rows[0]['calories']);
        $this->assertSame(60.0, $rows[0]['proteins']);
        $this->assertSame(90.0, $rows[0]['carbs']);
        $this->assertSame(15.0, $rows[0]['fat']);
        $this->assertSame(2, $rows[0]['meals_count']);

        $this->assertSame(0.0, $rows[1]['calories']);
        $this->assertSame(0, $rows[1]['meals_count']);

        $this->assertSame(125.0, $rows[2]['calories']);
        $this->assertSame(1, $rows[2]['meals_count']);
    }

    public function test_history_targets_come_from_daily_targets_with_current_fallback(): void
    {
        $food = $this->makeFood();
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'], '2026-09-08')->assertStatus(201);
        DailyTarget::query()->where('user_id', $this->user->id)->where('date', '2026-09-08')->update(['calories' => 1800, 'proteins' => 120, 'carbs' => 180, 'fat' => 60]);

        $rows = $this->getJson('/api/meals/history?from=2026-09-08&to=2026-09-09')->assertOk()->json('data');

        $this->assertSame(1800, $rows[0]['target_calories']);
        $this->assertSame(120, $rows[0]['target_proteins']);
        $this->assertSame(180, $rows[0]['target_carbs']);
        $this->assertSame(60, $rows[0]['target_fat']);

        $this->assertSame(self::TARGET_CALORIES, $rows[1]['target_calories'], 'Sans cible figée : cibles effectives actuelles.');
        $this->assertSame(self::TARGET_PROTEINS, $rows[1]['target_proteins']);
        $this->assertSame(self::TARGET_CARBS, $rows[1]['target_carbs']);
        $this->assertSame(self::TARGET_FAT, $rows[1]['target_fat']);
    }

    public function test_history_sums_completed_sessions_only(): void
    {
        WorkoutSession::factory()->completed(300)->create(['user_id' => $this->user->id, 'date' => '2026-09-08', 'duration_min' => 30]);
        WorkoutSession::factory()->completed(150)->create(['user_id' => $this->user->id, 'date' => '2026-09-08', 'duration_min' => 20]);
        WorkoutSession::factory()->create(['user_id' => $this->user->id, 'date' => '2026-09-08', 'duration_min' => 60, 'calories_burned' => 999]);
        WorkoutSession::factory()->completed(100)->create(['user_id' => $this->user->id, 'date' => '2026-09-09', 'duration_min' => 10]);

        $rows = $this->getJson('/api/meals/history?from=2026-09-08&to=2026-09-09')->assertOk()->json('data');

        $this->assertSame(50, $rows[0]['sport_minutes']);
        $this->assertSame(450.0, $rows[0]['calories_burned']);
        $this->assertSame(10, $rows[1]['sport_minutes']);
        $this->assertSame(100.0, $rows[1]['calories_burned']);
    }

    public function test_history_period_is_capped_at_92_days(): void
    {
        $this->getJson('/api/meals/history?from=2026-06-01&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'La période ne peut pas dépasser 92 jours.');

        $this->getJson('/api/meals/history?from=2026-06-02&to=2026-09-01')
            ->assertOk()
            ->assertJsonCount(92, 'data');
    }

    public function test_history_defaults_to_the_last_30_days(): void
    {
        $today = Clock::today($this->user);

        $response = $this->getJson('/api/meals/history')->assertOk();

        $this->assertSame($today, $response->json('to'));
        $this->assertSame(CarbonImmutable::parse($today)->subDays(29)->toDateString(), $response->json('from'));
        $this->assertCount(30, $response->json('data'));
        $this->assertSame($today, $response->json('data.29.date'));
    }

    public function test_history_validation(): void
    {
        $this->getJson('/api/meals/history?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'La date de fin doit être postérieure ou égale à la date de début.');

        $this->getJson('/api/meals/history?from=01-09-2026')
            ->assertStatus(422)
            ->assertJsonPath('errors.from.0', 'Le champ date de début doit respecter le format Y-m-d.');
    }

    public function test_history_ignores_other_users(): void
    {
        $other = User::factory()->create();
        $meal = Meal::factory()->on('2026-09-08')->ofType('dejeuner')->create(['user_id' => $other->id]);
        MealItem::factory()->create(['meal_id' => $meal->id, 'calories' => 900]);
        WorkoutSession::factory()->completed(300)->create(['user_id' => $other->id, 'date' => '2026-09-08']);
        DailyTarget::factory()->create(['user_id' => $other->id, 'date' => '2026-09-08', 'calories' => 1234]);

        $row = $this->getJson('/api/meals/history?from=2026-09-08&to=2026-09-08')->assertOk()->json('data.0');

        $this->assertSame(0.0, $row['calories']);
        $this->assertSame(0, $row['meals_count']);
        $this->assertSame(0, $row['sport_minutes']);
        $this->assertSame(0.0, $row['calories_burned']);
        $this->assertSame(self::TARGET_CALORIES, $row['target_calories']);
    }

    public function test_history_serialization_types(): void
    {
        $food = $this->makeFood();
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'], '2026-09-08')->assertStatus(201);

        $row = $this->getJson('/api/meals/history?from=2026-09-08&to=2026-09-08')->assertOk()->json('data.0');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['date']);
        $this->assertIsFloat($row['calories']);
        $this->assertIsFloat($row['proteins']);
        $this->assertIsFloat($row['calories_burned']);
        $this->assertIsInt($row['target_calories']);
        $this->assertIsInt($row['meals_count']);
        $this->assertIsInt($row['sport_minutes']);
    }

    public function test_history_targets_are_null_without_profile(): void
    {
        $bare = User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($bare);

        $row = $this->getJson('/api/meals/history?from=2026-09-08&to=2026-09-08')->assertOk()->json('data.0');

        $this->assertNull($row['target_calories']);
        $this->assertNull($row['target_fat']);
    }
}
