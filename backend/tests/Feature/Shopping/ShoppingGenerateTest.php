<?php

namespace Tests\Feature\Shopping;

use App\Models\Food;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Planner\ModuleM7Helpers;
use Tests\TestCase;

class ShoppingGenerateTest extends TestCase
{
    use ModuleM7Helpers;
    use RefreshDatabase;

    private const LEGACY_STOCK_ITEM_KEYS = [
        'id', 'stock_id', 'stock_name', 'food_id', 'food_name', 'food_barcode', 'food_brand', 'quantity', 'unit',
        'expires_at', 'days_left', 'created_at', 'updated_at',
    ];

    private function plannedRecipe(User $user, array $ingredients, array $plan = []): Recipe
    {
        $recipe = Recipe::factory()->create([
            'created_by_user_id' => $user->id,
            'servings' => 1,
            'is_public' => true,
            'ingredients' => $ingredients,
        ]);

        MealPlan::factory()->create(array_merge([
            'user_id' => $user->id,
            'household_id' => null,
            'date' => $this->weekDay($user, 0),
            'meal_type' => 'dejeuner',
            'recipe_id' => $recipe->id,
            'title' => $recipe->title,
            'servings' => 1,
            'status' => 'prevu',
        ], $plan));

        return $recipe;
    }

    public function test_generate_adds_missing_ingredients_and_low_stock_and_compares_with_stock(): void
    {
        $user = $this->login($this->userWithProfile());
        $stock = $this->personalStock($user);

        $this->plannedRecipe($user, [
            ['name' => 'Riz basmati', 'ean' => '3000000000001', 'amount' => 200, 'unit' => 'g'],
            ['name' => 'Poulet', 'ean' => null, 'amount' => 300, 'unit' => 'g'],
            ['name' => 'Brocoli', 'ean' => null, 'amount' => 1, 'unit' => 'piece'],
            ['name' => 'Lait', 'ean' => null, 'amount' => 20, 'unit' => 'cl'],
        ]);

        // En stock par EAN → pas ajouté.
        StockItem::factory()->create(['stock_id' => $stock->id, 'food_name' => 'Riz', 'food_barcode' => '3000000000001', 'quantity' => 500, 'unit' => 'g', 'expires_at' => null]);
        // En stock par nom (LIKE insensible à la casse/accents) → pas ajouté.
        StockItem::factory()->create(['stock_id' => $stock->id, 'food_name' => 'Brocolis frais', 'quantity' => 2, 'unit' => 'piece', 'expires_at' => null]);
        // Périmé → pas disponible → ajouté.
        StockItem::factory()->expired()->create(['stock_id' => $stock->id, 'food_name' => 'Poulet', 'quantity' => 1, 'unit' => 'piece']);
        // Épuisé → pas disponible → ajouté (une seule fois : ingrédient puis stock bas dédoublonné).
        StockItem::factory()->depleted()->create(['stock_id' => $stock->id, 'food_name' => 'Lait', 'unit' => 'l']);
        // Stock bas (quantité ≤ min_quantity) → ajouté en auto_stock.
        StockItem::factory()->create(['stock_id' => $stock->id, 'food_name' => 'Œufs', 'quantity' => 2, 'min_quantity' => 6, 'unit' => 'piece', 'expires_at' => null]);

        $response = $this->postJson('/api/shopping-list/generate')->assertOk();

        $response->assertJsonPath('added_count', 3);
        $response->assertJsonPath('message', '3 articles ajoutés à la liste.');
        $response->assertJsonPath('week_start', $this->weekStart($user));
        $response->assertJsonPath('counts.total', 3);

        $items = collect($response->json('data'))->keyBy('label');
        $this->assertEqualsCanonicalizing(['Poulet', 'Lait', 'Œufs'], $items->keys()->all());

        $this->assertSame('planificateur', $items['Poulet']['source']);
        $this->assertEquals(300, $items['Poulet']['quantity']);
        $this->assertSame('g', $items['Poulet']['unit']);
        $this->assertSame('planificateur', $items['Lait']['source']);
        $this->assertSame('auto_stock', $items['Œufs']['source']);
        $this->assertNull($items['Œufs']['quantity']);
    }

