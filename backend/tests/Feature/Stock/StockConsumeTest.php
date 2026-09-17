<?php

namespace Tests\Feature\Stock;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\ShoppingItem;
use App\Models\StockItem;
use App\Models\User;

class StockConsumeTest extends StockTestCase
{
    private function foodItem(User $user, float $quantity = 500, string $unit = 'g', array $food = []): StockItem
    {
        $stock = $this->personalStock($user, 'Frigo');
        $foodModel = Food::factory()->create($food + [
            'name' => 'Fromage blanc',
            'calories' => 100,
            'proteins' => 8,
            'carbs' => 4,
            'fat' => 3,
        ]);

        return StockItem::factory()->create([
            'stock_id' => $stock->id,
            'food_id' => $foodModel->id,
            'food_name' => $foodModel->name,
            'quantity' => $quantity,
            'unit' => $unit,
            'expires_at' => $this->daysFromToday(5),
        ]);
    }

    public function test_consume_decrements_the_stock_and_adds_a_meal_item(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user);

        $response = $this->postJson("/api/stocks/items/{$item->id}/consume", [
            'quantity' => 200,
            'unit' => 'g',
            'meal_type' => 'dejeuner',
            'date' => self::TODAY,
        ])->assertCreated()->assertJson(['message' => 'Consommation enregistrée.']);

        $this->assertSame(['message', 'data'], array_keys($response->json()));
        $this->assertSame(['meal_item', 'stock_item'], array_keys($response->json('data')));
        $this->assertSame(['id', 'meal_id', 'calories'], array_keys($response->json('data.meal_item')));
        $this->assertSame(['id', 'quantity', 'previous_quantity', 'depleted'], array_keys($response->json('data.stock_item')));

        $stockPayload = $response->json('data.stock_item');
        $this->assertSame($item->id, $stockPayload['id']);
        $this->assertSame(300.0, $stockPayload['quantity']);
        $this->assertSame(500.0, $stockPayload['previous_quantity']);
        $this->assertFalse($stockPayload['depleted']);

        $mealPayload = $response->json('data.meal_item');
        $this->assertIsInt($mealPayload['id']);
        $this->assertSame(200.0, $mealPayload['calories']);

        $meal = Meal::query()->findOrFail($mealPayload['meal_id']);
        $this->assertSame($user->id, $meal->user_id);
        $this->assertSame(self::TODAY, $meal->date->format('Y-m-d'));
        $this->assertSame('dejeuner', $meal->type->value);

