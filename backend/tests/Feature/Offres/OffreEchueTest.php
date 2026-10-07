<?php

namespace Tests\Feature\Offres;

use App\Enums\Offre;
use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Une offre échue (ou rétrogradée) ne garde aucun effet de bord payant : plus de rappels de
 * péremption ni de repas planifiés, plus de bloc stock au tableau de bord, plus de décompte du
 * stock à l'enregistrement d'un repas. Les données, elles, restent intactes.
 */
class OffreEchueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        UserSetting::factory()->for($this->user)->create(['notif_rappel_repas' => true]);
        $this->today = Clock::today($this->user);
        Sanctum::actingAs($this->user, ['*']);
    }

    private function expirer(): void
    {
        $this->user->forceFill(['offre' => Offre::Complet, 'offre_expire_le' => now()->subDay()])->save();
    }

    private function stockItem(array $attributes = []): StockItem
    {
        $stock = Stock::query()->firstOrCreate(['user_id' => $this->user->id, 'household_id' => null, 'name' => 'Frigo']);

        return StockItem::factory()->for($stock)->create($attributes);
    }

    private function preparerRappels(): void
    {
        $demain = CarbonImmutable::parse($this->today)->addDay()->toDateString();
        $this->stockItem(['food_name' => 'Yaourt', 'expires_at' => $demain]);
        MealPlan::factory()->for($this->user)->create(['date' => $this->today, 'meal_type' => 'dejeuner', 'title' => 'Poulet rôti', 'status' => 'prevu']);
    }

    public function test_les_rappels_payants_s_eteignent_avec_l_offre(): void
    {
        $this->preparerRappels();

        $types = array_column($this->getJson('/api/notifications')->assertOk()->json('data'), 'type');
        $this->assertContains('stock_peremption', $types);
        $this->assertContains('rappel_repas', $types);

        $this->expirer();

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertExactJson(['data' => [], 'unread_count' => 0]);
    }

    public function test_le_tableau_de_bord_ne_montre_plus_le_stock(): void
    {
        $this->preparerRappels();

        $this->assertSame(1, $this->getJson('/api/dashboard')->assertOk()->json('data.stock.expiring_count'));

        $this->expirer();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');
        $this->assertSame(0, $data['stock']['expiring_count']);
        $this->assertSame([], $data['stock']['expiring']);
    }

    public function test_un_repas_ne_decompte_plus_le_stock(): void
    {
        $food = Food::factory()->create();
        $item = $this->stockItem(['food_id' => $food->id, 'quantity' => 500, 'unit' => 'g']);
        $this->expirer();

        $this->postJson('/api/meals', [
            'date' => $this->today,
            'type' => 'dejeuner',
            'items' => [['food_id' => $food->id, 'quantity' => 100, 'unit' => 'g', 'stock_item_id' => $item->id, 'decrement_stock' => true]],
        ])->assertCreated()->assertJsonPath('stock_decrements', []);

        $this->assertSame(500.0, $item->fresh()->quantity, 'Sans l’offre stock, le stock ne bouge pas.');
        $this->assertNull(MealItem::query()->firstOrFail()->stock_item_id);
    }
}
