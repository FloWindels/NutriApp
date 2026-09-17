<?php

namespace Tests\Feature\Meals;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;

class MealFrequentTest extends MealsTestCase
{
    public const ROW_KEYS = ['kind', 'id', 'label', 'brand', 'last_quantity', 'last_unit', 'calories_per_100g', 'calories_per_serving', 'count'];

    private function recentDate(int $daysAgo): string
    {
        return CarbonImmutable::parse(Clock::today($this->user))->subDays($daysAgo)->toDateString();
    }

    public function test_frequent_orders_by_count_and_exposes_last_quantity(): void
    {
        $foodA = $this->makeFood(['name' => 'Riz basmati', 'brand' => 'Uncle', 'barcode' => '3000000000024', 'calories' => 130]);
        $foodB = $this->makeFood(['name' => 'Pomme', 'brand' => null, 'barcode' => null, 'calories' => 52]);
        $recipe = $this->makeRecipe();

        $this->postItem('dejeuner', ['food_id' => $foodA->id, 'quantity' => 100, 'unit' => 'g'], $this->recentDate(5))->assertStatus(201);
        $this->postItem('diner', ['food_id' => $foodA->id, 'quantity' => 80, 'unit' => 'g'], $this->recentDate(3))->assertStatus(201);
        $this->postItem('dejeuner', ['food_id' => $foodA->id, 'quantity' => 150, 'unit' => 'g'], $this->recentDate(1))->assertStatus(201);
        $this->postItem('diner', ['recipe_id' => $recipe->id, 'quantity' => 1, 'unit' => 'portion'], $this->recentDate(4))->assertStatus(201);
        $this->postItem('diner', ['recipe_id' => $recipe->id, 'quantity' => 2, 'unit' => 'portion'], $this->recentDate(2))->assertStatus(201);
        $this->postItem('collation', ['food_id' => $foodB->id, 'quantity' => 1, 'unit' => 'piece'], $this->recentDate(6))->assertStatus(201);

        $response = $this->getJson('/api/meals/frequent')->assertOk();
        $rows = $response->json('data');

        $this->assertExactKeys(['data'], $response->json());
        $this->assertCount(3, $rows);
        $this->assertExactKeys(self::ROW_KEYS, $rows[0], 'frequent row');

        $this->assertSame(['food', 'recipe', 'food'], array_column($rows, 'kind'));
        $this->assertSame([3, 2, 1], array_column($rows, 'count'));

        $this->assertSame($foodA->id, $rows[0]['id']);
        $this->assertSame('Riz basmati', $rows[0]['label']);
        $this->assertSame('Uncle', $rows[0]['brand']);
        $this->assertSame(150.0, $rows[0]['last_quantity']);
        $this->assertSame('g', $rows[0]['last_unit']);
        $this->assertSame(130.0, $rows[0]['calories_per_100g']);
        $this->assertNull($rows[0]['calories_per_serving']);

        $this->assertSame($recipe->id, $rows[1]['id']);
        $this->assertSame('Poulet au curry', $rows[1]['label']);
        $this->assertNull($rows[1]['brand']);
        $this->assertSame(2.0, $rows[1]['last_quantity']);
        $this->assertSame('portion', $rows[1]['last_unit']);
        $this->assertNull($rows[1]['calories_per_100g']);
        $this->assertSame(200.0, $rows[1]['calories_per_serving']);

        $this->assertSame($foodB->id, $rows[2]['id']);
        $this->assertSame('piece', $rows[2]['last_unit']);
        $this->assertSame(52.0, $rows[2]['calories_per_100g']);
        $this->assertIsInt($rows[2]['count']);
    }

    public function test_frequent_ignores_items_older_than_60_days_and_custom_items(): void
    {
        $old = $this->makeFood(['name' => 'Vieux', 'barcode' => '3000000000031']);
        $recent = $this->makeFood(['name' => 'Récent', 'barcode' => '3000000000048']);

        foreach ([61, 70, 90] as $daysAgo) {
            $this->postItem('dejeuner', ['food_id' => $old->id, 'quantity' => 100, 'unit' => 'g'], $this->recentDate($daysAgo))->assertStatus(201);
        }
        $this->postItem('dejeuner', ['food_id' => $recent->id, 'quantity' => 100, 'unit' => 'g'], $this->recentDate(60))->assertStatus(201);
        $this->postItem('collation', ['custom' => ['label' => 'Perso', 'calories' => 10, 'proteins' => 0, 'carbs' => 2, 'fat' => 0], 'quantity' => 1, 'unit' => 'g'], $this->recentDate(1))->assertStatus(201);

        $rows = $this->getJson('/api/meals/frequent')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($recent->id, $rows[0]['id']);
        $this->assertSame(1, $rows[0]['count']);
    }

    public function test_frequent_is_limited_to_12_rows(): void
    {
        $meal = Meal::factory()->on($this->recentDate(1))->ofType('dejeuner')->create(['user_id' => $this->user->id]);

        for ($i = 0; $i < 14; $i++) {
            $food = Food::factory()->create(['barcode' => null, 'name' => "Aliment $i"]);
            MealItem::factory()->forFood($food)->create(['meal_id' => $meal->id]);
        }

        $this->getJson('/api/meals/frequent')->assertOk()->assertJsonCount(12, 'data');
    }

    public function test_frequent_only_counts_the_current_user(): void
    {
        $food = $this->makeFood();
        $other = User::factory()->create();
        $meal = Meal::factory()->on($this->recentDate(1))->ofType('dejeuner')->create(['user_id' => $other->id]);
        MealItem::factory()->forFood($food)->create(['meal_id' => $meal->id]);

        $this->getJson('/api/meals/frequent')->assertOk()->assertJsonPath('data', []);
    }

    public function test_frequent_falls_back_to_the_snapshot_when_the_food_changed(): void
    {
        $food = $this->makeFood(['calories' => 250]);
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'], $this->recentDate(1))->assertStatus(201);

        $food->update(['calories' => 300, 'name' => 'Poulet rôti (mis à jour)']);

        $row = $this->getJson('/api/meals/frequent')->assertOk()->json('data.0');

        $this->assertSame(300.0, $row['calories_per_100g'], 'Les valeurs actuelles de l’aliment sont privilégiées pour un futur ajout.');
        $this->assertSame('Poulet rôti (mis à jour)', $row['label']);
    }
}
