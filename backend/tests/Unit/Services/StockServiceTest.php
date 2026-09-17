<?php

namespace Tests\Unit\Services;

use App\Models\Food;
use App\Models\ShoppingItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Décrément de stock (brief §6.2). Dépend du schéma (migrations A1) : SQLite :memory:.
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockService;
        $this->user = User::factory()->create();
    }

    private function stock(array $attributes = []): Stock
    {
        return Stock::query()->forceCreate(array_merge([
            'user_id' => $this->user->id,
            'household_id' => null,
            'name' => 'Frigo',
        ], $attributes));
    }

    private function item(Stock $stock, array $attributes = []): StockItem
    {
        return StockItem::query()->forceCreate(array_merge([
            'stock_id' => $stock->id,
            'food_id' => null,
            'food_name' => 'Lait demi-écrémé',
            'quantity' => 1000,
            'unit' => 'ml',
            'expires_at' => null,
        ], $attributes));
    }

    public function test_decrement_meme_unite(): void
    {
        $item = $this->item($this->stock());

        $result = $this->service->decrement($item, 250, 'ml');

        $this->assertSame($item->id, $result['stock_item_id']);
        $this->assertSame(1000.0, $result['previous_quantity']);
        $this->assertSame(750.0, $result['new_quantity']);
        $this->assertSame('ml', $result['unit']);
        $this->assertFalse($result['depleted']);
        $this->assertSame(750.0, (float) $item->fresh()->quantity);
        $this->assertNull($item->fresh()->depleted_at);
        $this->assertSame(0, ShoppingItem::query()->count());
    }

    public function test_decrement_avec_alias_cl(): void
    {
        $item = $this->item($this->stock());

        $result = $this->service->decrement($item, 25, 'cl');

        $this->assertSame(750.0, $result['new_quantity']);
    }

    public function test_epuisement_ne_supprime_jamais_et_cree_un_article_de_courses(): void
    {
        $item = $this->item($this->stock(), ['quantity' => 300]);

        $result = $this->service->decrement($item, 800, 'ml');

        $this->assertSame(0.0, $result['new_quantity']);
        $this->assertTrue($result['depleted']);

        $fresh = $item->fresh();
        $this->assertNotNull($fresh, 'L’article ne doit jamais être supprimé.');
        $this->assertSame(0.0, (float) $fresh->quantity);
        $this->assertNotNull($fresh->depleted_at);

        $shopping = ShoppingItem::query()->first();
        $this->assertNotNull($shopping);
        $this->assertSame('Lait demi-écrémé', $shopping->label);
        $this->assertSame('auto_stock', $shopping->source);
        $this->assertSame($this->user->id, (int) $shopping->user_id);
        $this->assertNull($shopping->household_id);
        $this->assertFalse((bool) $shopping->checked);

        // Second épuisement : pas de doublon tant que l'article de courses n'est pas coché.
        $this->service->decrement($item, 10, 'ml');
        $this->assertSame(1, ShoppingItem::query()->count());

        // Une fois coché, un nouvel article peut être créé.
        $shopping->forceFill(['checked' => true])->save();
        $this->service->decrement($item, 10, 'ml');
        $this->assertSame(2, ShoppingItem::query()->count());
    }

    public function test_conversion_via_les_grammes_avec_la_portion_du_produit(): void
    {
        $food = Food::query()->forceCreate([
            'barcode' => null, 'name' => 'Œuf', 'calories' => 140, 'fat' => 10, 'carbs' => 1, 'proteins' => 12,
            'source_type' => 'manual', 'serving_size_g' => 55,
        ]);
        $item = $this->item($this->stock(), ['food_id' => $food->id, 'food_name' => 'Œufs', 'quantity' => 500, 'unit' => 'g']);

        $result = $this->service->decrement($item, 2, 'piece');

        $this->assertSame(390.0, $result['new_quantity']);
        $this->assertSame('g', $result['unit']);
    }

    public function test_conversion_kg_vers_g_et_pieces_via_alias_unite(): void
    {
        $kg = $this->item($this->stock(), ['food_name' => 'Farine', 'quantity' => 2, 'unit' => 'kg']);
        $this->assertSame(1.5, $this->service->decrement($kg, 500, 'g')['new_quantity']);

        $pieces = $this->item($this->stock(['name' => 'Placard']), ['food_name' => 'Pommes', 'quantity' => 6, 'unit' => 'piece']);
        $this->assertSame(4.0, $this->service->decrement($pieces, 2, 'unite')['new_quantity']);
    }

    public function test_unites_non_convertibles_retirent_la_quantite_telle_quelle(): void
    {
        $item = $this->item($this->stock(), ['food_name' => 'Boîte mystère', 'quantity' => 5, 'unit' => 'boite']);

        $result = $this->service->decrement($item, 2, 'sachet');

        $this->assertSame(3.0, $result['new_quantity']);
    }

    public function test_stock_de_foyer_pousse_l_article_dans_la_liste_du_foyer(): void
    {
        $stock = $this->stock(['user_id' => null, 'household_id' => 7]);
        $item = $this->item($stock, ['quantity' => 100]);

        $this->service->decrement($item, 100, 'ml');

        $shopping = ShoppingItem::query()->firstOrFail();
        $this->assertNull($shopping->user_id);
        $this->assertSame(7, (int) $shopping->household_id);
    }
}