        $mealItem = MealItem::query()->findOrFail($mealPayload['id']);
        $this->assertSame($item->food_id, $mealItem->food_id);
        $this->assertSame($item->id, $mealItem->stock_item_id);
        $this->assertSame(200.0, $mealItem->quantity);
        $this->assertSame('g', $mealItem->unit);
        $this->assertSame(16.0, $mealItem->proteins);

        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'quantity' => 300, 'depleted_at' => null]);
        $this->assertSame(0, ShoppingItem::query()->count());
    }

    public function test_consuming_everything_depletes_the_item_and_queues_a_shopping_item(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user, 250, 'g');

        $response = $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 250, 'unit' => 'g'])
            ->assertCreated();

        $this->assertStringContainsString('épuisé', $response->json('message'));
        $this->assertSame(0.0, $response->json('data.stock_item.quantity'));
        $this->assertTrue($response->json('data.stock_item.depleted'));
        $this->assertNotNull($response->json('data.meal_item'));

        $item->refresh();
        $this->assertSame(0.0, $item->quantity);
        $this->assertNotNull($item->depleted_at);
        // L'article n'est jamais supprimé.
        $this->assertDatabaseHas('stock_items', ['id' => $item->id]);

        $this->assertDatabaseHas('shopping_items', [
            'user_id' => $user->id,
            'household_id' => null,
            'food_id' => $item->food_id,
            'label' => 'Fromage blanc',
            'source' => 'auto_stock',
            'checked' => false,
        ]);

        // Épuisé : impossible de consommer davantage.
        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 1, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Quantité supérieure au stock.');

        // Il disparaît de la liste par défaut, réapparaît avec include_depleted.
        $this->assertCount(0, $this->getJson('/api/stocks')->json('data'));
        $this->assertCount(1, $this->getJson('/api/stocks?include_depleted=1')->json('data'));
    }

    public function test_consume_more_than_the_stock_is_rejected_without_side_effects(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user, 100, 'g');

        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 100.5, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Quantité supérieure au stock.')
            ->assertJsonPath('message', 'Quantité supérieure au stock.');

        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'quantity' => 100]);
        $this->assertSame(0, Meal::query()->count());
        $this->assertSame(0, MealItem::query()->count());
    }

    public function test_consume_converts_units_before_comparing_and_decrementing(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user, 1000, 'g');

        // 0,5 kg = 500 g ≤ 1000 g.
        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 0.5, 'unit' => 'kg', 'add_to_meal' => false])
            ->assertCreated()
            ->assertJsonPath('data.stock_item.quantity', 500.0);

        // 0,6 kg = 600 g > 500 g restants.
        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 0.6, 'unit' => 'kg', 'add_to_meal' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Quantité supérieure au stock.');
    }

    public function test_consume_defaults_to_the_stock_unit_when_unit_is_omitted(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user, 3, 'piece', ['serving_size_g' => 50]);

        $response = $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 2])->assertCreated();

        $this->assertSame(1.0, $response->json('data.stock_item.quantity'));
        // 2 pièces × 50 g × 100 kcal/100 g = 100 kcal.
        $this->assertSame(100.0, $response->json('data.meal_item.calories'));
    }

    public function test_consume_without_food_id_only_decrements_the_stock(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        $item = StockItem::factory()->create(['stock_id' => $stock->id, 'food_id' => null, 'food_name' => 'Article maison', 'quantity' => 3, 'unit' => 'piece']);

        $response = $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 1, 'unit' => 'piece', 'meal_type' => 'diner'])
            ->assertCreated();

        $this->assertNull($response->json('data.meal_item'));
        $this->assertSame(2.0, $response->json('data.stock_item.quantity'));
        $this->assertSame(3.0, $response->json('data.stock_item.previous_quantity'));
        $this->assertSame(0, Meal::query()->count());
        $this->assertSame(0, MealItem::query()->count());
    }

    public function test_consume_with_add_to_meal_false_does_not_create_a_meal(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user);

        $response = $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 100, 'unit' => 'g', 'add_to_meal' => false])
            ->assertCreated();

        $this->assertNull($response->json('data.meal_item'));
        $this->assertSame(400.0, $response->json('data.stock_item.quantity'));
        $this->assertSame(0, Meal::query()->count());
    }

    public function test_consume_defaults_date_to_today_and_picks_a_meal_type(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user);

        $response = $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 50, 'unit' => 'g'])->assertCreated();

        $meal = Meal::query()->findOrFail($response->json('data.meal_item.meal_id'));
        $this->assertSame(self::TODAY, $meal->date->format('Y-m-d'));
        // 14 h à Paris : le déjeuner (12 h 30 + 2 h 30 de fenêtre) n'est pas encore enregistré.
        $this->assertSame('dejeuner', $meal->type->value);
    }

    public function test_consume_reuses_an_existing_meal_of_the_same_day_and_type(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user);
        $existing = Meal::factory()->create(['user_id' => $user->id, 'date' => self::TODAY, 'type' => 'dejeuner']);

        $response = $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 50, 'unit' => 'g', 'meal_type' => 'dejeuner', 'date' => self::TODAY])
            ->assertCreated();

        $this->assertSame($existing->id, $response->json('data.meal_item.meal_id'));
        $this->assertSame(1, Meal::query()->where('user_id', $user->id)->count());
    }

    public function test_undo_by_restoring_the_quantity_clears_the_depleted_state(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user, 2, 'piece');

        $consume = $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 2, 'unit' => 'piece'])->assertCreated();
        $this->assertTrue($consume->json('data.stock_item.depleted'));
        $this->assertCount(0, $this->getJson('/api/stocks')->json('data'));

        // Annulation côté client : suppression de l'élément de repas + remise de la quantité.
        $this->assertNotNull($consume->json('data.meal_item.id'));
        $restored = $this->putJson("/api/stocks/items/{$item->id}", ['quantity' => 2])->assertOk()->json('data');

        $this->assertSame(2.0, $restored['quantity']);
        $this->assertFalse($restored['is_depleted']);
        $this->assertNull($restored['depleted_at']);
        $this->assertCount(1, $this->getJson('/api/stocks')->json('data'));
    }

    public function test_consume_validation_is_french(): void
    {
        $user = $this->actingAsUser();
        $item = $this->foodItem($user);

        $missing = $this->postJson("/api/stocks/items/{$item->id}/consume", [])->assertStatus(422);
        $this->assertSame('Le champ quantité est obligatoire.', $missing->json('errors.quantity.0'));

        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 1, 'meal_type' => 'brunch', 'date' => '16/09/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meal_type', 'date']);
    }

    public function test_consume_unknown_item_is_not_found(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/stocks/items/999999/consume', ['quantity' => 1])
            ->assertNotFound()
            ->assertJson(['message' => 'Introuvable.']);
    }
}