    public function test_generate_dedupes_against_unchecked_items_case_insensitively_and_is_idempotent(): void
    {
        $user = $this->login($this->userWithProfile());

        $this->plannedRecipe($user, [
            ['name' => 'Poulet', 'ean' => null, 'amount' => 300, 'unit' => 'g'],
            ['name' => 'Carottes', 'ean' => null, 'amount' => 3, 'unit' => 'piece'],
        ]);

        ShoppingItem::factory()->create(['user_id' => $user->id, 'label' => 'poulet']);
        // Un article coché ne compte pas comme présent : « Carottes » est bien ré-ajouté.
        ShoppingItem::factory()->checked()->create(['user_id' => $user->id, 'label' => 'Carottes']);

        $this->postJson('/api/shopping-list/generate')->assertOk()->assertJsonPath('added_count', 1);
        $this->assertSame(1, ShoppingItem::query()->where('user_id', $user->id)->where('checked', false)->whereRaw('LOWER(label) = ?', ['carottes'])->count());

        $this->postJson('/api/shopping-list/generate')
            ->assertOk()
            ->assertJsonPath('added_count', 0)
            ->assertJsonPath('message', 'Rien à ajouter : ta liste est déjà à jour.');

        $this->assertSame(3, ShoppingItem::query()->where('user_id', $user->id)->count());
    }

    public function test_generate_ignores_realised_cancelled_and_out_of_week_plans_and_scales_by_servings(): void
    {
        $user = $this->login($this->userWithProfile());

        $this->plannedRecipe($user, [['name' => 'Saumon', 'ean' => null, 'amount' => 150, 'unit' => 'g']], ['status' => 'realise']);
        $this->plannedRecipe($user, [['name' => 'Thon', 'ean' => null, 'amount' => 150, 'unit' => 'g']], ['status' => 'annule']);
        $this->plannedRecipe($user, [['name' => 'Cabillaud', 'ean' => null, 'amount' => 150, 'unit' => 'g']], ['date' => $this->weekDay($user, 8)]);
        $this->plannedRecipe($user, [['name' => 'Lentilles', 'ean' => null, 'amount' => 100, 'unit' => 'g']], ['servings' => 2, 'date' => $this->weekDay($user, 6)]);

        $response = $this->postJson('/api/shopping-list/generate', ['week_start' => $this->weekDay($user, 2)])->assertOk();

        $response->assertJsonPath('added_count', 1);
        $response->assertJsonPath('week_start', $this->weekStart($user));
        $response->assertJsonPath('data.0.label', 'Lentilles');
        $response->assertJsonPath('data.0.quantity', 200);
    }

    public function test_generate_in_household_uses_household_plans_stock_and_list(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner, $member);
        $owner = $owner->fresh();

        $stock = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        StockItem::factory()->create(['stock_id' => $stock->id, 'food_name' => 'Tomates', 'quantity' => 4, 'unit' => 'piece', 'expires_at' => null]);

        $recipe = Recipe::factory()->create(['created_by_user_id' => $member->id, 'is_public' => true, 'servings' => 1, 'ingredients' => [
            ['name' => 'Tomates', 'ean' => null, 'amount' => 2, 'unit' => 'piece'],
            ['name' => 'Mozzarella', 'ean' => null, 'amount' => 125, 'unit' => 'g'],
        ]]);
        MealPlan::factory()->create(['user_id' => $member->id, 'household_id' => $household->id, 'date' => $this->weekDay($owner, 3), 'meal_type' => 'diner', 'recipe_id' => $recipe->id, 'title' => $recipe->title]);

        $this->login($owner);

