<?php

namespace Tests\Feature\Contract;

use App\Models\Food;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gel du contrat legacy (brief §0.3) pour les aliments et les recettes :
 * jeux de clés exacts (legacy + additifs §3/§5), types de sérialisation, enveloppes.
 */
class LegacyFoodRecipeContractTest extends TestCase
{
    use RefreshDatabase;

    public const FOOD_KEYS = [
        // legacy
        'id', 'barcode', 'name', 'brand', 'image_url', 'calories', 'fat', 'carbs', 'proteins',
        'source_type', 'created_by_user_id', 'is_owner', 'created_at', 'updated_at',
        // additifs §3.2
        'fiber', 'sugar', 'salt', 'serving_size_g', 'serving_label', 'category', 'allergens',
        'per_unit', 'is_verified', 'source_fetched_at', 'is_estimate', 'is_favorite',
    ];

    public const RECIPE_KEYS = [
        // legacy
        'id', 'title', 'description', 'prep_time_minutes', 'calories', 'image_url', 'ingredients',
        'ingredients_count', 'is_public', 'is_owner', 'created_by_user_id', 'created_at', 'updated_at',
        // additifs §5
        'servings', 'proteins', 'carbs', 'fat', 'tags', 'meal_types', 'per_serving', 'has_macros', 'is_estimate',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_food_payload_keys_and_types(): void
    {
        $food = Food::factory()->create([
            'barcode' => '3017620422003', 'calories' => 539.5, 'fat' => 30.9, 'carbs' => 57.5, 'proteins' => 6.3,
            'created_by_user_id' => $this->user->id, 'allergens' => ['en:milk'],
        ]);

        $data = $this->getJson('/api/foods/'.$food->id)->assertOk()->json('data');

        $this->assertSame(self::FOOD_KEYS, array_keys($data));
        $this->assertIsInt($data['id']);
        $this->assertIsString($data['barcode']);
        $this->assertIsFloat($data['calories']);
        $this->assertIsFloat($data['fat']);
        $this->assertIsFloat($data['carbs']);
        $this->assertIsFloat($data['proteins']);
        $this->assertIsBool($data['is_owner']);
        $this->assertIsBool($data['is_verified']);
        $this->assertIsBool($data['is_estimate']);
        $this->assertIsBool($data['is_favorite']);
        $this->assertIsArray($data['allergens']);
        $this->assertSame('100g', $data['per_unit']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $data['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['updated_at']);
    }

    public function test_food_barcode_may_be_null_and_lists_share_the_same_keys(): void
    {
        Food::factory()->sansCodeBarres()->create(['name' => 'Soupe maison']);

        $search = $this->getJson('/api/foods/search?q=soupe')->assertOk();

        $this->assertSame(['data', 'meta'], array_keys($search->json()));
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total', 'off_queried'], array_keys($search->json('meta')));
        $this->assertSame(self::FOOD_KEYS, array_keys($search->json('data.0')));
        $this->assertNull($search->json('data.0.barcode'));
    }

    public function test_food_mutation_envelopes(): void
    {
        $created = $this->postJson('/api/foods', ['name' => 'Nouveau', 'calories' => 10])->assertCreated();
        $this->assertSame(['message', 'data'], array_keys($created->json()));
        $this->assertSame(self::FOOD_KEYS, array_keys($created->json('data')));

        $updated = $this->putJson('/api/foods/'.$created->json('data.id'), ['name' => 'Renommé'])->assertOk();
        $this->assertSame(['message', 'data'], array_keys($updated->json()));

        $favorite = $this->postJson('/api/foods/'.$created->json('data.id').'/favorite')->assertCreated();
        $this->assertSame(['message', 'data'], array_keys($favorite->json()));

        $favorites = $this->getJson('/api/foods/favorites')->assertOk();
        $this->assertSame(['data'], array_keys($favorites->json()));
        $this->assertSame(self::FOOD_KEYS, array_keys($favorites->json('data.0')));

        // Code-barres inconnu en local ET absent d'Open Food Facts : 404 legacy conservé.
        Http::fake([config('services.off.base_url').'/*' => Http::response(['status' => 0], 200)]);
        $this->getJson('/api/foods/barcode/00000000')->assertStatus(404)->assertJsonStructure(['message']);
    }

    public function test_recipe_payload_keys_and_types(): void
    {
        $recipe = Recipe::factory()->create([
            'created_by_user_id' => $this->user->id, 'calories' => 800.5, 'servings' => 2.5,
            'proteins' => 40.5, 'carbs' => 100, 'fat' => 20, 'tags' => ['rapide'], 'meal_types' => ['diner'],
        ]);

        $data = $this->getJson('/api/recipes/'.$recipe->id)->assertOk()->json('data');

        $this->assertSame(self::RECIPE_KEYS, array_keys($data));
        $this->assertIsInt($data['id']);
        $this->assertIsFloat($data['calories']);
        $this->assertIsFloat($data['servings']);
        $this->assertIsFloat($data['proteins']);
        $this->assertIsBool($data['is_public']);
        $this->assertIsBool($data['is_owner']);
        $this->assertIsBool($data['has_macros']);
        $this->assertIsBool($data['is_estimate']);
        $this->assertIsArray($data['tags']);
        $this->assertIsArray($data['meal_types']);
        $this->assertIsArray($data['ingredients']);
        $this->assertIsInt($data['ingredients_count']);
        $this->assertSame(['calories', 'proteins', 'carbs', 'fat'], array_keys($data['per_serving']));
        $this->assertSame(['name', 'ean', 'amount', 'unit'], array_keys($data['ingredients'][0]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['created_at']);
    }

    public function test_recipe_list_and_mutation_envelopes(): void
    {
        Recipe::factory()->create(['created_by_user_id' => $this->user->id]);

        $list = $this->getJson('/api/recipes')->assertOk();
        $this->assertSame(['data', 'meta'], array_keys($list->json()));
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($list->json('meta')));
        $this->assertSame(self::RECIPE_KEYS, array_keys($list->json('data.0')));

        $created = $this->postJson('/api/recipes', ['title' => 'Contrat', 'calories' => 100])->assertCreated();
        $this->assertSame(['message', 'data'], array_keys($created->json()));
        $this->assertSame(self::RECIPE_KEYS, array_keys($created->json('data')));

        $updated = $this->putJson('/api/recipes/'.$created->json('data.id'), ['title' => 'Contrat 2', 'calories' => 120])->assertOk();
        $this->assertSame(['message', 'data'], array_keys($updated->json()));

        $deleted = $this->deleteJson('/api/recipes/'.$created->json('data.id'))->assertOk();
        $this->assertSame(['message'], array_keys($deleted->json()));

        $estimate = $this->postJson('/api/recipes/estimate', ['ingredients' => [['name' => 'riz', 'amount' => 100, 'unit' => 'g']]])->assertOk();
        $this->assertSame(['data'], array_keys($estimate->json()));
        $this->assertSame(
            ['calories', 'proteins', 'carbs', 'fat', 'resolved_count', 'total_count', 'is_estimate', 'details'],
            array_keys($estimate->json('data'))
        );
    }
}
