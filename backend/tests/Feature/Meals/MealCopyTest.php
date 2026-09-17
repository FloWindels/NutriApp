<?php

namespace Tests\Feature\Meals;

use App\Models\DailyTarget;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;

class MealCopyTest extends MealsTestCase
{
    public const TO = '2026-09-11';

    public function test_copy_all_meals_of_a_day_with_their_snapshots(): void
    {
        $food = $this->makeFood();
        $recipe = $this->makeRecipe();
        $this->postItem('petit_dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'], self::DATE, ['name' => 'Petit-déj'])->assertStatus(201);
        $this->postItem('petit_dejeuner', ['recipe_id' => $recipe->id, 'quantity' => 1, 'unit' => 'portion'])->assertStatus(200);
        $this->postItem('diner', ['custom' => ['label' => 'Soupe', 'calories' => 120, 'proteins' => 4, 'carbs' => 20, 'fat' => 2], 'quantity' => 1, 'unit' => 'portion'])->assertStatus(201);

        $response = $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO]);

        $response->assertOk()->assertJsonPath('message', 'Repas copiés.')->assertJsonPath('copied_count', 3);
        $this->assertExactKeys(['message', 'day', 'copied_count'], $response->json());
        $this->assertExactKeys(self::DAY_KEYS, $response->json('day'));
        $this->assertSame(self::TO, $response->json('day.date'));
        $this->assertSame(250 + 200 + 120.0, $response->json('day.totals.calories'));

        $copied = Meal::query()->where('user_id', $this->user->id)->where('date', self::TO)->get();
        $this->assertCount(2, $copied);
        $this->assertSame('Petit-déj', $copied->firstWhere('type', \App\Enums\MealType::PetitDejeuner)->name);

        $source = MealItem::query()->whereIn('meal_id', Meal::query()->where('date', self::DATE)->pluck('id'))->orderBy('id')->get();
        $target = MealItem::query()->whereIn('meal_id', $copied->pluck('id'))->orderBy('id')->get();
        $this->assertCount(3, $target);

        foreach ($source as $index => $item) {
            $copy = $target[$index];
            foreach (['source_type', 'label', 'quantity', 'unit', 'grams_equivalent', 'calories', 'proteins', 'carbs', 'fat', 'ref_basis', 'ref_calories', 'ref_serving_size_g', 'is_estimate', 'food_id', 'recipe_id'] as $column) {
                $this->assertEquals($item->{$column}, $copy->{$column}, "Colonne $column copiée à l’identique.");
            }
        }

        $this->assertSame(3, MealItem::query()->whereIn('meal_id', Meal::query()->where('date', self::DATE)->pluck('id'))->count(), 'La source est intacte.');
    }

    public function test_copy_a_single_meal_type(): void
    {
        $food = $this->makeFood();
        $this->postItem('petit_dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);
        $this->postItem('diner', ['food_id' => $food->id, 'quantity' => 200, 'unit' => 'g'])->assertStatus(201);

        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO, 'type' => 'diner'])
            ->assertOk()
            ->assertJsonPath('copied_count', 1)
            ->assertJsonCount(1, 'day.meals')
            ->assertJsonPath('day.meals.0.type', 'diner')
            ->assertJsonPath('day.totals.calories', 500.0);
    }

    public function test_copy_has_no_effect_on_stock(): void
    {
        $food = $this->makeFood();
        $stock = Stock::factory()->create(['user_id' => $this->user->id, 'name' => 'Frigo']);
        $stockItem = StockItem::factory()->create(['stock_id' => $stock->id, 'food_id' => $food->id, 'quantity' => 500, 'unit' => 'g']);

        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g', 'stock_item_id' => $stockItem->id, 'decrement_stock' => true])->assertStatus(201);
        $this->assertSame(400.0, $stockItem->fresh()->quantity);

        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO])->assertOk()->assertJsonPath('copied_count', 1);

        $this->assertSame(400.0, $stockItem->fresh()->quantity, 'La copie ne décrémente jamais le stock.');
        $copy = MealItem::query()->whereIn('meal_id', Meal::query()->where('date', self::TO)->pluck('id'))->firstOrFail();
        $this->assertNull($copy->stock_item_id, 'Le lien vers l’article de stock n’est pas copié.');
    }

    public function test_copy_appends_to_an_existing_target_meal(): void
    {
        $food = $this->makeFood();
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 20, 'unit' => 'g'], self::TO, ['name' => 'Déjà là'])->assertStatus(201);

        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO])
            ->assertOk()
            ->assertJsonCount(1, 'day.meals')
            ->assertJsonPath('day.meals.0.name', 'Déjà là')
            ->assertJsonCount(2, 'day.meals.0.items')
            ->assertJsonPath('day.totals.calories', 300.0);

        $this->assertSame(1, Meal::query()->where('date', self::TO)->count());
    }

    public function test_copy_writes_the_daily_targets_of_the_target_day(): void
    {
        $food = $this->makeFood();
        $this->postItem('dejeuner', ['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g'])->assertStatus(201);

        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO])->assertOk();

        $this->assertSame(self::TARGET_CALORIES, DailyTarget::query()->where('user_id', $this->user->id)->where('date', self::TO)->firstOrFail()->calories);
    }

    public function test_copy_with_nothing_to_copy_is_422(): void
    {
        Meal::factory()->on(self::DATE)->ofType('dejeuner')->create(['user_id' => $this->user->id]);

        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO])
            ->assertStatus(422)
            ->assertJsonPath('errors.from_date.0', 'Aucun repas à copier à cette date.');

        $this->assertSame(0, Meal::query()->where('date', self::TO)->count());
    }

    public function test_copy_validation(): void
    {
        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::DATE])
            ->assertStatus(422)
            ->assertJsonPath('errors.to_date.0', 'La date de destination doit être différente de la date d’origine.');

        $this->postJson('/api/meals/copy', ['to_date' => self::TO])
            ->assertStatus(422)
            ->assertJsonPath('errors.from_date.0', 'Le champ date d’origine est obligatoire.');

        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO, 'type' => 'brunch'])
            ->assertStatus(422)
            ->assertJsonPath('errors.type.0', 'La valeur sélectionnée pour type de repas n’est pas valide.');
    }

    public function test_copy_ignores_other_users_meals(): void
    {
        $other = User::factory()->create();
        $meal = Meal::factory()->on(self::DATE)->ofType('dejeuner')->create(['user_id' => $other->id]);
        MealItem::factory()->create(['meal_id' => $meal->id]);

        $this->postJson('/api/meals/copy', ['from_date' => self::DATE, 'to_date' => self::TO])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from_date']);

        $this->assertSame(1, MealItem::query()->count());
    }
}
