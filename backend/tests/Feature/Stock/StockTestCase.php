<?php

namespace Tests\Feature\Stock;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Stock;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\Stock\StockAlerts;
use App\Support\Clock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class StockTestCase extends TestCase
{
    use RefreshDatabase;

    /** Date fixe (midi UTC = 14 h à Paris) : « aujourd'hui » vaut 2026-09-16 dans les deux fuseaux. */
    protected const TODAY = '2026-09-16';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 12:00:00', 'UTC'));
        Clock::forget();
        StockAlerts::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function user(array $settings = []): User
    {
        $user = User::factory()->create();
        UserSetting::factory()->create(['user_id' => $user->id] + $settings);

        return $user;
    }

    protected function actingAsUser(?User $user = null, array $settings = []): User
    {
        $user ??= $this->user($settings);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function personalStock(User $user, string $name = 'Frigo'): Stock
    {
        return Stock::factory()->create(['user_id' => $user->id, 'household_id' => null, 'name' => $name]);
    }

    /**
     * Crée un foyer dont $owner est propriétaire et $members sont membres (users.household_id posé).
     *
     * @param  array<int, User>  $members
     */
    protected function household(User $owner, array $members = []): Household
    {
        $household = Household::factory()->create(['owner_id' => $owner->id]);
        HouseholdMember::factory()->owner()->create(['household_id' => $household->id, 'user_id' => $owner->id]);
        $owner->forceFill(['household_id' => $household->id])->save();

        foreach ($members as $member) {
            HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $member->id]);
            $member->forceFill(['household_id' => $household->id])->save();
        }

        return $household;
    }

    protected function daysFromToday(int $days): string
    {
        return Carbon::parse(self::TODAY)->addDays($days)->toDateString();
    }

    /** @return array<int, string> */
    protected function legacyItemKeys(): array
    {
        return [
            'id', 'stock_id', 'stock_name', 'food_id', 'food_name', 'food_barcode', 'food_brand',
            'quantity', 'unit', 'expires_at', 'days_left', 'created_at', 'updated_at',
        ];
    }

    /** @return array<int, string> */
    protected function newItemKeys(): array
    {
        return [
            'min_quantity', 'opened_at', 'depleted_at', 'is_depleted', 'expiry_kind', 'expiry_status',
            'food', 'household_id',
        ];
    }
}
