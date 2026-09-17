<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuthExtensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_normalises_email_creates_settings_and_fires_registered(): void
    {
        Event::fake([Registered::class]);

        $response = $this->postJson('/api/register', [
            'name' => 'Léa',
            'email' => '  LEA@Example.COM ',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()->assertJsonPath('user.email', 'lea@example.com');

        $user = User::query()->where('email', 'lea@example.com')->firstOrFail();
        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id, 'timezone' => 'Europe/Paris']);
        $this->assertSame(1, UserSetting::query()->where('user_id', $user->id)->count());

        Event::assertDispatched(Registered::class, fn (Registered $e) => $e->user->is($user));
    }

    public function test_register_rejects_duplicate_email_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'lea@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Léa',
            'email' => 'LEA@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString('adresse e-mail', $response->json('errors.email.0'));
    }

    public function test_register_validation_is_french(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => '',
            'email' => 'pas-un-email',
            'password' => 'court',
            'password_confirmation' => 'autre',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->assertStringContainsString('nom', $response->json('errors.name.0'));
        $this->assertStringContainsString('mot de passe', $response->json('errors.password.0'));
    }

    public function test_login_normalises_email(): void
    {
        User::factory()->create(['email' => 'lea@example.com', 'password' => bcrypt('secret123')]);

        $this->postJson('/api/login', ['email' => '  Lea@EXAMPLE.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('user.email', 'lea@example.com');
    }

    public function test_logout_deletes_the_current_token_only(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $first = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');
        $second = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])->json('token');

        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($first)->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Déconnecté.']);

        $this->assertSame(1, $user->tokens()->count());

        // Le premier jeton est révoqué, le second reste valide (le garde est réinitialisé entre les requêtes).
        $this->app['auth']->forgetGuards();
        $this->withToken($first)->getJson('/api/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/me')->assertOk();
    }

    public function test_me_lazily_creates_settings(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseMissing('user_settings', ['user_id' => $user->id]);

        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('settings.timezone', 'Europe/Paris');

        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id]);
    }
}
