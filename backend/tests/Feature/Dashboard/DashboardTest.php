<?php

namespace Tests\Feature\Dashboard;

use App\Models\NotificationRead;
use App\Models\WeightLog;
use App\Models\WorkoutSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /dashboard (brief §7) : forme exacte de la charge utile, couplage sport ↔ budget,
 * chemin « sans profil » et types sérialisés.
 */
class DashboardTest extends TestCase
{
    use ModuleM9Helpers;
    use RefreshDatabase;

    public const DATA_KEYS = [
        'date', 'user', 'has_profile', 'targets', 'consumed', 'remaining', 'progress_pct', 'calories_bonus',
        'plancher_kcal', 'is_estimate', 'meals', 'next_meal_type', 'stock', 'sport', 'recommendations',
        'weight', 'notifications_unread',
    ];

    public const TOTALS_KEYS = ['calories', 'proteins', 'carbs', 'fat', 'fiber', 'sugar', 'salt', 'is_partial'];

    public const STOCK_KEYS = ['expiring_count', 'expired_count', 'low_count', 'expiring'];

    public const SPORT_KEYS = ['sessions_today', 'calories_burned', 'planned', 'week_minutes', 'week_sessions', 'streak_days'];

    public const WEIGHT_KEYS = ['current', 'target', 'history', 'variation_hebdo_kg'];

