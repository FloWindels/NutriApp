<?php

namespace Tests\Unit\Services;

use App\Services\MealBudget;
use App\Services\MealCalculator;
use App\Services\NutritionCalculator;
use App\Services\SportNutrition;
use Tests\TestCase;

class MealBudgetTest extends TestCase
{
    private MealBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();

        $nutrition = new NutritionCalculator;
        $this->budget = new MealBudget(new MealCalculator($nutrition, new SportNutrition), $nutrition);
    }

    public function test_parts_par_defaut_somment_a_un(): void
    {
        $shares = $this->budget->shares('omnivore');

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.0001);
        $this->assertSame(0.35, $shares['dejeuner']);
        $this->assertSame(0.25, $shares['petit_dejeuner']);
        $this->assertSame(0.10, $shares['collation']);
        $this->assertSame(0.30, $shares['diner']);
    }

    public function test_budget_sans_repas_enregistre(): void
    {
        $this->assertSame(700.0, $this->budget->budgetFromSummary(2000, 'dejeuner', [], 'omnivore'));
        $this->assertSame(500.0, $this->budget->budgetFromSummary(2000, 'petit_dejeuner', [], null));
    }

    public function test_budget_renormalise_sur_les_repas_restants(): void
    {
        // petit-déjeuner déjà pris : parts restantes .35 + .10 + .30 = .75 → dîner = 1500 × .30 / .75
        $this->assertSame(600.0, $this->budget->budgetFromSummary(1500, 'diner', ['petit_dejeuner'], 'omnivore'));

        // dîner déjà pris mais on redemande le dîner : sa part compte quand même au dénominateur
        $this->assertSame(600.0, $this->budget->budgetFromSummary(1500, 'diner', ['petit_dejeuner', 'diner'], 'omnivore'));
    }

    public function test_budget_minimum_150_kcal(): void
    {
        $this->assertSame(150.0, $this->budget->budgetFromSummary(200, 'collation', [], 'omnivore'));
    }

    public function test_tout_enregistre_retourne_le_restant_ou_zero(): void
    {
        $all = ['petit_dejeuner', 'dejeuner', 'collation', 'diner'];

        $this->assertSame(320.0, $this->budget->budgetFromSummary(320, 'collation', $all, 'omnivore'));
        $this->assertSame(0.0, $this->budget->budgetFromSummary(-100, 'collation', $all, 'omnivore'));
    }

    public function test_jeune_intermittent_exclut_les_repas_hors_fenetre(): void
    {
        $shares = $this->budget->shares('jeune_intermittent');

        // Fenêtre 12:00–20:00 par défaut : le petit-déjeuner (08:00) sort de la fenêtre.
        $this->assertSame(0.0, $shares['petit_dejeuner']);
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.0001);
        $this->assertEqualsWithDelta(0.35 / 0.75, $shares['dejeuner'], 0.0001);

        $this->assertSame(0.0, $this->budget->budgetFromSummary(1800, 'petit_dejeuner', [], 'jeune_intermittent'));
        $this->assertSame(840.0, $this->budget->budgetFromSummary(1800, 'dejeuner', [], 'jeune_intermittent'));
    }

    public function test_fenetre_configurable_via_config_diets(): void
    {
        config()->set('diets.jeune_intermittent.regles.fenetre_alimentaire', ['debut' => '08:00', 'fin' => '16:00']);

        $shares = $this->budget->shares('jeune_intermittent');

        $this->assertSame(0.0, $shares['diner']);
        $this->assertSame(0.0, $shares['collation']);
        $this->assertGreaterThan(0, $shares['petit_dejeuner']);
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.0001);
    }
}
