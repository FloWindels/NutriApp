<?php

namespace Tests\Feature\Settings;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'notif_peremption', 'notif_rappel_repas', 'notif_rappel_sport', 'heure_rappel',
        'jours_alerte_peremption', 'unites', 'theme', 'langue', 'timezone', 'ia_seances',
        'partage_profil_foyer', 'updated_at',
    ];

    public function test_get_settings_creates_defaults_lazily(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->assertDatabaseMissing('user_settings', ['user_id' => $user->id]);

        $response = $this->getJson('/api/settings');

        $response->assertOk();
        $this->assertSame(self::KEYS, array_keys($response->json('data')));
        $response->assertJsonPath('data.notif_peremption', true)
            ->assertJsonPath('data.notif_rappel_repas', false)
            ->assertJsonPath('data.notif_rappel_sport', false)
            ->assertJsonPath('data.heure_rappel', null)
            ->assertJsonPath('data.jours_alerte_peremption', 3)
            ->assertJsonPath('data.unites', 'metrique')
            ->assertJsonPath('data.theme', 'systeme')
            ->assertJsonPath('data.langue', 'fr')
            ->assertJsonPath('data.timezone', 'Europe/Paris')
            ->assertJsonPath('data.ia_seances', true)
            ->assertJsonPath('data.partage_profil_foyer', null);

        $this->assertIsBool($response->json('data.notif_peremption'));
        $this->assertIsInt($response->json('data.jours_alerte_peremption'));
        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id]);
    }

    public function test_put_settings_is_partial_and_returns_message_and_data(): void
    {
        $user = User::factory()->create();
        UserSetting::factory()->for($user)->create(['jours_alerte_peremption' => 5]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/settings', [
            'notif_rappel_repas' => true,
            'heure_rappel' => '07:30',
            'theme' => 'sombre',
            'timezone' => 'America/Toronto',
            'ia_seances' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Paramètres enregistrés.')
            ->assertJsonPath('data.notif_rappel_repas', true)
            ->assertJsonPath('data.heure_rappel', '07:30')
            ->assertJsonPath('data.theme', 'sombre')
            ->assertJsonPath('data.timezone', 'America/Toronto')
            ->assertJsonPath('data.ia_seances', false)
            ->assertJsonPath('data.jours_alerte_peremption', 5); // inchangé

        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id, 'heure_rappel' => '07:30:00', 'theme' => 'sombre']);

        Clock::forget();
        $this->assertSame('America/Toronto', Clock::timezone($user->fresh()));
    }

    public function test_put_settings_validation_is_french(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/settings', [
            'theme' => 'violet',
            'jours_alerte_peremption' => 0,
            'timezone' => 'Mars/Olympus',
            'heure_rappel' => '25h',
            'unites' => 'cubits',
            'notif_peremption' => 'peut-être',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'theme', 'jours_alerte_peremption', 'timezone', 'heure_rappel', 'unites', 'notif_peremption',
        ]);
        $this->assertStringContainsString('thème', $response->json('errors.theme.0'));
        $this->assertStringContainsString('fuseau horaire', $response->json('errors.timezone.0'));
    }

    public function test_jours_alerte_peremption_bounds(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', ['jours_alerte_peremption' => 14])->assertOk();
        $this->putJson('/api/settings', ['jours_alerte_peremption' => 15])->assertStatus(422);
        $this->putJson('/api/settings', ['jours_alerte_peremption' => 1])->assertOk()->assertJsonPath('data.jours_alerte_peremption', 1);
    }

    public function test_partage_profil_foyer_without_household_is_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/settings', ['partage_profil_foyer' => false, 'theme' => 'clair']);

        $response->assertStatus(422)->assertJsonValidationErrors(['partage_profil_foyer']);
        $this->assertSame('Tu ne fais partie d’aucun foyer.', $response->json('errors.partage_profil_foyer.0'));
        // Rien n'est écrit quand la requête est refusée.
        $this->assertDatabaseMissing('user_settings', ['user_id' => $user->id, 'theme' => 'clair']);
    }

    public function test_partage_profil_foyer_reads_and_writes_membership(): void
    {
        $owner = User::factory()->create();
        $household = Household::factory()->create(['owner_id' => $owner->id]);
        $user = User::factory()->create(['household_id' => $household->id]);
        HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'share_profile' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.partage_profil_foyer', true);

        $this->putJson('/api/settings', ['partage_profil_foyer' => false])
            ->assertOk()
            ->assertJsonPath('data.partage_profil_foyer', false);

        $this->assertDatabaseHas('household_members', ['user_id' => $user->id, 'share_profile' => false]);
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.partage_profil_foyer', false);
    }

    public function test_settings_are_isolated_per_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        UserSetting::factory()->for($other)->create(['theme' => 'sombre']);
        Sanctum::actingAs($user);

        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.theme', 'systeme');
        $this->putJson('/api/settings', ['theme' => 'clair'])->assertOk();

        $this->assertDatabaseHas('user_settings', ['user_id' => $other->id, 'theme' => 'sombre']);
        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id, 'theme' => 'clair']);
    }

    public function test_settings_require_authentication(): void
    {
        $this->getJson('/api/settings')->assertStatus(401);
        $this->putJson('/api/settings', ['theme' => 'clair'])->assertStatus(401);
    }
}
