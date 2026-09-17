<?php

namespace Tests\Feature\Stock;

use App\Models\Stock;
use App\Models\StockItem;

class StockHouseholdTest extends StockTestCase
{
    public function test_member_sees_household_stocks_and_not_the_personal_ones_of_others(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $household = $this->household($owner, [$member]);

        $shared = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        $sharedItem = StockItem::factory()->create(['stock_id' => $shared->id, 'food_name' => 'Beurre']);

        // Lieu personnel de l'owner (hors foyer) : invisible pour le membre.
        $ownerPersonal = $this->personalStock($owner, 'Cave');
        StockItem::factory()->create(['stock_id' => $ownerPersonal->id, 'food_name' => 'Vin']);

        $this->actingAsUser($member);
        $response = $this->getJson('/api/stocks')->assertOk();

        $this->assertSame($household->id, $response->json('household_id'));
        $this->assertSame([$sharedItem->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame($household->id, $response->json('data.0.household_id'));

        $locationNames = collect($response->json('locations'))->pluck('name')->all();
        $this->assertContains('Frigo', $locationNames);
        $this->assertNotContains('Cave', $locationNames);
    }

    public function test_index_lazily_creates_household_default_locations_without_touching_personal_ones(): void
    {
        $owner = $this->user();
        $household = $this->household($owner);
        $this->personalStock($owner, 'Frigo');

        $this->actingAsUser($owner);
        $response = $this->getJson('/api/stocks')->assertOk();

        $this->assertCount(3, $response->json('locations'));
        $this->assertSame(3, Stock::query()->where('household_id', $household->id)->whereNull('user_id')->count());
        $this->assertSame(1, Stock::query()->where('user_id', $owner->id)->whereNull('household_id')->count());
    }

    public function test_member_can_add_and_edit_items_in_household_locations(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $household = $this->household($owner, [$member]);
        $shared = Stock::factory()->forHousehold($household)->create(['name' => 'Placard']);

        $this->actingAsUser($member);

        $created = $this->postJson('/api/stocks/items', [
            'stock_id' => $shared->id,
            'food_name' => 'Farine',
            'quantity' => 1,
            'unit' => 'kg',
        ])->assertCreated()->json('data');

        $this->assertSame($household->id, $created['household_id']);
        $this->assertSame('Placard', $created['stock_name']);

        // Sans stock_id : le « Frigo » du foyer est créé à la demande (user_id NULL).
        $frigoItem = $this->postJson('/api/stocks/items', ['food_name' => 'Lait'])->assertCreated()->json('data');
        $this->assertSame('Frigo', $frigoItem['stock_name']);
        $this->assertDatabaseHas('stocks', ['id' => $frigoItem['stock_id'], 'household_id' => $household->id, 'user_id' => null]);

        $this->actingAsUser($owner);
        $this->putJson("/api/stocks/items/{$created['id']}", ['quantity' => 0.5])->assertOk();
        $this->assertDatabaseHas('stock_items', ['id' => $created['id'], 'quantity' => 0.5]);
    }

    public function test_household_location_names_are_unique_within_the_household_only(): void
    {
        $owner = $this->user();
        $household = $this->household($owner);
        Stock::factory()->forHousehold($household)->create(['name' => 'Cave']);
        // Un autre utilisateur possède déjà « Cave » à titre personnel : aucun conflit.
        $this->personalStock($this->user(), 'Cave');

        $this->actingAsUser($owner);

        $this->postJson('/api/stocks', ['name' => 'cave'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ce lieu existe déjà.');

        $this->postJson('/api/stocks', ['name' => 'Garage'])->assertCreated();
        $this->assertDatabaseHas('stocks', ['household_id' => $household->id, 'user_id' => null, 'name' => 'Garage']);
    }

    public function test_non_member_and_other_household_cannot_touch_household_items(): void
    {
        $owner = $this->user();
        $household = $this->household($owner);
        $shared = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        $item = StockItem::factory()->create(['stock_id' => $shared->id, 'quantity' => 3]);

        // Utilisateur sans foyer.
        $this->actingAsUser();
        $this->putJson("/api/stocks/items/{$item->id}", ['quantity' => 1])->assertNotFound();
        $this->deleteJson("/api/stocks/items/{$item->id}")->assertNotFound();
        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 1])->assertNotFound();
        $this->putJson("/api/stocks/{$shared->id}", ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson("/api/stocks/{$shared->id}")->assertNotFound();

        // Membre d'un autre foyer.
        $otherOwner = $this->user();
        $this->household($otherOwner);
        $this->actingAsUser($otherOwner);
        $this->putJson("/api/stocks/items/{$item->id}", ['quantity' => 1])->assertNotFound();
        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 1])->assertNotFound();
        $this->deleteJson("/api/stocks/{$shared->id}")->assertNotFound();

        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'quantity' => 3]);
        $this->assertDatabaseHas('stocks', ['id' => $shared->id, 'name' => 'Frigo']);
    }

    public function test_member_cannot_move_a_household_item_into_a_personal_location(): void
    {
        $owner = $this->user();
        $household = $this->household($owner);
        $shared = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        $item = StockItem::factory()->create(['stock_id' => $shared->id]);
        $personal = $this->personalStock($owner, 'Cave');

        $this->actingAsUser($owner);

        $this->putJson("/api/stocks/items/{$item->id}", ['stock_id' => $personal->id])->assertNotFound();
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'stock_id' => $shared->id]);
    }

    public function test_consume_in_household_queues_the_shopping_item_for_the_household(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $household = $this->household($owner, [$member]);
        $shared = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        $item = StockItem::factory()->create(['stock_id' => $shared->id, 'food_id' => null, 'food_name' => 'Beurre', 'quantity' => 1, 'unit' => 'piece']);

        $this->actingAsUser($member);

        $this->postJson("/api/stocks/items/{$item->id}/consume", ['quantity' => 1, 'unit' => 'piece'])
            ->assertCreated()
            ->assertJsonPath('data.stock_item.depleted', true)
            ->assertJsonPath('data.meal_item', null);

        $this->assertDatabaseHas('shopping_items', [
            'household_id' => $household->id,
            'user_id' => null,
            'label' => 'Beurre',
            'source' => 'auto_stock',
            'checked' => false,
        ]);
    }
}