    public const RECOMMENDATION_KEYS = ['id', 'date', 'type', 'title', 'message', 'factors', 'actions', 'priority', 'status', 'is_estimate'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_payload_exposes_every_block_of_the_brief(): void
    {
        $this->freezeAtLocalHour(20);
        $user = $this->login($this->userWithProfile());

        $this->logMeal($user, 'petit_dejeuner', ['calories' => 600, 'proteins' => 40, 'carbs' => 60, 'fat' => 20]);
        $this->logMeal($user, 'dejeuner', ['calories' => 1000, 'proteins' => 60, 'carbs' => 100, 'fat' => 30]);

        $stock = $this->personalStock($user);
        $this->stockItem($stock, ['food_name' => 'Yaourt nature', 'expires_at' => $this->daysFromToday(2)]);
        $this->stockItem($stock, ['food_name' => 'Lait', 'expires_at' => $this->daysFromToday(-2), 'expiry_kind' => 'dlc']);
        $this->stockItem($stock, ['food_name' => 'Riz', 'expires_at' => null, 'quantity' => 1, 'min_quantity' => 2]);

        WorkoutSession::factory()->create([
            'user_id' => $user->id,
            'date' => self::TODAY,
            'title' => 'Circuit cardio',
            'duration_min' => 45,
            'status' => 'terminee',
            'calories_burned' => 400,
            'calories_source' => 'auto',
            'completed_at' => Carbon::now()->subHours(6),
        ]);
        WorkoutSession::factory()->create([
            'user_id' => $user->id,
            'date' => self::TODAY,
            'title' => 'Séance jambes',
            'duration_min' => 40,
            'planned_at' => '21:00',
            'status' => 'prevue',
        ]);

        WeightLog::factory()->create(['user_id' => $user->id, 'date' => $this->daysFromToday(-7), 'weight_kg' => 81.5]);
        WeightLog::factory()->create(['user_id' => $user->id, 'date' => self::TODAY, 'weight_kg' => 80.2]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSameKeys(self::DATA_KEYS, $data, 'data');
        $this->assertSame(self::TODAY, $data['date']);
        $this->assertSame(['name' => 'Camille Durand', 'first_name' => 'Camille'], $data['user']);
        $this->assertTrue($data['has_profile']);

        $this->assertSameKeys(self::TOTALS_KEYS, $data['targets'], 'targets');
        $this->assertSameKeys(self::TOTALS_KEYS, $data['consumed'], 'consumed');
        $this->assertSameKeys(self::TOTALS_KEYS, $data['remaining'], 'remaining');
        $this->assertSameKeys(self::STOCK_KEYS, $data['stock'], 'stock');
        $this->assertSameKeys(self::SPORT_KEYS, $data['sport'], 'sport');
        $this->assertSameKeys(self::WEIGHT_KEYS, $data['weight'], 'weight');

        // Repas du jour (liste courte).
        $this->assertCount(2, $data['meals']);
        $this->assertSameKeys(['id', 'type', 'name', 'calories', 'items_count'], $data['meals'][0], 'meals.0');
        $this->assertSame(['petit_dejeuner', 'dejeuner'], array_column($data['meals'], 'type'));
        $this->assertEqualsWithDelta(600.0, $data['meals'][0]['calories'], 0.01);
        $this->assertSame(1, $data['meals'][0]['items_count']);

        // Prochain repas : petit-déjeuner et déjeuner enregistrés à 20 h → dîner.
        $this->assertSame('diner', $data['next_meal_type']);

        // Stock : un article périmé, un qui expire dans 2 jours, un sous le minimum.
        $this->assertSame(1, $data['stock']['expiring_count']);
        $this->assertSame(1, $data['stock']['expired_count']);
        $this->assertSame(1, $data['stock']['low_count']);
        $this->assertCount(1, $data['stock']['expiring']);
        $this->assertSameKeys(['id', 'label', 'expires_at', 'days_left', 'stock_name'], $data['stock']['expiring'][0], 'stock.expiring.0');
        $this->assertSame('Yaourt nature', $data['stock']['expiring'][0]['label']);
        $this->assertSame(2, $data['stock']['expiring'][0]['days_left']);
        $this->assertSame('Frigo', $data['stock']['expiring'][0]['stock_name']);

        // Sport.
        $this->assertCount(2, $data['sport']['sessions_today']);
        $this->assertSameKeys(
            ['id', 'title', 'status', 'duration_min', 'calories_burned', 'planned_at', 'sport_name', 'kind'],
            $data['sport']['sessions_today'][0],
            'sport.sessions_today.0'
        );
        $this->assertEqualsWithDelta(400.0, $data['sport']['calories_burned'], 0.01);
        $this->assertCount(1, $data['sport']['planned']);
        $this->assertSame('Séance jambes', $data['sport']['planned'][0]['title']);
        $this->assertSame(45, $data['sport']['week_minutes']);
        $this->assertSame(1, $data['sport']['week_sessions']);
        $this->assertSame(1, $data['sport']['streak_days']);

        // Poids : historique croissant, cible et variation hebdomadaire du calcul.
        $this->assertEqualsWithDelta(80.2, $data['weight']['current'], 0.01);
        $this->assertEqualsWithDelta(80.0, $data['weight']['target'], 0.01);
        $this->assertSame(
            [$this->daysFromToday(-7), self::TODAY],
            array_column($data['weight']['history'], 'date')
        );
        $this->assertEqualsWithDelta([81.5, 80.2], array_column($data['weight']['history'], 'weight_kg'), 0.01);
        $this->assertJsonNumber($data['weight']['variation_hebdo_kg']);

        // Recommandations : au plus 4, forme de la ressource.
        $this->assertLessThanOrEqual(4, count($data['recommendations']));
        foreach ($data['recommendations'] as $reco) {
            $this->assertSameKeys(self::RECOMMENDATION_KEYS, $reco, 'recommendation');
        }

        $this->assertIsInt($data['notifications_unread']);
    }

    public function test_remaining_adds_the_sport_bonus_of_a_completed_session(): void
    {
        $this->freezeAtLocalHour(14);
        $user = $this->login($this->userWithProfile());

        $this->logMeal($user, 'petit_dejeuner', ['calories' => 600, 'proteins' => 40, 'carbs' => 60, 'fat' => 20]);
        $this->logMeal($user, 'dejeuner', ['calories' => 900, 'proteins' => 50, 'carbs' => 90, 'fat' => 30]);

        // Séance terminée : 420 kcal brûlées, coefficient 100 % → bonus 420 kcal.
        WorkoutSession::factory()->create([
            'user_id' => $user->id,
            'date' => self::TODAY,
            'status' => 'terminee',
            'duration_min' => 50,
            'calories_burned' => 420,
            'calories_source' => 'auto',
            'completed_at' => Carbon::now()->subHours(5),
        ]);
        // Séance annulée et séance de la veille : hors du bonus du jour.
        WorkoutSession::factory()->create([
            'user_id' => $user->id, 'date' => self::TODAY, 'status' => 'annulee', 'calories_burned' => 300,
        ]);
        WorkoutSession::factory()->create([
            'user_id' => $user->id, 'date' => $this->daysFromToday(-1), 'status' => 'terminee', 'calories_burned' => 500,
            'completed_at' => Carbon::now()->subDay(),
        ]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(420, $data['calories_bonus']);
        $this->assertEqualsWithDelta(1500.0, $data['consumed']['calories'], 0.01);
        $this->assertEqualsWithDelta(self::TARGET_CALORIES, $data['targets']['calories'], 0.01);
        $this->assertEqualsWithDelta(self::TARGET_CALORIES + 420 - 1500, $data['remaining']['calories'], 0.01);
        $this->assertEqualsWithDelta(self::TARGET_PROTEINS - 90, $data['remaining']['proteins'], 0.01);

        // progress_pct = consommé / (cibles + bonus).
        $this->assertSame((int) round(1500 / (self::TARGET_CALORIES + 420) * 100), $data['progress_pct']);
        $this->assertSame(1500, $data['plancher_kcal']);
        $this->assertTrue($data['is_estimate']);
    }

    public function test_coefficient_below_one_hundred_reduces_the_bonus(): void
    {
        $this->freezeAtLocalHour(14);
        $user = $this->login($this->userWithProfile(['sport_coef_calories' => 50]));

        WorkoutSession::factory()->create([
            'user_id' => $user->id, 'date' => self::TODAY, 'status' => 'terminee',
            'calories_burned' => 400, 'completed_at' => Carbon::now()->subHours(3),
        ]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(200, $data['calories_bonus']);
        $this->assertEqualsWithDelta(self::TARGET_CALORIES + 200, $data['remaining']['calories'], 0.01);
    }

    public function test_dashboard_without_profile_returns_null_targets_and_the_profile_recommendation(): void
    {
        $this->freezeAtLocalHour(10);
        $user = $this->login($this->userWithoutProfile());

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSameKeys(self::DATA_KEYS, $data, 'data');
        $this->assertFalse($data['has_profile']);
        $this->assertNull($data['targets']['calories']);
        $this->assertNull($data['remaining']['calories']);
        $this->assertNull($data['progress_pct']);
        $this->assertSame(0, $data['calories_bonus']);
        $this->assertSame(0, $data['plancher_kcal']);
        $this->assertSame([], $data['meals']);
        $this->assertNull($data['weight']['current']);
        $this->assertNull($data['weight']['target']);
        $this->assertNull($data['weight']['variation_hebdo_kg']);
        $this->assertSame([], $data['weight']['history']);

        $types = array_column($data['recommendations'], 'type');
        $this->assertContains('profil_incomplet', $types);
        $this->assertSame(1, $data['recommendations'][0]['priority']);
    }

    public function test_serialization_types(): void
    {
        $this->freezeAtLocalHour(19);
        $user = $this->login($this->userWithProfile());

        $this->logMeal($user, 'dejeuner', ['calories' => 700, 'proteins' => 40, 'carbs' => 70, 'fat' => 20, 'salt' => 1.5]);
        $stock = $this->personalStock($user);
        $this->stockItem($stock, ['expires_at' => $this->daysFromToday(1)]);
        WorkoutSession::factory()->create([
            'user_id' => $user->id, 'date' => self::TODAY, 'status' => 'terminee',
            'duration_min' => 30, 'calories_burned' => 250.5, 'completed_at' => Carbon::now()->subHours(2),
        ]);
        WeightLog::factory()->create(['user_id' => $user->id, 'date' => self::TODAY, 'weight_kg' => 79.4]);

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['date']);
        $this->assertIsBool($data['has_profile']);
        $this->assertIsBool($data['is_estimate']);
        $this->assertIsInt($data['calories_bonus']);
        $this->assertIsInt($data['plancher_kcal']);
        $this->assertIsInt($data['progress_pct']);
        $this->assertIsInt($data['notifications_unread']);
        $this->assertIsString($data['next_meal_type']);

        foreach (['targets', 'consumed', 'remaining'] as $block) {
            foreach (['calories', 'proteins', 'carbs', 'fat'] as $macro) {
                $this->assertJsonNumber($data[$block][$macro], "$block.$macro");
            }
            $this->assertIsBool($data[$block]['is_partial']);
        }

        $this->assertJsonNumber($data['meals'][0]['calories'], 'meals.0.calories');
        $this->assertIsInt($data['meals'][0]['items_count']);
        $this->assertIsInt($data['stock']['expiring'][0]['days_left']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['stock']['expiring'][0]['expires_at']);
        $this->assertJsonNumber($data['sport']['calories_burned'], 'sport.calories_burned');
        $this->assertIsInt($data['sport']['week_minutes']);
        $this->assertIsInt($data['sport']['streak_days']);
        $this->assertIsFloat($data['sport']['sessions_today'][0]['calories_burned']);
        $this->assertIsInt($data['sport']['sessions_today'][0]['duration_min']);
        $this->assertIsFloat($data['weight']['current']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['weight']['history'][0]['date']);
        $this->assertIsFloat($data['weight']['history'][0]['weight_kg']);
    }

    public function test_notifications_unread_counts_expiring_items_and_drops_read_keys(): void
    {
        $this->freezeAtLocalHour(12);
        $user = $this->login($this->userWithProfile());

        $stock = $this->personalStock($user);
        $item = $this->stockItem($stock, ['expires_at' => $this->daysFromToday(2)]);

        $this->assertSame(1, $this->getJson('/api/dashboard')->assertOk()->json('data.notifications_unread'));

        NotificationRead::query()->create([
            'user_id' => $user->id,
            'key' => 'peremption:'.$item->id,
            'read_at' => Carbon::now(),
        ]);

        $this->assertSame(0, $this->getJson('/api/dashboard')->assertOk()->json('data.notifications_unread'));
    }

    public function test_date_parameter_is_validated_and_honoured(): void
    {
        $this->freezeAtLocalHour(12);
        $user = $this->login($this->userWithProfile());

        $this->logMeal($user, 'dejeuner', ['calories' => 800], $this->daysFromToday(-3));

        $data = $this->getJson('/api/dashboard?date='.$this->daysFromToday(-3))->assertOk()->json('data');
        $this->assertSame($this->daysFromToday(-3), $data['date']);
        $this->assertEqualsWithDelta(800.0, $data['consumed']['calories'], 0.01);

        $this->getJson('/api/dashboard?date=16-09-2026')->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized()->assertJson(['message' => 'Non authentifié.']);
    }

    /**
     * Un nombre sérialisé en JSON : entier ou flottant, jamais une chaîne
     * (json_encode rend 800.0 sous la forme « 800 », le type PHP dépend donc de la valeur).
     */
    private function assertJsonNumber(mixed $value, string $context = ''): void
    {
        $this->assertTrue(
            is_int($value) || is_float($value),
            trim('Valeur non numérique '.$context).' : '.var_export($value, true)
        );
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameKeys(array $expected, array $actual, string $context): void
    {
        $actualKeys = array_keys($actual);
        sort($expected);
        sort($actualKeys);

        $this->assertSame($expected, $actualKeys, "Clés inattendues ($context).");
    }
}
