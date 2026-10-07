<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Accueil d'un nouveau compte : questionnaire de première connexion et visite guidée.
 * Les deux se déclarent par un booléen ; le serveur pose et conserve la date.
 */
class AccueilTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_nouveau_compte_n_a_ni_questionnaire_ni_visite(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.onboarding_termine_le', null)
            ->assertJsonPath('data.visite_vue_le', null);
    }

    public function test_terminer_le_questionnaire_et_la_visite_pose_la_date_du_serveur(): void
    {
        Carbon::setTestNow('2026-10-07 09:30:00');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/settings', ['onboarding_termine' => true, 'visite_vue' => true]);

        $response->assertOk()
            ->assertJsonPath('data.onboarding_termine_le', '2026-10-07T09:30:00.000000Z')
            ->assertJsonPath('data.visite_vue_le', '2026-10-07T09:30:00.000000Z');
        // Les booléens d'entrée ne fuient pas dans la réponse.
        $this->assertArrayNotHasKey('onboarding_termine', $response->json('data'));
        $this->assertArrayNotHasKey('visite_vue', $response->json('data'));

        $this->assertNotNull(UserSetting::query()->where('user_id', $user->id)->value('visite_vue_le'));
        Carbon::setTestNow();
    }

    public function test_revalider_garde_la_premiere_date(): void
    {
        $user = User::factory()->create();
        UserSetting::factory()->for($user)->create(['visite_vue_le' => '2026-09-01 08:00:00']);
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', ['visite_vue' => true])
            ->assertOk()
            ->assertJsonPath('data.visite_vue_le', '2026-09-01T08:00:00.000000Z');
    }

    public function test_faux_rouvre_l_etape_sans_toucher_l_autre(): void
    {
        $user = User::factory()->create();
        UserSetting::factory()->for($user)->create([
            'onboarding_termine_le' => '2026-09-01 08:00:00',
            'visite_vue_le' => '2026-09-01 08:05:00',
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', ['visite_vue' => false])
            ->assertOk()
            ->assertJsonPath('data.visite_vue_le', null)
            ->assertJsonPath('data.onboarding_termine_le', '2026-09-01T08:00:00.000000Z');
    }

    public function test_la_date_ne_se_fixe_pas_depuis_le_client(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Seuls les booléens sont acceptés : une date envoyée telle quelle est ignorée.
        $this->putJson('/api/settings', ['visite_vue_le' => '2020-01-01T00:00:00Z'])
            ->assertOk()
            ->assertJsonPath('data.visite_vue_le', null);

        $this->putJson('/api/settings', ['onboarding_termine' => 'bientôt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['onboarding_termine']);
    }

    public function test_l_accueil_est_propre_a_chaque_compte(): void
    {
        $user = User::factory()->create();
        $autre = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', ['onboarding_termine' => true])->assertOk();

        Sanctum::actingAs($autre);
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.onboarding_termine_le', null);
    }
}
