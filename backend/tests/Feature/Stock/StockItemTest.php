<?php

namespace Tests\Feature\Stock;

use App\Models\Food;
use App\Models\Stock;
use App\Models\StockItem;

class StockItemTest extends StockTestCase
{
    public function test_store_item_with_legacy_body_lands_in_frigo_and_returns_the_contract_keys(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/stocks/items', [
            'food_name' => 'Lait demi-écrémé',
            'food_brand' => 'Lactel',
            'quantity' => 2,
            'unit' => 'l',
            'expires_at' => $this->daysFromToday(4),
        ])->assertCreated()->assertJson(['message' => 'Produit ajouté au stock.']);

        $this->assertSame(['message', 'data'], array_keys($response->json()));
        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(array_merge($this->legacyItemKeys(), $this->newItemKeys()), array_keys($data));

        $this->assertSame('Frigo', $data['stock_name']);
        $this->assertSame('Lait demi-écrémé', $data['food_name']);
        $this->assertSame('Lactel', $data['food_brand']);
        $this->assertNull($data['food_id']);
        $this->assertNull($data['food']);
        $this->assertSame(2.0, $data['quantity']);
        $this->assertSame('l', $data['unit']);
        $this->assertSame(4, $data['days_left']);
        $this->assertSame('dlc', $data['expiry_kind']);
        $this->assertSame('ok', $data['expiry_status']);
        $this->assertFalse($data['is_depleted']);

        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'name' => 'Frigo']);
        $this->assertSame(1, Stock::query()->where('user_id', $user->id)->count());
    }

    public function test_store_item_defaults_quantity_and_unit(): void
    {
        $this->actingAsUser();

        $data = $this->postJson('/api/stocks/items', ['food_name' => 'Œufs'])->assertCreated()->json('data');

        $this->assertSame(1.0, $data['quantity']);
        $this->assertSame('unite', $data['unit']);
        $this->assertNull($data['expires_at']);
        $this->assertSame('inconnu', $data['expiry_status']);
    }

    public function test_store_item_with_food_id_snapshots_the_food_and_accepts_new_fields(): void
    {
        $user = $this->actingAsUser();
        $placard = $this->personalStock($user, 'Placard');
        $food = Food::factory()->create(['name' => 'Pâtes complètes', 'brand' => 'Barilla', 'barcode' => '8076809513753', 'calories' => 340]);

        $data = $this->postJson('/api/stocks/items', [
            'stock_id' => $placard->id,
            'food_id' => $food->id,
            'quantity' => 500,
            'unit' => 'g',
            'expires_at' => $this->daysFromToday(200),
            'expiry_kind' => 'ddm',
            'min_quantity' => 250,
            'opened_at' => self::TODAY,
        ])->assertCreated()->json('data');

        $this->assertSame($placard->id, $data['stock_id']);
        $this->assertSame('Placard', $data['stock_name']);
        $this->assertSame($food->id, $data['food_id']);
        $this->assertSame('Pâtes complètes', $data['food_name']);
        $this->assertSame('Barilla', $data['food_brand']);
        $this->assertSame('8076809513753', $data['food_barcode']);
        $this->assertSame('ddm', $data['expiry_kind']);
        $this->assertSame(250.0, $data['min_quantity']);
        $this->assertSame(self::TODAY, $data['opened_at']);
        $this->assertSame(340.0, $data['food']['calories']);
    }

    public function test_store_item_creates_a_food_from_the_barcode_payload_and_reuses_it(): void
    {
        $user = $this->actingAsUser();

        $first = $this->postJson('/api/stocks/items', [
            'food_name' => 'Yaourt nature',
            'food_brand' => 'Danone',
            'food_barcode' => '3033490004521',
            'quantity' => 4,
            'unit' => 'piece',
            'calories' => 61,
            'proteins' => 3.9,
            'carbs' => 4.7,
            'fat' => 3.1,
            'image_url' => 'https://img.test/yaourt.jpg',
        ])->assertCreated()->json('data');

        $food = Food::query()->where('barcode', '3033490004521')->first();
        $this->assertNotNull($food);
        $this->assertSame('Yaourt nature', $food->name);
        $this->assertSame('Danone', $food->brand);
        $this->assertSame(61.0, $food->calories);
        $this->assertSame(3.9, $food->proteins);
        $this->assertSame(4.7, $food->carbs);
        $this->assertSame(3.1, $food->fat);
        $this->assertSame('manual', $food->source_type);
        $this->assertSame($user->id, $food->created_by_user_id);
        $this->assertSame('https://img.test/yaourt.jpg', $food->image_url);

        $this->assertSame($food->id, $first['food_id']);
        $this->assertSame(61.0, $first['food']['calories']);

        // Même code-barres : l'aliment existant est réutilisé, aucun doublon.
        $second = $this->postJson('/api/stocks/items', [
            'food_name' => 'Yaourt nature (autre libellé)',
            'food_barcode' => '3033490004521',
            'calories' => 99,
        ])->assertCreated()->json('data');

        $this->assertSame($food->id, $second['food_id']);
        $this->assertSame('Yaourt nature', $second['food_name']);
        $this->assertSame(1, Food::query()->where('barcode', '3033490004521')->count());
        $this->assertSame(61.0, Food::query()->find($food->id)->calories);
    }

    public function test_store_item_with_open_food_facts_source_creates_a_community_food(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/stocks/items', [
            'food_name' => 'Nutella',
            'food_barcode' => '3017620422003',
            'source_type' => 'open_food_facts',
            'calories' => 539,
        ])->assertCreated();

        $food = Food::query()->where('barcode', '3017620422003')->firstOrFail();
        $this->assertSame('open_food_facts', $food->source_type);
        $this->assertNull($food->created_by_user_id);
        $this->assertNotNull($food->source_fetched_at);
    }

    public function test_store_item_with_a_non_numeric_barcode_does_not_create_a_food(): void
    {
        $this->actingAsUser();

        $data = $this->postJson('/api/stocks/items', [
            'food_name' => 'Article maison',
            'food_barcode' => 'MAISON-01',
        ])->assertCreated()->json('data');

        $this->assertNull($data['food_id']);
        $this->assertSame('MAISON-01', $data['food_barcode']);
        $this->assertSame(0, Food::query()->count());
    }

    public function test_store_item_validation_is_french(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/stocks/items', ['quantity' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.food_name.0', 'Indique le nom de l’aliment ou choisis un aliment existant.');

        $response = $this->postJson('/api/stocks/items', [
            'food_name' => 'Riz',
            'quantity' => 0,
            'expiry_kind' => 'autre',
            'source_type' => 'recipe',
            'food_id' => 999999,
        ])->assertStatus(422);

        $response->assertJsonValidationErrors(['quantity', 'expiry_kind', 'source_type', 'food_id']);
        $this->assertStringContainsString('quantité', $response->json('errors.quantity.0'));
        $this->assertStringContainsString('type de date limite', $response->json('errors.expiry_kind.0'));
    }

    public function test_store_item_in_a_foreign_location_is_not_found(): void
    {
        $other = $this->user();
        $foreign = $this->personalStock($other, 'Frigo');
        $this->actingAsUser();

        $this->postJson('/api/stocks/items', ['food_name' => 'Riz', 'stock_id' => $foreign->id])
            ->assertNotFound()
            ->assertJson(['message' => 'Introuvable.']);

        $this->assertSame(0, StockItem::query()->count());
    }

    public function test_update_item_changes_fields_and_moves_between_locations(): void
    {
        $user = $this->actingAsUser();
        $frigo = $this->personalStock($user, 'Frigo');
        $congel = $this->personalStock($user, 'Congélateur');
        $item = StockItem::factory()->create([
            'stock_id' => $frigo->id,
            'food_name' => 'Poulet',
            'quantity' => 2,
            'unit' => 'piece',
            'expires_at' => $this->daysFromToday(2),
        ]);

        $response = $this->putJson("/api/stocks/items/{$item->id}", [
            'quantity' => 1.5,
            'unit' => 'kg',
            'expires_at' => $this->daysFromToday(90),
            'expiry_kind' => 'ddm',
            'stock_id' => $congel->id,
            'food_name' => 'Blancs de poulet',
            'min_quantity' => 0.5,
            'opened_at' => self::TODAY,
        ])->assertOk()->assertJson(['message' => 'Élément du stock mis à jour.']);

        $this->assertSame(['message', 'data'], array_keys($response->json()));
        $data = $response->json('data');
        $this->assertSame(1.5, $data['quantity']);
        $this->assertSame('kg', $data['unit']);
        $this->assertSame($this->daysFromToday(90), $data['expires_at']);
        $this->assertSame('ddm', $data['expiry_kind']);
        $this->assertSame($congel->id, $data['stock_id']);
        $this->assertSame('Congélateur', $data['stock_name']);
        $this->assertSame('Blancs de poulet', $data['food_name']);
        $this->assertSame(0.5, $data['min_quantity']);
        $this->assertSame(self::TODAY, $data['opened_at']);

        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'stock_id' => $congel->id, 'food_name' => 'Blancs de poulet']);
    }

    public function test_update_item_can_clear_nullable_fields(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        $item = StockItem::factory()->create([
            'stock_id' => $stock->id,
            'expires_at' => $this->daysFromToday(2),
            'opened_at' => self::TODAY,
            'min_quantity' => 1,
        ]);

        $data = $this->putJson("/api/stocks/items/{$item->id}", [
            'expires_at' => null,
            'opened_at' => null,
            'min_quantity' => null,
        ])->assertOk()->json('data');

        $this->assertNull($data['expires_at']);
        $this->assertNull($data['days_left']);
        $this->assertNull($data['opened_at']);
        $this->assertNull($data['min_quantity']);
        $this->assertSame('inconnu', $data['expiry_status']);
    }

    public function test_update_item_quantity_zero_marks_depleted_and_positive_quantity_clears_it(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        $item = StockItem::factory()->create(['stock_id' => $stock->id, 'quantity' => 3]);

        $depleted = $this->putJson("/api/stocks/items/{$item->id}", ['quantity' => 0])->assertOk()->json('data');
        $this->assertSame(0.0, $depleted['quantity']);
        $this->assertTrue($depleted['is_depleted']);
        $this->assertNotNull($depleted['depleted_at']);

        $restored = $this->putJson("/api/stocks/items/{$item->id}", ['quantity' => 2])->assertOk()->json('data');
        $this->assertSame(2.0, $restored['quantity']);
        $this->assertFalse($restored['is_depleted']);
        $this->assertNull($restored['depleted_at']);
        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'quantity' => 2, 'depleted_at' => null]);
    }

    public function test_update_item_rejects_negative_quantity_in_french(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        $item = StockItem::factory()->create(['stock_id' => $stock->id]);

        $response = $this->putJson("/api/stocks/items/{$item->id}", ['quantity' => -1])->assertStatus(422);
        $response->assertJsonValidationErrors(['quantity']);
        $this->assertStringContainsString('quantité', $response->json('errors.quantity.0'));
    }

    public function test_update_item_to_a_foreign_location_is_not_found(): void
    {
        $other = $this->user();
        $foreign = $this->personalStock($other, 'Frigo');
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        $item = StockItem::factory()->create(['stock_id' => $stock->id]);

        $this->putJson("/api/stocks/items/{$item->id}", ['stock_id' => $foreign->id])
            ->assertNotFound()
            ->assertJson(['message' => 'Introuvable.']);

        $this->assertDatabaseHas('stock_items', ['id' => $item->id, 'stock_id' => $stock->id]);
    }

    public function test_destroy_item_deletes_it(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        $item = StockItem::factory()->create(['stock_id' => $stock->id]);

        $response = $this->deleteJson("/api/stocks/items/{$item->id}")
            ->assertOk()
            ->assertJson(['message' => 'Élément supprimé du stock.']);

        $this->assertSame(['message'], array_keys($response->json()));
        $this->assertDatabaseMissing('stock_items', ['id' => $item->id]);
    }

    public function test_items_of_another_user_are_not_found(): void
    {
        $other = $this->user();
        $foreignStock = $this->personalStock($other, 'Frigo');
        $foreign = StockItem::factory()->create(['stock_id' => $foreignStock->id, 'quantity' => 5]);
        $this->actingAsUser();

        $this->putJson("/api/stocks/items/{$foreign->id}", ['quantity' => 1])->assertNotFound()->assertJson(['message' => 'Introuvable.']);
        $this->deleteJson("/api/stocks/items/{$foreign->id}")->assertNotFound()->assertJson(['message' => 'Introuvable.']);
        $this->postJson("/api/stocks/items/{$foreign->id}/consume", ['quantity' => 1])->assertNotFound()->assertJson(['message' => 'Introuvable.']);

        $this->assertDatabaseHas('stock_items', ['id' => $foreign->id, 'quantity' => 5]);
    }
}
