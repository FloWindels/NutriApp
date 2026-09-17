<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_link_to_frontend_url(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lea@example.com']);

        $response = $this->postJson('/api/forgot-password', ['email' => 'LEA@example.com']);

        $response->assertOk()->assertExactJson([
            'message' => 'Si un compte existe, un lien de réinitialisation a été envoyé.',
        ]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringStartsWith('http://localhost:3000/reset-password?token=', $url);
            $this->assertStringContainsString('&email=lea%40example.com', $url);
            $this->assertStringContainsString('token='.$notification->token, $url);

            return true;
        });
    }

    public function test_forgot_password_is_always_200_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'inconnu@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'Si un compte existe, un lien de réinitialisation a été envoyé.');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_email_in_french(): void
    {
        $response = $this->postJson('/api/forgot-password', ['email' => 'pas-un-email']);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertStringContainsString('adresse e-mail', $response->json('errors.email.0'));
    }

    public function test_reset_password_updates_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['email' => 'lea@example.com', 'password' => bcrypt('ancien-mdp')]);
        $user->createToken('mobile');
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'Lea@Example.com',
            'token' => $token,
            'password' => 'nouveau-mdp-1',
            'password_confirmation' => 'nouveau-mdp-1',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Mot de passe réinitialisé.']);

        $user->refresh();
        $this->assertTrue(Hash::check('nouveau-mdp-1', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'lea@example.com']);

        $this->postJson('/api/login', ['email' => 'lea@example.com', 'password' => 'nouveau-mdp-1'])->assertOk();
    }

    public function test_reset_password_with_invalid_token_is_422(): void
    {
        User::factory()->create(['email' => 'lea@example.com']);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'lea@example.com',
            'token' => 'jeton-invalide',
            'password' => 'nouveau-mdp-1',
            'password_confirmation' => 'nouveau-mdp-1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertSame('Ce lien de réinitialisation n’est plus valide.', $response->json('errors.email.0'));
    }

    public function test_reset_password_validation_requires_confirmed_password(): void
    {
        $response = $this->postJson('/api/reset-password', [
            'email' => 'lea@example.com',
            'token' => 'x',
            'password' => 'court',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}
