<?php

namespace Tests\Unit\Services;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\Recipe;
use App\Services\MealCalculator;
use App\Services\NutritionCalculator;
use App\Services\SportNutrition;
use Tests\TestCase;

/**
 * Mathématiques des instantanés (brief §4.2) : modèles transitoires, aucune écriture en base.
 */
class MealCalculatorTest extends TestCase
{
    private MealCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new MealCalculator(new NutritionCalculator, new SportNutrition);
    }

    private function food(array $attributes = []): Food
    {
        return (new Food)->forceFill(array_merge([
            'id' => 42,
            'name' => 'Yaourt nature',
            'calories' => 200,
            'proteins' => 10,
            'carbs' => 20,
            'fat' => 5,
            'fiber' => 3,
            'sugar' => null,
            'salt' => 0.5,
            'serving_size_g' => null,
            'density_g_per_ml' => null,
            'category' => null,
        ], $attributes));
    }

    public function test_snapshot_aliment_en_grammes(): void
    {
        $s = $this->calc->snapshot(['food_id' => 42, 'quantity' => 150, 'unit' => 'g'], $this->food());

        $this->assertSame('food', $s['source_type']);
        $this->assertSame(42, $s['food_id']);
        $this->assertNull($s['recipe_id']);
        $this->assertSame('Yaourt nature', $s['label']);
        $this->assertSame(150.0, $s['quantity']);
        $this->assertSame('g', $s['unit']);
        $this->assertSame(150.0, $s['grams_equivalent']);
        $this->assertSame('per_100g', $s['ref_basis']);
        $this->assertSame(300.0, $s['calories']);
        $this->assertSame(15.0, $s['proteins']);
        $this->assertSame(30.0, $s['carbs']);
        $this->assertSame(7.5, $s['fat']);
        $this->assertSame(4.5, $s['fiber']);
        $this->assertNull($s['sugar']);
        $this->assertSame(0.75, $s['salt']);
        $this->assertSame(200.0, $s['ref_calories']);
        $this->assertSame(10.0, $s['ref_proteins']);
        $this->assertFalse($s['is_estimate']);
    }

    public function test_snapshot_aliment_en_pieces_utilise_la_portion_du_produit(): void
    {
        $s = $this->calc->snapshot(['quantity' => 2, 'unit' => 'pièce'], $this->food(['serving_size_g' => 55]));

        $this->assertSame('piece', $s['unit']);
        $this->assertSame(2.0, $s['quantity']);
        $this->assertSame(110.0, $s['grams_equivalent']);
        $this->assertSame(220.0, $s['calories']);
        $this->assertSame(55.0, $s['ref_serving_size_g']);
        $this->assertTrue($s['is_estimate']);
    }

    public function test_snapshot_aliment_avec_alias_cl_convertit_en_ml(): void
    {
        $s = $this->calc->snapshot(['quantity' => 20, 'unit' => 'cl'], $this->food(['name' => 'Lait demi-écrémé']));

        $this->assertSame('ml', $s['unit']);
        $this->assertSame(200.0, $s['quantity']);
        $this->assertSame(200.0, $s['grams_equivalent']);
        $this->assertSame(400.0, $s['calories']);
        $this->assertTrue($s['is_estimate']); // densité inconnue
    }

    public function test_snapshot_aliment_ml_avec_densite_connue_n_est_pas_une_estimation(): void
    {
        $s = $this->calc->snapshot(['quantity' => 100, 'unit' => 'ml'], $this->food(['density_g_per_ml' => 0.92]));

        $this->assertSame(92.0, $s['grams_equivalent']);
        $this->assertFalse($s['is_estimate']);
    }

    public function test_snapshot_aliment_sans_macros_est_une_estimation(): void
    {
        $s = $this->calc->snapshot(['quantity' => 100, 'unit' => 'g'], $this->food(['proteins' => null]));

        $this->assertSame(0.0, $s['proteins']);
        $this->assertNull($s['ref_proteins']);
        $this->assertTrue($s['is_estimate']);
    }

    public function test_snapshot_recette_par_portion(): void
    {
        $recipe = (new Recipe)->forceFill([
            'id' => 7, 'title' => 'Chili végétarien', 'calories' => 800, 'proteins' => 40, 'carbs' => 80, 'fat' => 20,
            'servings' => 4, 'is_estimate' => false,
        ]);

        $s = $this->calc->snapshot(['recipe_id' => 7, 'quantity' => 1.5, 'unit' => 'g'], null, $recipe);

        $this->assertSame('recipe', $s['source_type']);
        $this->assertSame(7, $s['recipe_id']);
        $this->assertSame('portion', $s['unit']); // forcé pour une recette
        $this->assertNull($s['grams_equivalent']);
        $this->assertSame('per_serving', $s['ref_basis']);
        $this->assertSame(200.0, $s['ref_calories']);
        $this->assertSame(300.0, $s['calories']);
        $this->assertSame(15.0, $s['proteins']);
        $this->assertSame(30.0, $s['carbs']);
        $this->assertSame(7.5, $s['fat']);
        $this->assertFalse($s['is_estimate']);
    }

    public function test_snapshot_recette_sans_macros_ne_garde_que_les_calories(): void
    {
        $recipe = (new Recipe)->forceFill(['id' => 8, 'title' => 'Soupe', 'calories' => 300, 'servings' => 2]);

        $s = $this->calc->snapshot(['recipe_id' => 8, 'quantity' => 1], null, $recipe);

        $this->assertSame(150.0, $s['calories']);
        $this->assertSame(0.0, $s['proteins']);
        $this->assertNull($s['ref_proteins']);
        $this->assertTrue($s['is_estimate']);
    }

    public function test_snapshot_personnalise_absolu(): void
    {
        $s = $this->calc->snapshot([
            'custom' => ['label' => 'Part de pizza', 'calories' => 800, 'proteins' => 30, 'carbs' => 90, 'fat' => 35],
            'quantity' => 2,
            'unit' => 'portion',
        ]);

        $this->assertSame('custom', $s['source_type']);
        $this->assertSame('Part de pizza', $s['label']);
        $this->assertSame('absolute', $s['ref_basis']);
        $this->assertSame(800.0, $s['calories']);
        $this->assertSame(30.0, $s['proteins']);
        $this->assertSame(400.0, $s['ref_calories']); // par unité de quantité
        $this->assertNull($s['grams_equivalent']);
        $this->assertFalse($s['is_estimate']);
    }

    public function test_snapshot_personnalise_pour_100_g(): void
    {
        $s = $this->calc->snapshot([
            'custom' => ['label' => 'Riz cuit', 'per_100g' => true, 'calories' => 130, 'proteins' => 2.7, 'carbs' => 28, 'fat' => 0.3],
            'quantity' => 250,
            'unit' => 'g',
        ]);

        $this->assertSame('per_100g', $s['ref_basis']);
        $this->assertSame(325.0, $s['calories']);
        $this->assertSame(6.75, $s['proteins']);
        $this->assertSame(250.0, $s['grams_equivalent']);
        $this->assertFalse($s['is_estimate']);
    }

    public function test_recompute_depuis_les_references_figees(): void
    {
        $snapshot = $this->calc->snapshot(['quantity' => 150, 'unit' => 'g'], $this->food(['serving_size_g' => 125]));
        $item = (new MealItem)->forceFill($snapshot);

        $enGrammes = $this->calc->recompute($item, 300, 'g');
        $this->assertSame(600.0, $enGrammes['calories']);
        $this->assertSame(300.0, $enGrammes['grams_equivalent']);
        $this->assertFalse($enGrammes['is_estimate']);

        // Sans l'aliment chargé, la portion figée (ref_serving_size_g) sert de repli.
        $enPortions = $this->calc->recompute($item, 2, 'portion');
        $this->assertSame(250.0, $enPortions['grams_equivalent']);
        $this->assertSame(500.0, $enPortions['calories']);
        $this->assertTrue($enPortions['is_estimate']);

        $absolu = (new MealItem)->forceFill($this->calc->snapshot([
            'custom' => ['label' => 'Pizza', 'calories' => 800, 'proteins' => 30, 'carbs' => 90, 'fat' => 35],
            'quantity' => 1, 'unit' => 'portion',
        ]));
        $this->assertSame(1600.0, $this->calc->recompute($absolu, 2, 'portion')['calories']);

        $recette = (new MealItem)->forceFill($this->calc->snapshot(
            ['quantity' => 1],
            null,
            (new Recipe)->forceFill(['id' => 1, 'title' => 'Dahl', 'calories' => 600, 'proteins' => 30, 'carbs' => 90, 'fat' => 10, 'servings' => 3])
        ));
        $this->assertSame(400.0, $this->calc->recompute($recette, 2, 'portion')['calories']);
    }

    public function test_totaux_et_indicateur_partiel(): void
    {
        $a = (new MealItem)->forceFill(['calories' => 300, 'proteins' => 15, 'carbs' => 30, 'fat' => 7.5, 'fiber' => 4.5, 'sugar' => null, 'salt' => 0.5, 'is_estimate' => false]);
        $b = (new MealItem)->forceFill(['calories' => 220, 'proteins' => 10, 'carbs' => 20, 'fat' => 8, 'fiber' => null, 'sugar' => null, 'salt' => null, 'is_estimate' => true]);

        $t = $this->calc->totalsForItems([$a, $b]);

        $this->assertSame(520.0, $t['calories']);
        $this->assertSame(25.0, $t['proteins']);
        $this->assertSame(50.0, $t['carbs']);
        $this->assertSame(15.5, $t['fat']);
        $this->assertSame(4.5, $t['fiber']);
        $this->assertNull($t['sugar']);
        $this->assertSame(0.5, $t['salt']);
        $this->assertTrue($t['is_partial']);

        $vide = $this->calc->totalsForItems([]);
        $this->assertSame(0.0, $vide['calories']);
        $this->assertNull($vide['fiber']);
        $this->assertFalse($vide['is_partial']);
    }

    public function test_prochain_type_de_repas(): void
    {
        $this->assertSame('petit_dejeuner', $this->calc->nextMealType([], 7));
        $this->assertSame('dejeuner', $this->calc->nextMealType(['petit_dejeuner'], 10));
        $this->assertSame('dejeuner', $this->calc->nextMealType([], 14));
        $this->assertSame('collation', $this->calc->nextMealType(['petit_dejeuner', 'dejeuner'], 16));
        $this->assertSame('diner', $this->calc->nextMealType(['petit_dejeuner', 'dejeuner'], 20));
        $this->assertSame('diner', $this->calc->nextMealType([], 23));
        $this->assertSame('collation', $this->calc->nextMealType(['petit_dejeuner', 'dejeuner', 'collation', 'diner'], 12));
        $this->assertSame('petit_dejeuner', $this->calc->nextMealType([], 0));
    }
}
