<?php

namespace Tests\Feature\Weights;

use App\Models\Profile;
use App\Models\User;
use App\Models\WeightLog;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeightLogTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(?User $user = null): User
    {
        $user = $user ?? User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * Profil auto du vecteur obligatoire, cibles calculées aujourd'hui avec poids_reference = 70.
     */
    private function profilAuto(User $user, array $overrides = []): Profile
    {
        $today = Clock::today($user);

        return Profile::factory()->for($user)->create(array_replace([
            'sexe' => 'homme', 'age' => 30, 'taille' => 175, 'poids' => 70,
            'poids_souhaite_kg' => 65, 'delai_objectif_jours' => 90,
            'objectif_type' => 'perdre', 'niveau_activite' => 'modere', 'regime_alimentaire' => 'omnivore',
            'objectif_date_debut' => $today,
            'objectif_date_fin' => CarbonImmutable::parse($today)->addDays(90)->toDateString(),
            'objectif_calcul_auto' => true,
            'calories_cibles' => 2130, 'proteines_cibles' => 126, 'glucides_cibles' => 280, 'lipides_cibles' => 56,
            'poids_reference' => 70,
            'cibles_calculees_le' => $today,
        ], $overrides));
    }

    // ------------------------------------------------------------------ liste

    public function test_weights_require_authentication(): void
    {
        $this->getJson('/api/weights')->assertStatus(401);
        $this->postJson('/api/weights', ['weight_kg' => 70])->assertStatus(401);
    }

    public function test_index_returns_only_my_logs_ordered_by_date(): void
    {
        $user = $this->actingAsUser();
        WeightLog::factory()->for($user)->create(['date' => '2026-09-10', 'weight_kg' => 71.5]);
        WeightLog::factory()->for($user)->create(['date' => '2026-09-01', 'weight_kg' => 72.3]);
        WeightLog::factory()->create(['date' => '2026-09-05', 'weight_kg' => 99]); // autre utilisateur

        $response = $this->getJson('/api/weights')->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', ['date' => '2026-09-01', 'weight_kg' => 72.3])
            ->assertJsonPath('data.1', ['date' => '2026-09-10', 'weight_kg' => 71.5]);

        $this->assertIsFloat($response->json('data.0.weight_kg'));
        $this->assertSame(['date', 'weight_kg'], array_keys($response->json('data.0')));
    }

    public function test_index_filters_by_from_and_to(): void
    {
        $user = $this->actingAsUser();
        foreach (['2026-09-01', '2026-09-05', '2026-09-10'] as $date) {
            WeightLog::factory()->for($user)->create(['date' => $date]);
        }

        $this->getJson('/api/weights?from=2026-09-02&to=2026-09-09')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-09-05');

        $this->getJson('/api/weights?from=2026-09-05')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/weights?to=2026-09-05')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/weights?from=09/01/2026')->assertStatus(422)->assertJsonValidationErrors(['from']);
    }

    // ------------------------------------------------------------------ création / upsert

    public function test_store_creates_a_log_for_today_by_default(): void
    {
        $user = $this->actingAsUser();
        $today = Clock::today($user);

        $response = $this->postJson('/api/weights', ['weight_kg' => 70.4])->assertStatus(201);

        $response->assertJsonPath('message', 'Poids enregistré.')
            ->assertJsonPath('data', ['date' => $today, 'weight_kg' => 70.4])
            ->assertJsonPath('profil_mis_a_jour', false)
            ->assertJsonPath('cibles_recalculees', false);

        $this->assertDatabaseHas('weight_logs', ['user_id' => $user->id, 'date' => $today, 'weight_kg' => 70.4]);
    }

    public function test_store_upserts_the_same_date(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/weights', ['date' => '2026-09-01', 'weight_kg' => 72])->assertStatus(201);
        $this->postJson('/api/weights', ['date' => '2026-09-01', 'weight_kg' => 71.2])
            ->assertOk()
            ->assertJsonPath('message', 'Poids mis à jour.')
            ->assertJsonPath('data.weight_kg', 71.2);

        $this->assertSame(1, WeightLog::where('user_id', $user->id)->count());
        $this->assertSame(71.2, (float) WeightLog::where('user_id', $user->id)->value('weight_kg'));
    }

    public function test_store_validation_is_french(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('/api/weights', ['weight_kg' => 10, 'date' => '2026/09/01'])->assertStatus(422);
        $response->assertJsonValidationErrors(['weight_kg', 'date']);
        $this->assertStringContainsString('poids', $response->json('errors.weight_kg.0'));

        $this->postJson('/api/weights', [])->assertStatus(422)
            ->assertJsonValidationErrors(['weight_kg' => 'Le champ poids est obligatoire.']);

        $future = CarbonImmutable::parse(Clock::today($user))->addDay()->toDateString();
        $this->postJson('/api/weights', ['weight_kg' => 70, 'date' => $future])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date' => 'La date de la pesée ne peut pas être dans le futur.']);
    }

    // ------------------------------------------------------------------ couplage profil & hystérésis

    public function test_today_weight_updates_profile_weight_without_recompute_when_change_is_small(): void
    {
        $user = $this->actingAsUser();
        $this->profilAuto($user);

        $this->postJson('/api/weights', ['weight_kg' => 69.5])
            ->assertStatus(201)
            ->assertJsonPath('profil_mis_a_jour', true)
            ->assertJsonPath('cibles_recalculees', false);

        $profile = Profile::where('user_id', $user->id)->first();
        $this->assertSame(69.5, $profile->poids);
        $this->assertSame(70.0, $profile->poids_reference);
        $this->assertSame(2130, $profile->calories_cibles);
    }

    public function test_today_weight_recomputes_targets_when_change_is_at_least_1_kg(): void
    {
        $user = $this->actingAsUser();
        $this->profilAuto($user);
        $today = Clock::today($user);

        $this->postJson('/api/weights', ['weight_kg' => 69])
            ->assertStatus(201)
            ->assertJsonPath('profil_mis_a_jour', true)
            ->assertJsonPath('cibles_recalculees', true);

        $profile = Profile::where('user_id', $user->id)->first();
        $this->assertSame(69.0, $profile->poids);
        $this->assertSame(69.0, $profile->poids_reference);
        $this->assertSame($today, $profile->cibles_calculees_le->format('Y-m-d'));
        // 69 kg → BMR 1638.75, TDEE 2540.1, déficit 4×7700/90 = 342 → 2198 → 2200
        $this->assertSame(2200, $profile->calories_cibles);
        $this->assertSame(124, $profile->proteines_cibles);

        $this->getJson('/api/profile')->assertOk()->assertJsonPath('calories_cibles', 2200)->assertJsonPath('poids', 69);
    }

    public function test_today_weight_recomputes_targets_when_they_are_14_days_old(): void
    {
        $user = $this->actingAsUser();
        $today = Clock::today($user);
        $this->profilAuto($user, [
            'cibles_calculees_le' => CarbonImmutable::parse($today)->subDays(14)->toDateString(),
            'calories_cibles' => 1111,
        ]);

        $this->postJson('/api/weights', ['weight_kg' => 70.2])
            ->assertStatus(201)
            ->assertJsonPath('cibles_recalculees', true);

        $profile = Profile::where('user_id', $user->id)->first();
        $this->assertSame($today, $profile->cibles_calculees_le->format('Y-m-d'));
        $this->assertSame(70.2, $profile->poids_reference);
        $this->assertNotSame(1111, $profile->calories_cibles);
    }

    public function test_targets_13_days_old_are_not_recomputed(): void
    {
        $user = $this->actingAsUser();
        $today = Clock::today($user);
        $ancien = CarbonImmutable::parse($today)->subDays(13)->toDateString();
        $this->profilAuto($user, ['cibles_calculees_le' => $ancien, 'calories_cibles' => 1111]);

        $this->postJson('/api/weights', ['weight_kg' => 70.5])->assertStatus(201)->assertJsonPath('cibles_recalculees', false);

        $profile = Profile::where('user_id', $user->id)->first();
        $this->assertSame(1111, $profile->calories_cibles);
        $this->assertSame($ancien, $profile->cibles_calculees_le->format('Y-m-d'));
    }

    public function test_manual_targets_are_never_recomputed_by_a_weight(): void
    {
        $user = $this->actingAsUser();
        $this->profilAuto($user, ['objectif_calcul_auto' => false, 'calories_cibles' => 2000]);

        $this->postJson('/api/weights', ['weight_kg' => 65])
            ->assertStatus(201)
            ->assertJsonPath('profil_mis_a_jour', true)
            ->assertJsonPath('cibles_recalculees', false);

        $profile = Profile::where('user_id', $user->id)->first();
        $this->assertSame(65.0, $profile->poids);
        $this->assertSame(2000, $profile->calories_cibles);
        $this->assertSame(70.0, $profile->poids_reference);
    }

    public function test_past_weight_does_not_touch_the_profile(): void
    {
        $user = $this->actingAsUser();
        $this->profilAuto($user);

        $this->postJson('/api/weights', ['date' => '2026-01-01', 'weight_kg' => 60])
            ->assertStatus(201)
            ->assertJsonPath('profil_mis_a_jour', false)
            ->assertJsonPath('cibles_recalculees', false);

        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'poids' => 70, 'calories_cibles' => 2130]);
    }

    public function test_today_weight_without_profile_is_simply_logged(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/weights', ['weight_kg' => 80])->assertStatus(201)->assertJsonPath('profil_mis_a_jour', false);
        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
    }

    // ------------------------------------------------------------------ suppression

    public function test_destroy_deletes_my_log_only(): void
    {
        $user = $this->actingAsUser();
        WeightLog::factory()->for($user)->create(['date' => '2026-09-01']);
        $other = WeightLog::factory()->create(['date' => '2026-09-02']);

        $this->deleteJson('/api/weights/2026-09-01')->assertOk()->assertExactJson(['message' => 'Pesée supprimée.']);
        $this->assertDatabaseMissing('weight_logs', ['user_id' => $user->id, 'date' => '2026-09-01']);

        // La pesée d'un autre utilisateur à cette date est introuvable pour moi.
        $this->deleteJson('/api/weights/2026-09-02')->assertStatus(404)->assertExactJson(['message' => 'Introuvable.']);
        $this->assertDatabaseHas('weight_logs', ['id' => $other->id]);
    }

    public function test_destroy_with_malformed_date_is_404(): void
    {
        $this->actingAsUser();

        $this->deleteJson('/api/weights/hier')->assertStatus(404);
        $this->deleteJson('/api/weights/2026-09-99')->assertStatus(404)->assertExactJson(['message' => 'Introuvable.']);
    }
}
