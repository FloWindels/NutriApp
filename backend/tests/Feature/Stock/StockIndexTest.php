<?php

namespace Tests\Feature\Stock;

use App\Models\Food;
use App\Models\Stock;
use App\Models\StockItem;

class StockIndexTest extends StockTestCase
{
    public function test_index_creates_the_three_default_locations_once(): void
    {
        $user = $this->actingAsUser();

        $first = $this->getJson('/api/stocks')->assertOk();
        $second = $this->getJson('/api/stocks')->assertOk();

        $names = collect($first->json('locations'))->pluck('name')->all();
        $this->assertSame(['Frigo', 'Congélateur', 'Placard'], $names);
        $this->assertCount(3, $second->json('locations'));
        $this->assertSame(3, Stock::query()->where('user_id', $user->id)->whereNull('household_id')->count());

        $second->assertJsonStructure([
            'data',
            'locations' => [['id', 'name', 'items_count']],
            'alerts' => ['expiring_count', 'expired_count', 'low_count'],
            'household_id',
        ]);
        $this->assertNull($second->json('household_id'));
        $this->assertSame(['expiring_count' => 0, 'expired_count' => 0, 'low_count' => 0], $second->json('alerts'));
    }

    public function test_index_does_not_duplicate_a_default_location_that_differs_only_by_case(): void
    {
        $user = $this->actingAsUser();
        $this->personalStock($user, 'frigo');

        $response = $this->getJson('/api/stocks')->assertOk();

        $names = collect($response->json('locations'))->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $this->assertCount(3, $names);
        $this->assertContains('frigo', $names);
        $this->assertSame(1, collect($names)->filter(fn ($n) => $n === 'frigo')->count());
    }

    public function test_index_works_under_the_v1_prefix_too(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/stocks')->assertOk()->assertJsonCount(3, 'locations');
    }

    public function test_index_hides_depleted_items_unless_requested(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        $active = StockItem::factory()->create(['stock_id' => $stock->id, 'quantity' => 2]);
        $depleted = StockItem::factory()->depleted()->create(['stock_id' => $stock->id]);

        $default = $this->getJson('/api/stocks')->assertOk();
        $this->assertSame([$active->id], collect($default->json('data'))->pluck('id')->all());
        $this->assertSame(1, collect($default->json('locations'))->firstWhere('id', $stock->id)['items_count']);
        // L'article épuisé compte toujours dans les alertes « stock bas ».
        $this->assertSame(1, $default->json('alerts.low_count'));

        $withDepleted = $this->getJson('/api/stocks?include_depleted=1')->assertOk();
        $ids = collect($withDepleted->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$active->id, $depleted->id], $ids);
        $this->assertSame(2, collect($withDepleted->json('locations'))->firstWhere('id', $stock->id)['items_count']);

