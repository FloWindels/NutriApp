<?php

namespace Tests\Feature\Meals;

use App\Models\DailyTarget;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;

class MealStoreTest extends MealsTestCase
{
    public function test_post_creates_meal_once_then_appends_without_overwriting_name(): void
    {
        $food = $this->makeFood();

        $first = $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'], self::DATE, ['name' => 'Déjeuner du midi']);

        $first->assertStatus(201)
            ->assertJsonPath('message', 'Repas enregistré.')
            ->assertJsonPath('data.name', 'Déjeuner du midi')
            ->assertJsonPath('data.type', 'dejeuner')
            ->assertJsonPath('data.date', self::DATE)
            ->assertJsonCount(1, 'data.items');

        $this->assertExactKeys(['message', 'data', 'day', 'stock_decrements'], $first->json());
        $this->assertExactKeys(self::MEAL_KEYS, $first->json('data'), 'MealResource');
        $this->assertExactKeys(self::DAY_KEYS, $first->json('day'), 'day');
        $this->assertSame([], $first->json('stock_decrements'));

        $second = $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 50, 'unit' => 'g'], self::DATE, ['name' => 'Autre nom']);

        $second->assertStatus(200)
            ->assertJsonPath('message', 'Repas mis à jour.')
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.name', 'Déjeuner du midi')
            ->assertJsonCount(2, 'data.items');

        $this->assertSame(1, Meal::query()->count());
        $this->assertSame(375.0, $second->json('data.totals.calories'));
    }

    public function test_post_without_items_creates_an_empty_meal(): void
    {
        $response = $this->postJson('/api/meals', ['date' => self::DATE, 'type' => 'diner']);

        $response->assertStatus(201)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.totals.calories', 0.0)
            ->assertJsonPath('day.date', self::DATE);

        $this->assertSame(0, DailyTarget::query()->count(), 'Sans élément, aucune cible du jour n’est figée.');
    }

    public function test_several_collations_are_items_of_a_single_meal(): void
    {
        $food = $this->makeFood();

        foreach ([30, 40, 50] as $grams) {
            $this->postItem('collation', ['food_id' => $food->id, 'quantity' => $grams, 'unit' => 'g'])->assertSuccessful();
        }

        $this->assertSame(1, Meal::query()->where('type', 'collation')->count());
        $this->assertSame(3, MealItem::query()->count());

        $this->getJson('/api/meals?date='.self::DATE)
            ->assertOk()
            ->assertJsonCount(1, 'data.meals')
            ->assertJsonCount(3, 'data.meals.0.items')
            ->assertJsonPath('data.totals.calories', 300.0);
    }

    public function test_food_snapshot_per_100g(): void
    {
        $food = $this->makeFood();

        $response = $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g']);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');

        $this->assertExactKeys(self::ITEM_KEYS, $item, 'MealItemResource');
        $this->assertSame('food', $item['source_type']);
        $this->assertSame($food->id, $item['food_id']);
        $this->assertSame(100.0, $item['quantity']);
        $this->assertSame('g', $item['unit']);
        $this->assertSame(100.0, $item['grams_equivalent']);
        $this->assertSame(250.0, $item['calories']);
        $this->assertSame(20.0, $item['proteins']);
        $this->assertSame(30.0, $item['carbs']);
        $this->assertSame(5.0, $item['fat']);
        $this->assertSame(2.0, $item['fiber']);
        $this->assertSame(1.0, $item['sugar']);
        $this->assertSame(0.5, $item['salt']);
        $this->assertFalse($item['is_estimate']);
        $this->assertSame([
            'id' => $food->id,
            'barcode' => '3000000000017',
            'brand' => 'Marque test',
            'image_url' => 'https://img.test/poulet.jpg',
        ], $item['food']);

        $row = MealItem::query()->firstOrFail();
        $this->assertSame('per_100g', $row->ref_basis);
        $this->assertSame(250.0, $row->ref_calories);
        $this->assertSame(20.0, $row->ref_proteins);
    }

    public function test_food_portion_uses_serving_size_and_flags_estimate(): void
    {
        $food = $this->makeFood(['serving_size_g' => 50]);

        $response = $this->postItem('petit_dejeuner', ['food_id' => $food->id, 'quantity' => 2, 'unit' => 'portion']);

        $response->assertStatus(201)
            ->assertJsonPath('data.items.0.unit', 'portion')
            ->assertJsonPath('data.items.0.quantity', 2.0)
            ->assertJsonPath('data.items.0.grams_equivalent', 100.0)
            ->assertJsonPath('data.items.0.calories', 250.0)
            ->assertJsonPath('data.items.0.is_estimate', true);

        $this->assertSame(50.0, MealItem::query()->firstOrFail()->ref_serving_size_g);
    }

    public function test_recipe_snapshot_per_serving_times_quantity(): void
    {
        $recipe = $this->makeRecipe();

        $response = $this->postItem('diner', ['recipe_id' => $recipe->id, 'quantity' => 1.5, 'unit' => 'portion']);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');

        $this->assertSame('recipe', $item['source_type']);
        $this->assertSame($recipe->id, $item['recipe_id']);
        $this->assertNull($item['food_id']);
        $this->assertNull($item['food']);
        $this->assertSame('portion', $item['unit']);
        $this->assertSame(1.5, $item['quantity']);
        $this->assertNull($item['grams_equivalent']);
        $this->assertSame(300.0, $item['calories']);
        $this->assertSame(15.0, $item['proteins']);
        $this->assertSame(30.0, $item['carbs']);
        $this->assertSame(7.5, $item['fat']);
        $this->assertFalse($item['is_estimate']);
        $this->assertSame('per_serving', MealItem::query()->firstOrFail()->ref_basis);
    }

    public function test_recipe_without_macros_keeps_calories_only_and_is_estimate(): void
    {
        $recipe = $this->makeRecipe(['proteins' => null, 'carbs' => null, 'fat' => null]);

        $this->postItem('diner', ['recipe_id' => $recipe->id, 'quantity' => 2, 'unit' => 'portion'])
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.calories', 400.0)
            ->assertJsonPath('data.items.0.proteins', 0.0)
            ->assertJsonPath('data.items.0.is_estimate', true)
            ->assertJsonPath('data.totals.is_partial', true);
    }

    public function test_custom_absolute_values_are_the_totals(): void
    {
        $response = $this->postItem('collation', [
            'custom' => ['label' => 'Part de gâteau', 'calories' => 320, 'proteins' => 6, 'carbs' => 40, 'fat' => 14],
            'quantity' => 1,
            'unit' => 'portion',
        ]);

        $response->assertStatus(201);
        $item = $response->json('data.items.0');

        $this->assertSame('custom', $item['source_type']);
        $this->assertSame('Part de gâteau', $item['label']);
        $this->assertSame(320.0, $item['calories']);
        $this->assertSame(6.0, $item['proteins']);
        $this->assertSame(40.0, $item['carbs']);
        $this->assertSame(14.0, $item['fat']);
        $this->assertNull($item['fiber']);
        $this->assertFalse($item['is_estimate']);
        $this->assertSame('absolute', MealItem::query()->firstOrFail()->ref_basis);
    }

    public function test_custom_per_100g_scales_with_grams(): void
    {
        $this->postItem('collation', [
            'custom' => ['label' => 'Granola maison', 'per_100g' => true, 'calories' => 200, 'proteins' => 10, 'carbs' => 30, 'fat' => 4],
            'quantity' => 150,
            'unit' => 'g',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.calories', 300.0)
            ->assertJsonPath('data.items.0.proteins', 15.0)
            ->assertJsonPath('data.items.0.carbs', 45.0)
            ->assertJsonPath('data.items.0.fat', 6.0)
            ->assertJsonPath('data.items.0.grams_equivalent', 150.0)
            ->assertJsonPath('data.items.0.is_estimate', false);

        $this->assertSame('per_100g', MealItem::query()->firstOrFail()->ref_basis);
    }

    public function test_decrement_stock_in_the_same_request(): void
    {
        $food = $this->makeFood();
        $stock = Stock::factory()->create(['user_id' => $this->user->id, 'name' => 'Frigo']);
        $stockItem = StockItem::factory()->create([
            'stock_id' => $stock->id,
            'food_id' => $food->id,
            'food_name' => $food->name,
            'quantity' => 500,
            'unit' => 'g',
        ]);

        $response = $this->postItem('dejeuner', [
            'food_id' => $food->id,
            'quantity' => 100,
            'unit' => 'g',
            'stock_item_id' => $stockItem->id,
            'decrement_stock' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.items.0.stock_item_id', $stockItem->id)
            ->assertJsonCount(1, 'stock_decrements');

        $this->assertSame([
            'stock_item_id' => $stockItem->id,
            'previous_quantity' => 500.0,
            'new_quantity' => 400.0,
            'unit' => 'g',
            'depleted' => false,
        ], $response->json('stock_decrements.0'));

        $this->assertSame(400.0, $stockItem->fresh()->quantity);
    }

    public function test_stock_item_without_decrement_flag_is_only_linked(): void
    {
        $food = $this->makeFood();
        $stock = Stock::factory()->create(['user_id' => $this->user->id, 'name' => 'Placard']);
        $stockItem = StockItem::factory()->create(['stock_id' => $stock->id, 'food_id' => $food->id, 'quantity' => 500, 'unit' => 'g']);

        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g', 'stock_item_id' => $stockItem->id])
            ->assertStatus(201)
            ->assertJsonPath('stock_decrements', []);

        $this->assertSame(500.0, $stockItem->fresh()->quantity);
    }

    public function test_daily_targets_are_written_once_on_first_item(): void
    {
        $food = $this->makeFood();

        $this->postItem('petit_dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);

        $target = DailyTarget::query()->where('user_id', $this->user->id)->where('date', self::DATE)->firstOrFail();
        $this->assertSame(self::TARGET_CALORIES, $target->calories);
        $this->assertSame(self::TARGET_PROTEINS, $target->proteins);
        $this->assertSame(self::TARGET_CARBS, $target->carbs);
        $this->assertSame(self::TARGET_FAT, $target->fat);

        Profile::query()->where('user_id', $this->user->id)->update(['calories_cibles' => 2500]);

        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);

        $this->assertSame(1, DailyTarget::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(self::TARGET_CALORIES, $target->fresh()->calories, 'Les cibles du jour ne sont figées qu’une fois.');
    }

    public function test_validation_requires_exactly_one_source(): void
    {
        $food = $this->makeFood();

        $this->postItem('dejeuner', [
            'food_id' => $food->id,
            'custom' => ['label' => 'X', 'calories' => 1, 'proteins' => 0, 'carbs' => 0, 'fat' => 0],
            'quantity' => 100,
            'unit' => 'g',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0' => 'Indique un aliment, une recette ou un aliment personnalisé (une seule source).']);

        $this->postItem('dejeuner', ['quantity' => 100, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0']);
    }

    public function test_validation_messages_are_french(): void
    {
        $food = $this->makeFood();

        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 0, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity' => 'Le champ quantité doit être supérieur à 0.']);

        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 10, 'unit' => 'xyz'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit' => 'L’unité « xyz » n’est pas reconnue.']);

        $this->postItem('brunch', ['food_id' => $food->id, 'quantity' => 10, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonPath('errors.type.0', 'La valeur sélectionnée pour type de repas n’est pas valide.');

        $this->postJson('/api/meals', ['type' => 'dejeuner'])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', 'Le champ date est obligatoire.');

        $this->postJson('/api/meals', ['date' => '10/09/2026', 'type' => 'dejeuner'])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', 'Le champ date doit respecter le format Y-m-d.');

        $this->postItem('dejeuner', ['food_id' => 999999, 'quantity' => 10, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.food_id' => 'La valeur sélectionnée pour aliment n’est pas valide.']);
    }

    public function test_custom_item_unit_is_restricted(): void
    {
        $this->postItem('collation', [
            'custom' => ['label' => 'Huile', 'calories' => 90, 'proteins' => 0, 'carbs' => 0, 'fat' => 10],
            'quantity' => 1,
            'unit' => 'cas',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit' => 'L’unité d’un aliment personnalisé doit être g, ml, pièce ou portion.']);

        $this->postItem('collation', [
            'custom' => ['label' => 'Biscuit', 'calories' => 90, 'proteins' => 1, 'carbs' => 12, 'fat' => 4],
            'quantity' => 2,
            'unit' => 'unite',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.unit', 'piece')
            ->assertJsonPath('data.items.0.calories', 90.0);
    }

    public function test_custom_item_requires_label_and_macros(): void
    {
        $this->postItem('collation', ['custom' => ['calories' => 90], 'quantity' => 1, 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.custom.label', 'items.0.custom.proteins', 'items.0.custom.carbs', 'items.0.custom.fat']);
    }

    public function test_private_recipe_of_another_user_is_404_and_rolls_back(): void
    {
        $other = User::factory()->create();
        $recipe = Recipe::factory()->privee()->create(['created_by_user_id' => $other->id]);

        $this->postItem('diner', ['recipe_id' => $recipe->id, 'quantity' => 1, 'unit' => 'portion'])
            ->assertStatus(404)
            ->assertJson(['message' => 'Introuvable.']);

        $this->assertSame(0, Meal::query()->count(), 'La transaction annule la création du repas.');
        $this->assertSame(0, MealItem::query()->count());
    }

    public function test_foreign_stock_item_is_404(): void
    {
        $food = $this->makeFood();
        $other = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $other->id, 'name' => 'Frigo']);
        $stockItem = StockItem::factory()->create(['stock_id' => $stock->id, 'food_id' => $food->id, 'quantity' => 500, 'unit' => 'g']);

        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g', 'stock_item_id' => $stockItem->id, 'decrement_stock' => true])
            ->assertStatus(404)
            ->assertJson(['message' => 'Introuvable.']);

        $this->assertSame(500.0, $stockItem->fresh()->quantity);
        $this->assertSame(0, MealItem::query()->count());
    }

    public function test_meal_routes_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/meals')->assertStatus(401)->assertJson(['message' => 'Non authentifié.']);
        $this->postJson('/api/meals', ['date' => self::DATE, 'type' => 'dejeuner'])->assertStatus(401);
    }

    public function test_v1_prefix_serves_the_same_routes(): void
    {
        $food = $this->makeFood();

        $this->postJson('/api/v1/meals', ['date' => self::DATE, 'type' => 'dejeuner', 'items' => [['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g']]])
            ->assertStatus(201)
            ->assertJsonPath('data.totals.calories', 250.0);
    }
}
