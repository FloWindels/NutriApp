<?php

namespace Tests\Feature\Dashboard;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\WeightLog;
use App\Models\WorkoutSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Budget de requêtes du tableau de bord (brief §7) : tout est chargé en amont, y compris
 * la génération des recommandations — 20 requêtes au maximum quel que soit le volume de données.
 */
class DashboardQueryBudgetTest extends TestCase
{
    use ModuleM9Helpers;
    use RefreshDatabase;

    public const BUDGET = 20;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_stays_within_twenty_queries(): void
    {
        $this->freezeAtLocalHour(20);
        $user = $this->login($this->userWithProfile());

        // 3 repas × 5 éléments.
        foreach (['petit_dejeuner', 'dejeuner', 'diner'] as $type) {
            $meal = Meal::factory()->create(['user_id' => $user->id, 'date' => self::TODAY, 'type' => $type]);
            MealItem::factory()->count(5)->create(['meal_id' => $meal->id, 'calories' => 120, 'proteins' => 10, 'carbs' => 12, 'fat' => 4]);
        }

        // 30 articles de stock répartis sur deux lieux, dont des dates d'expiration variées.
        $frigo = $this->personalStock($user, 'Frigo');
        $placard = $this->personalStock($user, 'Placard');
        for ($i = 0; $i < 30; $i++) {
            $this->stockItem($i % 2 === 0 ? $frigo : $placard, [
                'food_name' => 'Article '.$i,
                'expires_at' => $this->daysFromToday($i - 3),
                'quantity' => 1 + $i,
                'min_quantity' => $i % 7 === 0 ? 5 : null,
            ]);
        }

        // 2 séances du jour (une terminée, une prévue).
        WorkoutSession::factory()->create([
            'user_id' => $user->id, 'date' => self::TODAY, 'status' => 'terminee', 'duration_min' => 45,
            'calories_burned' => 380, 'completed_at' => Carbon::now()->subHours(3),
        ]);
        WorkoutSession::factory()->create([
            'user_id' => $user->id, 'date' => self::TODAY, 'status' => 'prevue', 'duration_min' => 40, 'planned_at' => '21:30',
        ]);

        // 8 pesées.
        for ($i = 0; $i < 8; $i++) {
            WeightLog::factory()->create([
                'user_id' => $user->id,
                'date' => $this->daysFromToday(-$i),
                'weight_kg' => 80 + $i * 0.3,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/dashboard')->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            self::BUDGET,
            count($queries),
            sprintf(
                "Le tableau de bord doit tenir en %d requêtes, %d exécutées :\n%s",
                self::BUDGET,
                count($queries),
                implode("\n", array_map(fn (array $q) => '- '.$q['query'], $queries))
            )
        );
    }

    public function test_second_call_of_the_same_minute_does_not_regenerate_recommendations(): void
    {
        $this->freezeAtLocalHour(20);
        $user = $this->login($this->userWithProfile());

        $this->logMeal($user, 'petit_dejeuner', ['calories' => 500]);
        $this->logMeal($user, 'dejeuner', ['calories' => 700]);

        $this->getJson('/api/dashboard')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/dashboard')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(self::BUDGET, count($queries));
        $this->assertSame(
            [],
            array_values(array_filter($queries, fn (array $q) => str_starts_with(strtolower(trim($q['query'])), 'insert into "recommendations"'))),
            'Le verrou d’une minute doit empêcher une seconde génération.'
        );
    }
}