        $depletedRow = collect($withDepleted->json('data'))->firstWhere('id', $depleted->id);
        $this->assertTrue($depletedRow['is_depleted']);
        $this->assertNotNull($depletedRow['depleted_at']);
    }

    public function test_index_orders_by_expiry_then_nulls_last_then_most_recently_updated(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);

        $soon = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(2)]);
        $later = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(10)]);
        $olderNoDate = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null]);
        $newerNoDate = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null]);

        StockItem::query()->whereKey($olderNoDate->id)->update(['updated_at' => now()->subHours(5)]);
        StockItem::query()->whereKey($newerNoDate->id)->update(['updated_at' => now()->subHour()]);

        $response = $this->getJson('/api/stocks')->assertOk();

        $this->assertSame(
            [$soon->id, $later->id, $newerNoDate->id, $olderNoDate->id],
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_item_payload_exposes_the_legacy_keys_plus_the_new_ones_with_correct_types(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user, 'Placard');
        $food = Food::factory()->create([
            'name' => 'Riz basmati',
            'brand' => 'Marque X',
            'barcode' => '3017620422003',
            'calories' => 350.5,
            'proteins' => 7.2,
            'carbs' => 78,
            'fat' => 0.9,
            'image_url' => 'https://img.test/riz.jpg',
            'serving_size_g' => 60,
        ]);
        StockItem::factory()->create([
            'stock_id' => $stock->id,
            'food_id' => $food->id,
            'food_name' => $food->name,
            'food_barcode' => $food->barcode,
            'food_brand' => $food->brand,
            'quantity' => 500,
            'unit' => 'g',
            'expires_at' => $this->daysFromToday(12),
            'min_quantity' => 100,
            'opened_at' => self::TODAY,
        ]);

        $row = $this->getJson('/api/stocks')->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            array_merge($this->legacyItemKeys(), $this->newItemKeys()),
            array_keys($row)
        );

        $this->assertIsInt($row['id']);
        $this->assertIsInt($row['stock_id']);
        $this->assertSame('Placard', $row['stock_name']);
        $this->assertIsInt($row['food_id']);
        $this->assertSame('3017620422003', $row['food_barcode']);
        $this->assertIsFloat($row['quantity']);
        $this->assertSame(500.0, $row['quantity']);
        $this->assertSame('g', $row['unit']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['expires_at']);
        $this->assertSame($this->daysFromToday(12), $row['expires_at']);
        $this->assertIsInt($row['days_left']);
        $this->assertSame(12, $row['days_left']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $row['created_at']);
        $this->assertIsFloat($row['min_quantity']);
        $this->assertSame(100.0, $row['min_quantity']);
        $this->assertSame(self::TODAY, $row['opened_at']);
        $this->assertNull($row['depleted_at']);
        $this->assertIsBool($row['is_depleted']);
        $this->assertFalse($row['is_depleted']);
        $this->assertSame('dlc', $row['expiry_kind']);
        $this->assertSame('ok', $row['expiry_status']);
        $this->assertNull($row['household_id']);

        $this->assertSame(
            ['calories', 'proteins', 'carbs', 'fat', 'image_url', 'serving_size_g'],
            array_keys($row['food'])
        );
        $this->assertSame(350.5, $row['food']['calories']);
        $this->assertSame(60.0, $row['food']['serving_size_g']);
        $this->assertSame('https://img.test/riz.jpg', $row['food']['image_url']);
    }

    public function test_item_without_food_or_expiry_has_null_food_and_unknown_status(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);
        StockItem::factory()->create(['stock_id' => $stock->id, 'food_id' => null, 'expires_at' => null]);

        $row = $this->getJson('/api/stocks')->assertOk()->json('data.0');

        $this->assertNull($row['food']);
        $this->assertNull($row['food_id']);
        $this->assertNull($row['expires_at']);
        $this->assertNull($row['days_left']);
        $this->assertSame('inconnu', $row['expiry_status']);
    }

    public function test_expiry_status_follows_the_user_alert_window(): void
    {
        $user = $this->actingAsUser(null, ['jours_alerte_peremption' => 5]);
        $stock = $this->personalStock($user);

        $rows = [
            'perime' => StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(-1), 'expiry_kind' => 'dlc']),
            'ddm_depassee' => StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(-3), 'expiry_kind' => 'ddm']),
            'aujourdhui' => StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => self::TODAY]),
            'bientot' => StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(5)]),
            'ok' => StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(6)]),
            'inconnu' => StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null]),
        ];

        $data = collect($this->getJson('/api/stocks')->assertOk()->json('data'));

        foreach ($rows as $expected => $item) {
            $this->assertSame($expected, $data->firstWhere('id', $item->id)['expiry_status'], "Statut attendu « {$expected} »");
        }

        $this->assertSame(-1, $data->firstWhere('id', $rows['perime']->id)['days_left']);
        $this->assertSame(0, $data->firstWhere('id', $rows['aujourdhui']->id)['days_left']);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/stocks')->assertStatus(401)->assertJson(['message' => 'Non authentifié.']);
    }
}
