<?php

namespace Tests\Unit\Services;

use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\SportNutrition;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bonus calorique du sport (addendum §B). Dépend du schéma (migrations A1).
 */
class SportNutritionTest extends TestCase
{
    use RefreshDatabase;

    private SportNutrition $service;

    private User $user;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SportNutrition;
        $this->user = User::factory()->create();
        $this->today = Clock::today($this->user);
    }

    private function seance(array $attributes = []): WorkoutSession
    {
        return WorkoutSession::query()->forceCreate(array_merge([
            'user_id' => $this->user->id,
            'date' => $this->today,
            'title' => 'Course à pied',
            'kind' => 'activite',
            'duration_min' => 30,
            'status' => 'terminee',
            'source' => 'activite',
            'calories_burned' => 300,
            'calories_source' => 'auto',
        ], $attributes));
    }

    private function profile(array $attributes = []): Profile
    {
        return Profile::query()->forceCreate(array_merge([
            'user_id' => $this->user->id,
            'poids' => 75,
            'taille' => 178,
            'age' => 32,
            'sexe' => 'homme',
            'niveau_activite' => 'modere',
            'objectif_type' => 'maintenir',
            'sport_coef_calories' => 100,
        ], $attributes));
    }

    public function test_sans_seance_le_bonus_est_nul(): void
    {
        $this->profile();

        $r = $this->service->bonusForDay($this->user, $this->today);

        $this->assertSame(0.0, $r['calories_burned']);
        $this->assertSame(0, $r['calories_bonus']);
        $this->assertSame(100, $r['coefficient']);
        $this->assertFalse($r['is_estimate']);
        $this->assertStringContainsString('Aucune séance terminée', $r['explication']);
    }

    public function test_seules_les_seances_terminees_du_jour_comptent(): void
    {
        $this->profile();
        $this->seance(['calories_burned' => 300, 'calories_source' => 'auto']);
        $this->seance(['calories_burned' => 200, 'calories_source' => 'manuel']);
        $this->seance(['calories_burned' => 500, 'status' => 'prevue']);
        $this->seance(['calories_burned' => 400, 'date' => '2020-01-01']);

        $r = $this->service->bonusForDay($this->user, $this->today);

        $this->assertSame(500.0, $r['calories_burned']);
        $this->assertSame(500, $r['calories_bonus']);
        $this->assertSame(100, $r['coefficient']);
        $this->assertTrue($r['is_estimate']);
        $this->assertStringContainsString('500 kcal brûlées aujourd’hui', $r['explication']);
        $this->assertStringContainsString('100 % sont ajoutées à ton budget, soit +500 kcal à consommer.', $r['explication']);
    }

    public function test_coefficient_du_profil_est_applique(): void
    {
        $this->profile(['sport_coef_calories' => 50]);
        $this->seance(['calories_burned' => 300]);

        $r = $this->service->bonusForDay($this->user, $this->today);

        $this->assertSame(50, $r['coefficient']);
        $this->assertSame(150, $r['calories_bonus']);
        $this->assertStringContainsString('50 % sont ajoutées', $r['explication']);
    }

    public function test_coefficient_invalide_ou_profil_absent_retombe_a_100(): void
    {
        $this->seance(['calories_burned' => 100]);
        $this->assertSame(100, $this->service->bonusForDay($this->user, $this->today)['coefficient']);

        $this->profile(['sport_coef_calories' => 60]);
        $this->assertSame(100, $this->service->bonusForDay($this->user, $this->today)['coefficient']);
    }

    public function test_plafond_de_1500_kcal(): void
    {
        $this->profile();
        $this->seance(['calories_burned' => 1200]);
        $this->seance(['calories_burned' => 900]);

        $r = $this->service->bonusForDay($this->user, $this->today);

        $this->assertSame(2100.0, $r['calories_burned']);
        $this->assertSame(1500, $r['calories_bonus']);
        $this->assertStringContainsString('Plafond de 1 500 kcal appliqué.', $r['explication']);
    }

    public function test_calories_manuelles_ne_sont_pas_une_estimation(): void
    {
        $this->profile();
        $this->seance(['calories_burned' => 250, 'calories_source' => 'manuel']);

        $this->assertFalse($this->service->bonusForDay($this->user, $this->today)['is_estimate']);
    }

    public function test_niveau_d_activite_eleve_ajoute_l_indice_de_double_comptage(): void
    {
        $this->profile(['niveau_activite' => 'tres_eleve']);
        $this->seance(['calories_burned' => 300]);

        $r = $this->service->bonusForDay($this->user, $this->today);

        $this->assertSame(300, $r['calories_bonus']); // informatif : aucune réduction
        $this->assertStringContainsString('double comptage', $r['explication']);
    }
}
