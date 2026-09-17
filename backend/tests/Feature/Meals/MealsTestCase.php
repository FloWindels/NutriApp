<?php

namespace Tests\Feature\Meals;

use App\Models\Food;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Socle des tests du module M4 : un utilisateur avec un profil complet aux cibles fixes
 * (2000 kcal / 150 P / 200 G / 70 L, homme 30 ans, 175 cm, 70 kg, modéré, maintenir → plancher 1500).
 */
abstract class MealsTestCase extends TestCase
{
    use RefreshDatabase;

    public const TARGET_CALORIES = 2000;
    public const TARGET_PROTEINS = 150;
    public const TARGET_CARBS = 200;
    public const TARGET_FAT = 70;

    /** Journée passée fixe, indépendante du fuseau. */
    public const DATE = '2026-09-10';

    public const DAY_KEYS = ['date', 'meals', 'totals', 'targets', 'remaining', 'sport', 'next_meal_type', 'plancher_kcal'];

    public const MEAL_KEYS = ['id', 'date', 'type', 'name', 'consumed_at', 'notes', 'items', 'totals'];

    public const ITEM_KEYS = [
        'id', 'meal_id', 'source_type', 'food_id', 'recipe_id', 'stock_item_id', 'label', 'quantity', 'unit',
        'grams_equivalent', 'calories', 'proteins', 'carbs', 'fat', 'fiber', 'sugar', 'salt', 'is_estimate', 'food', 'created_at',
    ];

    public const TOTALS_KEYS = ['calories', 'proteins', 'carbs', 'fat', 'fiber', 'sugar', 'salt', 'is_partial'];

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser();
        Sanctum::actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    protected function makeUser(array $profile = []): User
    {
        $user = User::factory()->create();

        Profile::factory()->create(array_merge([
            'user_id' => $user->id,
            'sexe' => 'homme',
            'age' => 30,
            'taille' => 175,
            'poids' => 70,
            'poids_souhaite_kg' => 70,
            'poids_reference' => 70,
            'niveau_activite' => 'modere',
            'objectif_type' => 'maintenir',
            'regime_alimentaire' => 'omnivore',
            'calories_cibles' => self::TARGET_CALORIES,
            'proteines_cibles' => self::TARGET_PROTEINS,
            'glucides_cibles' => self::TARGET_CARBS,
            'lipides_cibles' => self::TARGET_FAT,
            'sport_coef_calories' => 100,
        ], $profile));

        return $user;
    }

    /**
     * Aliment à 250 kcal / 20 P / 30 G / 5 L pour 100 g, sans portion connue.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeFood(array $attributes = []): Food
    {
        return Food::factory()->create(array_merge([
            'name' => 'Poulet rôti',
            'brand' => 'Marque test',
            'barcode' => '3000000000017',
            'image_url' => 'https://img.test/poulet.jpg',
            'calories' => 250,
            'proteins' => 20,
            'carbs' => 30,
            'fat' => 5,
            'fiber' => 2,
            'sugar' => 1,
            'salt' => 0.5,
            'serving_size_g' => null,
            'category' => null,
            'density_g_per_ml' => null,
        ], $attributes));
    }

    /**
     * Recette publique 800 kcal / 40 P / 80 G / 20 L pour 4 portions (→ 200 kcal la portion).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeRecipe(array $attributes = []): Recipe
    {
        return Recipe::factory()->create(array_merge([
            'created_by_user_id' => $this->user->id,
            'title' => 'Poulet au curry',
            'calories' => 800,
            'proteins' => 40,
            'carbs' => 80,
            'fat' => 20,
            'servings' => 4,
            'is_public' => true,
            'is_estimate' => false,
        ], $attributes));
    }

    /**
     * POST /meals avec un seul élément.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $extra
     */
    protected function postItem(string $type, array $item, string $date = self::DATE, array $extra = []): TestResponse
    {
        return $this->postJson('/api/meals', [
            'date' => $date,
            'type' => $type,
            'items' => [$item],
        ] + $extra);
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<string, mixed>  $actual
     */
    protected function assertExactKeys(array $expected, array $actual, string $context = ''): void
    {
        $actualKeys = array_keys($actual);
        sort($expected);
        sort($actualKeys);

        $this->assertSame($expected, $actualKeys, 'Clés inattendues'.($context !== '' ? " ($context)" : '').'.');
    }
}
