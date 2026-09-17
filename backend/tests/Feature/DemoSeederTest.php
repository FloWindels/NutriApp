<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Database\Seeders\ExerciseSeeder;
use Database\Seeders\SportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jeu de démonstration (brief §15) : le seeder doit être rejouable sans rien dupliquer,
 * et produire un compte utilisable immédiatement (connexion + tableau de bord rempli).
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Tables dont le nombre de lignes ne doit pas bouger d'une exécution à l'autre. */
    private const TABLES = [
        'users',
        'profiles',
        'user_settings',
        'food',
        'recipes',
        'stocks',
        'stock_items',
        'meals',
        'meal_items',
        'daily_targets',
        'weight_logs',
        'workout_sessions',
        'workout_exercises',
        'sport_plans',
        'shopping_items',
        'meal_plans',
        'recommendations',
    ];

    public function test_le_seeder_de_demonstration_est_idempotent(): void
    {
        $this->seed(ExerciseSeeder::class);
        $this->seed(SportSeeder::class);

        $this->seed(DemoSeeder::class);
        $first = $this->counts();

        $this->seed(DemoSeeder::class);
        $second = $this->counts();

        $this->assertSame($first, $second, 'Rejouer DemoSeeder ne doit créer aucune ligne supplémentaire.');

        // Le contenu attendu par le cahier des charges est bien présent.
        $this->assertSame(1, $first['users']);
        $this->assertSame(1, $first['profiles']);
        $this->assertGreaterThanOrEqual(25, $first['food']);
        $this->assertSame(8, $first['recipes']);
        $this->assertSame(3, $first['stocks']);
        $this->assertGreaterThanOrEqual(12, $first['stock_items']);
        $this->assertSame(2, $first['meals']);
        $this->assertGreaterThan(0, $first['meal_items']);
        $this->assertSame(5, $first['weight_logs']);
        $this->assertSame(2, $first['workout_sessions']);
        $this->assertSame(2, $first['sport_plans']);
        $this->assertSame(4, $first['shopping_items']);
        $this->assertSame(3, $first['meal_plans']);

        $this->assertDatabaseHas('users', ['email' => DemoSeeder::EMAIL, 'name' => DemoSeeder::NAME]);
    }

    public function test_le_compte_de_demonstration_se_connecte_avec_le_mot_de_passe_documente(): void
    {
        $this->seedDemo();

        $response = $this->postJson('/api/login', [
            'email' => DemoSeeder::EMAIL,
            'password' => DemoSeeder::PASSWORD,
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(DemoSeeder::EMAIL, $response->json('user.email'));
        $this->assertSame(DemoSeeder::NAME, $response->json('user.name'));
    }

    public function test_le_tableau_de_bord_de_demonstration_affiche_des_calories_et_une_alerte_de_stock(): void
    {
        $this->seedDemo();

        $token = $this->postJson('/api/login', [
            'email' => DemoSeeder::EMAIL,
            'password' => DemoSeeder::PASSWORD,
        ])->assertOk()->json('token');

        $data = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['has_profile']);
        $this->assertGreaterThan(0, (float) $data['consumed']['calories'], 'Les repas du jour doivent être comptabilisés.');
        $this->assertGreaterThan(0, (float) $data['targets']['calories'], 'Le profil démo doit avoir des cibles calculées.');
        $this->assertCount(2, $data['meals']);

        $stock = $data['stock'];
        $alerts = (int) $stock['expiring_count'] + (int) $stock['expired_count'] + (int) $stock['low_count'];
        $this->assertGreaterThan(0, $alerts, 'Le stock démo doit déclencher au moins une alerte.');
        $this->assertGreaterThanOrEqual(1, (int) $stock['expired_count'], 'Un article périmé est attendu.');
        $this->assertGreaterThanOrEqual(1, (int) $stock['expiring_count'], 'Au moins un article bientôt périmé est attendu.');
    }

    private function seedDemo(): void
    {
        $this->seed(ExerciseSeeder::class);
        $this->seed(SportSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
