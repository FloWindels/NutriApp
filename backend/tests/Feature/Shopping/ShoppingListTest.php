<?php

namespace Tests\Feature\Shopping;

use App\Models\Food;
use App\Models\ShoppingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Planner\ModuleM7Helpers;
use Tests\TestCase;

class ShoppingListTest extends TestCase
{
    use ModuleM7Helpers;
    use RefreshDatabase;

    private const ITEM_KEYS = [
        'id', 'household_id', 'food_id', 'label', 'quantity', 'unit', 'checked', 'source', 'source_label',
        'food', 'created_at', 'updated_at',
    ];

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/shopping-list')
            ->assertStatus(401)
            ->assertJson(['message' => 'Non authentifié.']);
    }

    public function test_index_returns_scoped_items_with_counts_and_exact_keys(): void
    {
        $user = $this->login(User::factory()->create());
        $other = User::factory()->create();
        $food = Food::factory()->create(['name' => 'Lait demi-écrémé']);

        ShoppingItem::factory()->create(['user_id' => $user->id, 'label' => 'Lait', 'quantity' => 1.5, 'unit' => 'l', 'food_id' => $food->id]);
        ShoppingItem::factory()->checked()->create(['user_id' => $user->id, 'label' => 'Pain']);
        ShoppingItem::factory()->create(['user_id' => $other->id, 'label' => 'Article étranger']);

        $response = $this->getJson('/api/shopping-list')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('counts.total', 2);
        $response->assertJsonPath('counts.checked', 1);

        $first = $response->json('data.0');
        $this->assertEqualsCanonicalizing(self::ITEM_KEYS, array_keys($first));
        $this->assertSame('Lait', $first['label']);
        $this->assertIsFloat($first['quantity']);
        $this->assertSame(1.5, $first['quantity']);
        $this->assertFalse($first['checked']);
        $this->assertSame('manuel', $first['source']);
        $this->assertSame('Ajouté à la main', $first['source_label']);
        $this->assertSame('Lait demi-écrémé', $first['food']['name']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $first['created_at']);

        // Les articles non cochés viennent en premier.
        $this->assertTrue($response->json('data.1.checked'));
        $this->assertNotContains('Article étranger', array_column($response->json('data'), 'label'));
    }

    public function test_index_with_household_shows_household_items_and_hides_personal_ones(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $household = $this->household($owner, $member);

        ShoppingItem::factory()->create(['user_id' => $member->id, 'household_id' => $household->id, 'label' => 'Article du foyer']);
        ShoppingItem::factory()->create(['user_id' => null, 'household_id' => $household->id, 'label' => 'Stock épuisé du foyer', 'source' => 'auto_stock']);
        ShoppingItem::factory()->create(['user_id' => $owner->id, 'household_id' => null, 'label' => 'Ancien article perso']);
        ShoppingItem::factory()->create(['user_id' => $stranger->id, 'label' => 'Article étranger']);

        $this->login($owner->fresh());

        $labels = array_column($this->getJson('/api/shopping-list')->assertOk()->json('data'), 'label');

        $this->assertEqualsCanonicalizing(['Article du foyer', 'Stock épuisé du foyer'], $labels);
    }

    public function test_store_creates_personal_item(): void
    {
        $user = $this->login(User::factory()->create());
        $food = Food::factory()->create();

        $response = $this->postJson('/api/shopping-list/items', [
            'label' => '  Tomates  ',
            'quantity' => 6,
            'unit' => 'piece',
            'food_id' => $food->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Article ajouté à la liste.')
            ->assertJsonPath('data.label', 'Tomates')
            ->assertJsonPath('data.quantity', 6)
            ->assertJsonPath('data.unit', 'piece')
            ->assertJsonPath('data.checked', false)
            ->assertJsonPath('data.source', 'manuel')
            ->assertJsonPath('data.food_id', $food->id)
            ->assertJsonPath('data.household_id', null);

        $this->assertDatabaseHas('shopping_items', ['label' => 'Tomates', 'user_id' => $user->id, 'household_id' => null]);
    }

    public function test_store_in_household_sets_household_id(): void
    {
        $owner = User::factory()->create();
        $household = $this->household($owner);
        $this->login($owner->fresh());

        $this->postJson('/api/shopping-list/items', ['label' => 'Beurre'])
            ->assertStatus(201)
            ->assertJsonPath('data.household_id', $household->id);

        $this->assertDatabaseHas('shopping_items', ['label' => 'Beurre', 'household_id' => $household->id, 'user_id' => $owner->id]);
    }

    public function test_store_validates_in_french(): void
    {
        $this->login(User::factory()->create());

        $response = $this->postJson('/api/shopping-list/items', ['quantity' => 'abc', 'food_id' => 999999]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['label', 'quantity', 'food_id']);

        $this->assertStringContainsString('libellé', $response->json('errors.label.0'));
        $this->assertStringContainsString('quantité', $response->json('errors.quantity.0'));
    }

    public function test_update_checks_item_and_changes_quantity(): void
    {
        $user = $this->login(User::factory()->create());
        $item = ShoppingItem::factory()->create(['user_id' => $user->id, 'label' => 'Riz', 'quantity' => null]);

        $this->putJson('/api/shopping-list/items/'.$item->id, ['checked' => true, 'quantity' => 2, 'unit' => 'kg', 'label' => 'Riz complet'])
            ->assertOk()
            ->assertJsonPath('message', 'Article mis à jour.')
            ->assertJsonPath('data.checked', true)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.unit', 'kg')
            ->assertJsonPath('data.label', 'Riz complet');

        $this->assertDatabaseHas('shopping_items', ['id' => $item->id, 'checked' => 1, 'label' => 'Riz complet']);
    }

    public function test_update_rejects_empty_label(): void
    {
        $user = $this->login(User::factory()->create());
        $item = ShoppingItem::factory()->create(['user_id' => $user->id]);

        $this->putJson('/api/shopping-list/items/'.$item->id, ['label' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);
    }

    public function test_update_and_delete_foreign_item_return_404(): void
    {
        $this->login(User::factory()->create());
        $foreign = ShoppingItem::factory()->create();

        $this->putJson('/api/shopping-list/items/'.$foreign->id, ['checked' => true])
            ->assertStatus(404)
            ->assertJson(['message' => 'Introuvable.']);

        $this->deleteJson('/api/shopping-list/items/'.$foreign->id)
            ->assertStatus(404)
            ->assertJson(['message' => 'Introuvable.']);

        $this->assertDatabaseHas('shopping_items', ['id' => $foreign->id, 'checked' => 0]);
    }

    public function test_destroy_removes_own_item(): void
    {
        $user = $this->login(User::factory()->create());
        $item = ShoppingItem::factory()->create(['user_id' => $user->id]);

        $this->deleteJson('/api/shopping-list/items/'.$item->id)
            ->assertOk()
            ->assertJson(['message' => 'Article supprimé.']);

        $this->assertDatabaseMissing('shopping_items', ['id' => $item->id]);
    }

    public function test_clear_checked_deletes_only_checked_items_in_scope(): void
    {
        $user = $this->login(User::factory()->create());
        $other = User::factory()->create();

        ShoppingItem::factory()->checked()->count(2)->create(['user_id' => $user->id]);
        $kept = ShoppingItem::factory()->create(['user_id' => $user->id]);
        $foreignChecked = ShoppingItem::factory()->checked()->create(['user_id' => $other->id]);

        $this->deleteJson('/api/shopping-list/checked')
            ->assertOk()
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonPath('message', 'Articles cochés supprimés.');

        $this->assertDatabaseHas('shopping_items', ['id' => $kept->id]);
        $this->assertDatabaseHas('shopping_items', ['id' => $foreignChecked->id]);
        $this->assertSame(1, ShoppingItem::query()->where('user_id', $user->id)->count());
    }

    public function test_routes_are_also_mounted_under_v1(): void
    {
        $user = $this->login(User::factory()->create());
        ShoppingItem::factory()->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/shopping-list')->assertOk()->assertJsonCount(1, 'data');
    }
}
