<?php

namespace Tests\Feature\Stock;

use App\Models\StockItem;
use App\Models\UserSetting;

class StockAlertsTest extends StockTestCase
{
    public function test_alerts_use_the_expiry_window_from_the_user_settings(): void
    {
        $user = $this->actingAsUser(null, ['jours_alerte_peremption' => 5]);
        $stock = $this->personalStock($user);

        $today = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => self::TODAY]);
        $inFive = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(5)]);
        $inSix = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(6)]);
        $noDate = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null]);

        $response = $this->getJson('/api/stocks/alerts')->assertOk();

        $response->assertJsonStructure(['data' => ['expiring', 'expired', 'low']]);
        $this->assertSame(['data'], array_keys($response->json()));

        $expiring = collect($response->json('data.expiring'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$today->id, $inFive->id], $expiring);
        $this->assertNotContains($inSix->id, $expiring);
        $this->assertNotContains($noDate->id, $expiring);
        $this->assertSame([], $response->json('data.expired'));
        $this->assertSame([], $response->json('data.low'));

        // Les articles renvoyés ont la forme complète d'un article de stock.
        $this->assertEqualsCanonicalizing(
            array_merge($this->legacyItemKeys(), $this->newItemKeys()),
            array_keys($response->json('data.expiring.0'))
        );
    }

    public function test_default_window_is_three_days(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);

        $inThree = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(3)]);
        $inFour = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(4)]);

        $expiring = collect($this->getJson('/api/stocks/alerts')->assertOk()->json('data.expiring'))->pluck('id')->all();

        $this->assertSame([$inThree->id], $expiring);
        $this->assertNotContains($inFour->id, $expiring);
    }

    public function test_window_defaults_to_three_days_when_the_user_has_no_settings_row(): void
    {
        $user = $this->actingAsUser();
        UserSetting::query()->where('user_id', $user->id)->delete();
        $stock = $this->personalStock($user);

        StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(3)]);
        StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(4)]);

        $this->assertCount(1, $this->getJson('/api/stocks/alerts')->assertOk()->json('data.expiring'));
    }

    public function test_expired_and_low_lists(): void
    {
        $user = $this->actingAsUser();
        $stock = $this->personalStock($user);

        $expiredDlc = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(-1), 'expiry_kind' => 'dlc', 'quantity' => 2]);
        $expiredDdm = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(-10), 'expiry_kind' => 'ddm', 'quantity' => 2]);
        $lowByMin = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null, 'quantity' => 1, 'min_quantity' => 2]);
        $atMin = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null, 'quantity' => 2, 'min_quantity' => 2]);
        $aboveMin = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null, 'quantity' => 3, 'min_quantity' => 2]);
        $depleted = StockItem::factory()->depleted()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(-2)]);
        $noMin = StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null, 'quantity' => 0.5, 'min_quantity' => null]);

        $data = $this->getJson('/api/stocks/alerts')->assertOk()->json('data');

        $expired = collect($data['expired']);
        $this->assertEqualsCanonicalizing([$expiredDlc->id, $expiredDdm->id], $expired->pluck('id')->all());
        $this->assertSame('perime', $expired->firstWhere('id', $expiredDlc->id)['expiry_status']);
        $this->assertSame('ddm_depassee', $expired->firstWhere('id', $expiredDdm->id)['expiry_status']);
        // Un article épuisé n'est plus signalé comme périmé.
        $this->assertNotContains($depleted->id, $expired->pluck('id')->all());

        $low = collect($data['low'])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$lowByMin->id, $atMin->id, $depleted->id], $low);
        $this->assertNotContains($aboveMin->id, $low);
        $this->assertNotContains($noMin->id, $low);

        $this->assertSame([], $data['expiring']);
    }

    public function test_index_alert_counts_match_the_alerts_endpoint(): void
    {
        $user = $this->actingAsUser(null, ['jours_alerte_peremption' => 2]);
        $stock = $this->personalStock($user);

        StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(1)]);
        StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(2)]);
        StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(3)]);
        StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => $this->daysFromToday(-1)]);
        StockItem::factory()->create(['stock_id' => $stock->id, 'expires_at' => null, 'quantity' => 1, 'min_quantity' => 1]);
        StockItem::factory()->depleted()->create(['stock_id' => $stock->id, 'expires_at' => null]);

        $index = $this->getJson('/api/stocks')->assertOk();
        $alerts = $this->getJson('/api/stocks/alerts')->assertOk()->json('data');

        $this->assertSame(
            ['expiring_count' => 2, 'expired_count' => 1, 'low_count' => 2],
            $index->json('alerts')
        );
        $this->assertCount(2, $alerts['expiring']);
        $this->assertCount(1, $alerts['expired']);
        $this->assertCount(2, $alerts['low']);
    }

    public function test_alerts_are_scoped_to_the_user(): void
    {
        $other = $this->user();
        $foreignStock = $this->personalStock($other);
        StockItem::factory()->create(['stock_id' => $foreignStock->id, 'expires_at' => self::TODAY]);
        StockItem::factory()->depleted()->create(['stock_id' => $foreignStock->id]);

        $this->actingAsUser();

        $data = $this->getJson('/api/stocks/alerts')->assertOk()->json('data');
        $this->assertSame(['expiring' => [], 'expired' => [], 'low' => []], $data);
    }
}
