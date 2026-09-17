<?php

namespace Tests\Feature\Stock;

use App\Models\Stock;
use App\Models\StockItem;

class StockLocationTest extends StockTestCase
{
    public function test_store_location_returns_the_legacy_shape(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/stocks', ['name' => '  Cave  '])
            ->assertCreated()
            ->assertJson(['message' => 'Lieu de stock créé.']);

        $this->assertSame(['message', 'data'], array_keys($response->json()));
        $this->assertSame(['id', 'name'], array_keys($response->json('data')));
        $this->assertIsInt($response->json('data.id'));
        $this->assertSame('Cave', $response->json('data.name'));

        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'household_id' => null, 'name' => 'Cave']);
    }

    public function test_store_location_rejects_a_duplicate_name_case_insensitively(): void
    {
        $user = $this->actingAsUser();
        $this->personalStock($user, 'Cave');

        $this->postJson('/api/stocks', ['name' => 'CAVE'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ce lieu existe déjà.');

        $this->assertSame(1, Stock::query()->where('user_id', $user->id)->count());
    }

    public function test_store_location_allows_the_same_name_for_another_user(): void
    {
        $other = $this->user();
        $this->personalStock($other, 'Cave');
        $this->actingAsUser();

        $this->postJson('/api/stocks', ['name' => 'Cave'])->assertCreated();
    }

    public function test_store_location_validates_name_in_french(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/stocks', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Le champ nom du lieu est obligatoire.');

        $this->postJson('/api/stocks', ['name' => str_repeat('a', 81)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_location_renames_and_enforces_uniqueness(): void
    {
        $user = $this->actingAsUser();
        $cave = $this->personalStock($user, 'Cave');
        $this->personalStock($user, 'Garage');

        $response = $this->putJson("/api/stocks/{$cave->id}", ['name' => 'Cellier'])
            ->assertOk()
            ->assertJson(['message' => 'Lieu de stock renommé.']);

        $this->assertSame(['id', 'name'], array_keys($response->json('data')));
        $this->assertSame('Cellier', $response->json('data.name'));
        $this->assertDatabaseHas('stocks', ['id' => $cave->id, 'name' => 'Cellier']);

        // Renommer vers son propre nom (autre casse) est permis.
        $this->putJson("/api/stocks/{$cave->id}", ['name' => 'cellier'])->assertOk();

        // Renommer vers un autre lieu existant est refusé.
        $this->putJson("/api/stocks/{$cave->id}", ['name' => 'garage'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ce lieu existe déjà.');
    }

    public function test_destroy_location_refuses_a_non_empty_location(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user, 'Cave');
        StockItem::factory()->create(['stock_id' => $stock->id]);

        $this->deleteJson("/api/stocks/{$stock->id}")
            ->assertStatus(422)
            ->assertJson(['message' => 'Vide ce lieu avant de le supprimer.']);

        $this->assertDatabaseHas('stocks', ['id' => $stock->id]);
    }

    public function test_destroy_location_deletes_an_empty_location(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user, 'Cave');

        $this->deleteJson("/api/stocks/{$stock->id}")
            ->assertOk()
            ->assertJson(['message' => 'Lieu de stock supprimé.']);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_locations_of_another_user_are_not_found(): void
    {
        $other = $this->user();
        $foreign = $this->personalStock($other, 'Cave');
        $this->actingAsUser();

        $this->putJson("/api/stocks/{$foreign->id}", ['name' => 'Piraté'])
            ->assertNotFound()
            ->assertJson(['message' => 'Introuvable.']);

        $this->deleteJson("/api/stocks/{$foreign->id}")
            ->assertNotFound()
            ->assertJson(['message' => 'Introuvable.']);

        $this->assertDatabaseHas('stocks', ['id' => $foreign->id, 'name' => 'Cave']);
    }

    public function test_non_numeric_location_id_is_not_found(): void
    {
        $this->actingAsUser();

        $this->deleteJson('/api/stocks/abc')->assertNotFound();
    }
}
