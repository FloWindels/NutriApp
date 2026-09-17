<?php

namespace Tests\Feature\Meals;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\User;

class MealItemTest extends MealsTestCase
{
    private function createMeal(string $type = 'dejeuner', string $date = self::DATE): Meal
    {
        return Meal::factory()->on($date)->ofType($type)->create(['user_id' => $this->user->id]);
    }

    public function test_store_item_returns_201_with_the_expected_shape(): void
    {
        $meal = $this->createMeal();
        $food = $this->makeFood();

        $response = $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 200, 'unit' => 'g']);

        $response->assertStatus(201)->assertJsonPath('message', 'Élément ajouté au repas.');
        $this->assertExactKeys(['message', 'data', 'day', 'stock_decrement'], $response->json());
        $this->assertExactKeys(self::ITEM_KEYS, $response->json('data'), 'MealItemResource');
        $this->assertExactKeys(self::DAY_KEYS, $response->json('day'), 'day');

        $this->assertSame($meal->id, $response->json('data.meal_id'));
        $this->assertSame(500.0, $response->json('data.calories'));
        $this->assertNull($response->json('stock_decrement'));
        $this->assertSame(500.0, $response->json('day.totals.calories'));
        $this->assertSame($food->id, $response->json('data.food.id'));
        $this->assertSame('3000000000017', $response->json('data.food.barcode'));
    }

    public function test_store_item_validates_exactly_one_source(): void
    {
        $meal = $this->createMeal();

        $this->postJson("/api/meals/{$meal->id}/items", ['quantity' => 10, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonPath('errors.food_id.0', 'Indique un aliment, une recette ou un aliment personnalisé (une seule source).');
    }

    public function test_update_item_recomputes_from_refs_even_after_the_food_changed(): void
    {
        $meal = $this->createMeal();
        $food = $this->makeFood();
        $itemId = $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])
            ->assertStatus(201)->json('data.id');

        $food->update(['calories' => 999, 'proteins' => 99]);

        $response = $this->putJson("/api/meals/{$meal->id}/items/{$itemId}", ['quantity' => 200, 'unit' => 'g']);

        $response->assertOk()->assertJsonPath('message', 'Quantité mise à jour.');
        $this->assertExactKeys(['message', 'data', 'day'], $response->json());
        $this->assertSame(200.0, $response->json('data.quantity'));
        $this->assertSame(500.0, $response->json('data.calories'), 'Le recalcul part des ref_* figées (250 kcal/100 g), pas de l’aliment modifié.');
        $this->assertSame(40.0, $response->json('data.proteins'));
        $this->assertSame(200.0, $response->json('data.grams_equivalent'));
        $this->assertSame(500.0, $response->json('day.totals.calories'));
    }

    public function test_update_item_still_works_after_the_food_was_deleted(): void
    {
        $meal = $this->createMeal();
        $food = $this->makeFood(['serving_size_g' => 50]);
        $itemId = $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])
            ->assertStatus(201)->json('data.id');

        $food->delete();
        $this->assertNull(MealItem::query()->findOrFail($itemId)->food_id, 'FK nullOnDelete.');

        $response = $this->putJson("/api/meals/{$meal->id}/items/{$itemId}", ['quantity' => 2, 'unit' => 'portion'])->assertOk();

        $this->assertNull($response->json('data.food_id'));
        $this->assertNull($response->json('data.food'));
        $this->assertSame('portion', $response->json('data.unit'));
        $this->assertSame(100.0, $response->json('data.grams_equivalent'), 'ref_serving_size_g (50 g) figé sur l’élément.');
        $this->assertSame(250.0, $response->json('data.calories'));
        $this->assertTrue($response->json('data.is_estimate'));
    }

    public function test_update_item_in_household_units_flags_estimate(): void
    {
        $meal = $this->createMeal();
        $food = $this->makeFood();
        $itemId = $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])
            ->assertStatus(201)->json('data.id');

        $response = $this->putJson("/api/meals/{$meal->id}/items/{$itemId}", ['quantity' => 2, 'unit' => 'cas'])->assertOk();

        $this->assertSame('cas', $response->json('data.unit'));
        $this->assertSame(30.0, $response->json('data.grams_equivalent'));
        $this->assertSame(75.0, $response->json('data.calories'));
        $this->assertTrue($response->json('data.is_estimate'));
    }

    public function test_update_recipe_item_always_counts_in_portions(): void
    {
        $meal = $this->createMeal('diner');
        $recipe = $this->makeRecipe();
        $itemId = $this->postJson("/api/meals/{$meal->id}/items", ['recipe_id' => $recipe->id, 'quantity' => 1, 'unit' => 'portion'])
            ->assertStatus(201)->json('data.id');

        $recipe->update(['calories' => 4000]);

        $response = $this->putJson("/api/meals/{$meal->id}/items/{$itemId}", ['quantity' => 2, 'unit' => 'g'])->assertOk();

        $this->assertSame('portion', $response->json('data.unit'));
        $this->assertSame(2.0, $response->json('data.quantity'));
        $this->assertSame(400.0, $response->json('data.calories'));
        $this->assertNull($response->json('data.grams_equivalent'));
    }

    public function test_update_item_validation_is_french(): void
    {
        $meal = $this->createMeal();
        $food = $this->makeFood();
        $itemId = $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->json('data.id');

        $this->putJson("/api/meals/{$meal->id}/items/{$itemId}", ['quantity' => -1, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Le champ quantité doit être supérieur à 0.');

        $this->putJson("/api/meals/{$meal->id}/items/{$itemId}", ['quantity' => 1, 'unit' => 'boîte'])
            ->assertStatus(422)
            ->assertJsonPath('errors.unit.0', 'L’unité « boîte » n’est pas reconnue.');

        $this->putJson("/api/meals/{$meal->id}/items/{$itemId}", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Le champ quantité est obligatoire.')
            ->assertJsonPath('errors.unit.0', 'Le champ unité est obligatoire.');
    }

    public function test_delete_item_recomputes_the_day_totals(): void
    {
        $meal = $this->createMeal();
        $food = $this->makeFood();
        $first = $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->json('data.id');
        $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 40, 'unit' => 'g'])->assertStatus(201);

        $response = $this->deleteJson("/api/meals/{$meal->id}/items/{$first}");

        $response->assertOk()->assertJsonPath('message', 'Élément supprimé.');
        $this->assertExactKeys(['message', 'day'], $response->json());
        $this->assertSame(100.0, $response->json('day.totals.calories'));
        $this->assertSame(1900.0, $response->json('day.remaining.calories'));
        $this->assertSame(1, MealItem::query()->count());
    }

    public function test_update_meal_fields(): void
    {
        $meal = $this->createMeal();

        $response = $this->putJson("/api/meals/{$meal->id}", [
            'name' => '  Repas de fête  ',
            'notes' => 'Avec des amis',
            'consumed_at' => '2026-09-10T12:45:00+02:00',
        ]);

        $response->assertOk()->assertJsonPath('message', 'Repas mis à jour.');
        $this->assertExactKeys(['message', 'data'], $response->json());
        $this->assertExactKeys(self::MEAL_KEYS, $response->json('data'));
        $this->assertSame('Repas de fête', $response->json('data.name'));
        $this->assertSame('Avec des amis', $response->json('data.notes'));
        $this->assertSame('2026-09-10T10:45:00.000000Z', $response->json('data.consumed_at'), 'Stocké en UTC, sérialisé en ISO 8601.');

        // Mise à jour partielle : seul « notes » change, le nom est conservé ; null efface l'heure.
        $this->putJson("/api/meals/{$meal->id}", ['notes' => null, 'consumed_at' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Repas de fête')
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.consumed_at', null);

        $this->putJson("/api/meals/{$meal->id}", ['consumed_at' => 'pas une date'])
            ->assertStatus(422)
            ->assertJsonPath('errors.consumed_at.0', 'Le champ heure de consommation doit être une date valide.');
    }

    public function test_destroy_meal_deletes_its_items(): void
    {
        $meal = $this->createMeal();
        $food = $this->makeFood();
        $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);

        $response = $this->deleteJson("/api/meals/{$meal->id}");

        $response->assertOk()->assertJson(['message' => 'Repas supprimé.']);
        $this->assertExactKeys(['message'], $response->json());
        $this->assertSame(0, Meal::query()->count());
        $this->assertSame(0, MealItem::query()->count());
    }

    public function test_another_users_meal_is_404_everywhere(): void
    {
        $other = User::factory()->create();
        $meal = Meal::factory()->on(self::DATE)->ofType('dejeuner')->create(['user_id' => $other->id]);
        $item = MealItem::factory()->create(['meal_id' => $meal->id]);
        $food = $this->makeFood();

        $this->postJson("/api/meals/{$meal->id}/items", ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])
            ->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->putJson("/api/meals/{$meal->id}/items/{$item->id}", ['quantity' => 1, 'unit' => 'g'])
            ->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->deleteJson("/api/meals/{$meal->id}/items/{$item->id}")
            ->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->putJson("/api/meals/{$meal->id}", ['name' => 'Piraté'])
            ->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->deleteJson("/api/meals/{$meal->id}")
            ->assertStatus(404)->assertJson(['message' => 'Introuvable.']);

        $this->assertSame(1, MealItem::query()->count());
        $this->assertNull($meal->fresh()->name);
    }

    public function test_item_of_another_meal_is_404_even_for_the_same_user(): void
    {
        $mealA = $this->createMeal('dejeuner');
        $mealB = $this->createMeal('diner');
        $itemB = MealItem::factory()->create(['meal_id' => $mealB->id]);

        $this->putJson("/api/meals/{$mealA->id}/items/{$itemB->id}", ['quantity' => 1, 'unit' => 'g'])
            ->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->deleteJson("/api/meals/{$mealA->id}/items/{$itemB->id}")
            ->assertStatus(404);

        $this->assertNotNull($itemB->fresh());
    }

    public function test_non_numeric_ids_are_404(): void
    {
        $this->putJson('/api/meals/abc', ['name' => 'x'])->assertStatus(404)->assertJson(['message' => 'Introuvable.']);
        $this->deleteJson('/api/meals/1/items/xyz')->assertStatus(404);
    }
}
