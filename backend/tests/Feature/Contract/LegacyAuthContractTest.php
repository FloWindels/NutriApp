<?php

namespace Tests\Feature\Contract;

use App\Models\Profile;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gel du contrat legacy (brief §0.3) pour /register, /login et /me + alias /api/v1.
 */
class LegacyAuthContractTest extends TestCase
{
    use RefreshDatabase;

    private const USER_KEYS = ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'];

    public function test_register_returns_exact_legacy_keys(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Léa Martin',
            'email' => 'lea@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated();
        $this->assertSame(['token', 'user'], array_keys($response->json()));
        $this->assertSame(self::USER_KEYS, array_keys($response->json('user')));
        $this->assertIsInt($response->json('user.id'));
        $this->assertIsString($response->json('token'));
        $this->assertNull($response->json('user.email_verified_at'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $response->json('user.created_at'));
    }

    public function test_login_returns_exact_legacy_keys(): void
    {
        User::factory()->create(['email' => 'lea@example.com', 'password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/login', ['email' => 'lea@example.com', 'password' => 'secret123']);

        $response->assertOk();
        $this->assertSame(['token', 'user'], array_keys($response->json()));
        $this->assertSame(self::USER_KEYS, array_keys($response->json('user')));
    }

    public function test_bad_credentials_return_422_on_email(): void
    {
        User::factory()->create(['email' => 'lea@example.com', 'password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/login', ['email' => 'lea@example.com', 'password' => 'wrong-one']);

        $response->assertStatus(422);
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
        $this->assertSame(['Identifiants invalides.'], $response->json('errors.email'));
    }

    public function test_me_returns_frozen_shape_plus_siblings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertOk();
        $this->assertSame(
            [...self::USER_KEYS, 'has_profile', 'household_id', 'consentement_sante', 'settings'],
            array_keys($response->json())
        );
        $this->assertSame(['timezone', 'theme'], array_keys($response->json('settings')));
        $this->assertFalse($response->json('has_profile'));
        $this->assertNull($response->json('household_id'));
        $this->assertFalse($response->json('consentement_sante'));
        $this->assertSame('Europe/Paris', $response->json('settings.timezone'));
        $this->assertSame('systeme', $response->json('settings.theme'));
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('consentement_sante_at', $response->json());
    }

    public function test_me_reflects_profile_consent_and_settings(): void
    {
        $user = User::factory()->create(['consentement_sante_at' => now()]);
        Profile::factory()->for($user)->create();
        UserSetting::factory()->for($user)->create(['timezone' => 'America/Toronto', 'theme' => 'sombre']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('has_profile', true)
            ->assertJsonPath('consentement_sante', true)
            ->assertJsonPath('settings.timezone', 'America/Toronto')
            ->assertJsonPath('settings.theme', 'sombre');
    }

    public function test_v1_alias_returns_identical_body(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $legacy = $this->getJson('/api/me')->assertOk()->json();
        $v1 = $this->getJson('/api/v1/me')->assertOk()->json();

        $this->assertSame($legacy, $v1);

        $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password']);
        $login->assertOk();
        $this->assertSame(self::USER_KEYS, array_keys($login->json('user')));
    }

    public function test_unauthenticated_me_returns_french_401(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Non authentifié.']);
    }
}