        $this->postJson('/api/shopping-list/generate')
            ->assertOk()
            ->assertJsonPath('added_count', 1)
            ->assertJsonPath('data.0.label', 'Mozzarella')
            ->assertJsonPath('data.0.household_id', $household->id);
    }

    public function test_generate_validates_week_start(): void
    {
        $this->login($this->userWithProfile());

        $this->postJson('/api/shopping-list/generate', ['week_start' => '16/09/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['week_start']);
    }

    public function test_to_stock_creates_stock_item_in_given_location_and_deletes_list_item(): void
    {
        $user = $this->login(User::factory()->create());
        $frigo = $this->personalStock($user, 'Frigo');
        $food = Food::factory()->create(['name' => 'Yaourt nature', 'barcode' => '3017620422003', 'brand' => 'Marque']);
        $item = ShoppingItem::factory()->create(['user_id' => $user->id, 'label' => 'Yaourts', 'quantity' => 4, 'unit' => 'piece', 'food_id' => $food->id]);

        $expires = CarbonImmutable::now()->addDays(10)->toDateString();

        $response = $this->postJson('/api/shopping-list/items/'.$item->id.'/to-stock', [
            'stock_id' => $frigo->id,
            'expires_at' => $expires,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Article ajouté au stock « Frigo ».')
            ->assertJsonPath('data.stock_id', $frigo->id)
            ->assertJsonPath('data.stock_name', 'Frigo')
            ->assertJsonPath('data.food_id', $food->id)
            ->assertJsonPath('data.food_name', 'Yaourts')
            ->assertJsonPath('data.food_barcode', '3017620422003')
            ->assertJsonPath('data.food_brand', 'Marque')
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.unit', 'piece')
            ->assertJsonPath('data.expires_at', $expires);

        $data = $response->json('data');
        foreach (self::LEGACY_STOCK_ITEM_KEYS as $key) {
            $this->assertArrayHasKey($key, $data, "Clé héritée manquante : $key");
        }
        $this->assertIsNumeric($data['quantity']);
        $this->assertIsInt($data['days_left']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['expires_at']);

        $this->assertDatabaseMissing('shopping_items', ['id' => $item->id]);
        $this->assertDatabaseHas('stock_items', ['stock_id' => $frigo->id, 'food_name' => 'Yaourts', 'food_id' => $food->id]);
    }

    public function test_to_stock_defaults_to_placard_then_frigo_then_creates_placard(): void
    {
        $user = $this->login(User::factory()->create());

        // Aucun lieu : « Placard » est créé dans la portée.
        $item1 = ShoppingItem::factory()->create(['user_id' => $user->id, 'label' => 'Pâtes', 'quantity' => null, 'unit' => null]);
        $this->postJson('/api/shopping-list/items/'.$item1->id.'/to-stock', ['quantity' => 2, 'unit' => 'kg'])
            ->assertStatus(201)
            ->assertJsonPath('data.stock_name', 'Placard')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.unit', 'kg')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.days_left', null);

        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'household_id' => null, 'name' => 'Placard']);

        // Placard et Frigo présents : Placard d'abord.
        $this->personalStock($user, 'Frigo');
        // quantity/unit explicitement nuls : on teste le repli « 1 unite » du contrôleur
        // (la factory tire une quantité au hasard, ce qui rendrait l'assertion instable).
        $item2 = ShoppingItem::factory()->create([
            'user_id' => $user->id,
            'label' => 'Riz',
            'quantity' => null,
            'unit' => null,
        ]);
        $this->postJson('/api/shopping-list/items/'.$item2->id.'/to-stock')
            ->assertStatus(201)
            ->assertJsonPath('data.stock_name', 'Placard')
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.unit', 'unite');

        // Sans Placard : Frigo.
        Stock::query()->where('user_id', $user->id)->where('name', 'Placard')->delete();
        $item3 = ShoppingItem::factory()->create(['user_id' => $user->id, 'label' => 'Beurre']);
        $this->postJson('/api/shopping-list/items/'.$item3->id.'/to-stock')
            ->assertStatus(201)
            ->assertJsonPath('data.stock_name', 'Frigo');
    }

    public function test_to_stock_with_foreign_stock_or_foreign_item_returns_404(): void
    {
        $user = $this->login(User::factory()->create());
        $item = ShoppingItem::factory()->create(['user_id' => $user->id]);
        $foreignStock = Stock::factory()->create(['name' => 'Placard']);
        $foreignItem = ShoppingItem::factory()->create();

        $this->postJson('/api/shopping-list/items/'.$item->id.'/to-stock', ['stock_id' => $foreignStock->id])
            ->assertStatus(404)
            ->assertJson(['message' => 'Introuvable.']);
        $this->assertDatabaseHas('shopping_items', ['id' => $item->id]);
        $this->assertDatabaseCount('stock_items', 0);

        $this->postJson('/api/shopping-list/items/'.$foreignItem->id.'/to-stock')
            ->assertStatus(404)
            ->assertJson(['message' => 'Introuvable.']);
    }

    public function test_to_stock_in_household_uses_household_location(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $owner = $this->login($owner->fresh());

        $this->personalStock($owner, 'Placard'); // lieu personnel : hors portée quand on a un foyer
        $shared = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        $item = ShoppingItem::factory()->create(['user_id' => $owner->id, 'household_id' => $household->id, 'label' => 'Crème']);

        $this->postJson('/api/shopping-list/items/'.$item->id.'/to-stock')
            ->assertStatus(201)
            ->assertJsonPath('data.stock_id', $shared->id);
    }

    public function test_to_stock_validates_payload(): void
    {
        $user = $this->login(User::factory()->create());
        $item = ShoppingItem::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/shopping-list/items/'.$item->id.'/to-stock', ['quantity' => 0, 'expires_at' => 'demain'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity', 'expires_at']);
    }
}
