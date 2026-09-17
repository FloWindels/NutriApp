<?php

namespace Tests\Feature\Household;

use App\Models\MealPlan;
use App\Models\ShoppingItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Support\OwnerScope;
use App\Support\StockScope;

/**
 * Fusion des portées personnelles à la création / adhésion (brief §10) :
 * stocks par LOWER(name), articles de courses et plans de repas.
 */
class HouseholdStockMergeTest extends HouseholdTestCase
{
    public function test_create_rattache_tous_les_lieux_personnels_au_foyer(): void
    {
        $user = $this->login(User::factory()->create());
        $frigo = Stock::factory()->create(['user_id' => $user->id, 'name' => 'Frigo']);
        $placard = Stock::factory()->create(['user_id' => $user->id, 'name' => 'Placard']);
        StockItem::factory()->count(2)->create(['stock_id' => $frigo->id, 'quantity' => 2]);
        StockItem::factory()->create(['stock_id' => $placard->id, 'quantity' => 1]);

        $response = $this->postJson('/api/household', ['name' => 'Foyer'])
            ->assertCreated()
            ->assertJsonPath('merged_stock_items', 3)
            ->assertJsonPath('data.stock_items_count', 3);

        $householdId = $response->json('data.id');
        $this->assertDatabaseHas('stocks', ['id' => $frigo->id, 'user_id' => null, 'household_id' => $householdId]);
        $this->assertDatabaseHas('stocks', ['id' => $placard->id, 'user_id' => null, 'household_id' => $householdId]);
        $this->assertSame(0, Stock::query()->personalOf($user)->count());
        $this->assertSame(2, StockScope::query($user->fresh())->count());
        $this->assertHouseholdInvariant();
    }

    public function test_join_fusionne_les_lieux_de_meme_nom_et_rattache_les_autres(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        $sharedFrigo = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        StockItem::factory()->create(['stock_id' => $sharedFrigo->id, 'food_name' => 'Beurre']);

        $user = $this->login(User::factory()->create());
        $myFrigo = Stock::factory()->create(['user_id' => $user->id, 'name' => 'frigo']); // même nom, casse différente
        $myCave = Stock::factory()->create(['user_id' => $user->id, 'name' => 'Cave']);
        $lait = StockItem::factory()->create(['stock_id' => $myFrigo->id, 'food_name' => 'Lait']);
        $oeufs = StockItem::factory()->create(['stock_id' => $myFrigo->id, 'food_name' => 'Œufs']);
        $vin = StockItem::factory()->create(['stock_id' => $myCave->id, 'food_name' => 'Jus de raisin']);

        $this->postJson('/api/household/join', ['invite_code' => $household->invite_code])
            ->assertOk()
            ->assertJsonPath('merged_stock_items', 3);

        // Même nom → articles déplacés, lieu personnel supprimé.
        $this->assertDatabaseMissing('stocks', ['id' => $myFrigo->id]);
        $this->assertDatabaseHas('stock_items', ['id' => $lait->id, 'stock_id' => $sharedFrigo->id]);
        $this->assertDatabaseHas('stock_items', ['id' => $oeufs->id, 'stock_id' => $sharedFrigo->id]);
        $this->assertSame(3, StockItem::query()->where('stock_id', $sharedFrigo->id)->count());

        // Autre nom → lieu rattaché au foyer, articles intacts.
        $this->assertDatabaseHas('stocks', ['id' => $myCave->id, 'user_id' => null, 'household_id' => $household->id]);
        $this->assertDatabaseHas('stock_items', ['id' => $vin->id, 'stock_id' => $myCave->id]);

        $this->assertSame(0, Stock::query()->personalOf($user)->count());
        $this->assertEqualsCanonicalizing(
            [$sharedFrigo->id, $myCave->id],
            StockScope::query($user->fresh())->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame(4, StockScope::items($user->fresh())->count());
        $this->assertHouseholdInvariant();
    }

    public function test_join_deplace_les_courses_et_plans_personnels_dans_le_foyer(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);

        $user = $this->login(User::factory()->create());
        $item = ShoppingItem::factory()->create(['user_id' => $user->id, 'household_id' => null, 'label' => 'Pain']);
        $plan = MealPlan::factory()->create(['user_id' => $user->id, 'household_id' => null]);

        // Lignes d'un autre utilisateur sans foyer : jamais touchées.
        $stranger = User::factory()->create();
        $foreign = ShoppingItem::factory()->create(['user_id' => $stranger->id, 'household_id' => null]);

        $this->postJson('/api/household/join', ['invite_code' => $household->invite_code])->assertOk();

        $this->assertDatabaseHas('shopping_items', ['id' => $item->id, 'user_id' => $user->id, 'household_id' => $household->id]);
        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id, 'user_id' => $user->id, 'household_id' => $household->id]);
        $this->assertDatabaseHas('shopping_items', ['id' => $foreign->id, 'household_id' => null]);

        $fresh = $user->fresh();
        $this->assertSame(1, OwnerScope::apply(ShoppingItem::query(), $fresh)->count());
        $this->assertSame(1, OwnerScope::apply(MealPlan::query(), $fresh)->count());
        $this->assertSame(1, OwnerScope::apply(ShoppingItem::query(), $owner->fresh())->count());
    }

    public function test_stock_items_count_ignore_les_articles_epuises(): void
    {
        $owner = $this->login(User::factory()->create());
        $household = $this->householdOwnedBy($owner);
        $stock = Stock::factory()->forHousehold($household)->create(['name' => 'Placard']);
        StockItem::factory()->count(2)->create(['stock_id' => $stock->id, 'quantity' => 3]);
        StockItem::factory()->depleted()->create(['stock_id' => $stock->id]);

        $this->getJson('/api/household')
            ->assertOk()
            ->assertJsonPath('data.stock_items_count', 2);
    }

    public function test_service_attach_personal_stocks_retourne_le_nombre_d_articles(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        Stock::factory()->forHousehold($household)->create(['name' => 'Congélateur']);

        $user = User::factory()->create();
        $mine = Stock::factory()->create(['user_id' => $user->id, 'name' => 'CONGÉLATEUR']);
        StockItem::factory()->count(4)->create(['stock_id' => $mine->id]);

        $this->assertSame(4, $this->service->attachPersonalStocks($user, $household));
        $this->assertSame(0, $this->service->attachPersonalStocks($user, $household));
        $this->assertSame(1, Stock::query()->ofHousehold($household->id)->count());
    }
}
