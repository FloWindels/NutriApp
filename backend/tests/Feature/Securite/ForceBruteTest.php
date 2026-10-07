<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plafonds contre la force brute : connexion, changement de mot de passe, suppression du compte.
 */
class ForceBruteTest extends TestCase
{
    use RefreshDatabase;

    private function connexion(string $email, string $password, string $ip = '10.0.0.1'): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_la_connexion_est_bloquee_apres_5_essais_par_minute_pour_un_email_et_une_ip(): void
    {
        User::factory()->create(['email' => 'lea@example.com', 'password' => Hash::make('bon-mot-de-passe')]);

        for ($i = 0; $i < 5; $i++) {
            $this->connexion('lea@example.com', 'mauvais')->assertStatus(422);
        }

        // Même le bon mot de passe est refusé : le plafond ne dépend pas du résultat.
        $this->connexion('lea@example.com', 'bon-mot-de-passe')->assertStatus(429);
        $this->connexion('LEA@example.com', 'bon-mot-de-passe')->assertStatus(429);

        // Une autre adresse depuis la même IP n'est pas concernée.
        $this->connexion('autre@example.com', 'x')->assertStatus(422);

        // Le préfixe /api/v1 partage le même compteur.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/login', ['email' => 'lea@example.com', 'password' => 'bon-mot-de-passe'])
            ->assertStatus(429);
    }

    public function test_un_meme_email_est_plafonne_a_20_essais_par_heure_toutes_ip_confondues(): void
    {
        User::factory()->create(['email' => 'lea@example.com', 'password' => Hash::make('bon-mot-de-passe')]);

        // 5 IP × 4 essais : jamais 5 par minute pour un même couple (e-mail, IP).
        for ($ip = 1; $ip <= 5; $ip++) {
            for ($i = 0; $i < 4; $i++) {
                $this->connexion('lea@example.com', 'mauvais', '10.0.1.'.$ip)->assertStatus(422);
            }
        }

        $this->connexion('lea@example.com', 'bon-mot-de-passe', '10.0.2.1')->assertStatus(429);

        // Une heure plus tard, la porte se rouvre.
        $this->travel(61)->minutes();
        $this->connexion('lea@example.com', 'bon-mot-de-passe', '10.0.2.1')->assertOk();
    }

    public function test_le_changement_de_mot_de_passe_est_plafonne_a_5_par_minute(): void
    {
        Sanctum::actingAs(User::factory()->create(['password' => Hash::make('ancien-mdp-1')]));

        for ($i = 0; $i < 5; $i++) {
            $this->putJson('/api/account/password', [
                'current_password' => 'mauvais',
                'password' => 'nouveau-mdp-1',
                'password_confirmation' => 'nouveau-mdp-1',
            ])->assertStatus(422);
        }

        $this->putJson('/api/account/password', [
            'current_password' => 'ancien-mdp-1',
            'password' => 'nouveau-mdp-1',
            'password_confirmation' => 'nouveau-mdp-1',
        ])->assertStatus(429);
    }

    public function test_la_suppression_du_compte_est_plafonnee_a_5_par_minute(): void
    {
        $user = User::factory()->create(['password' => Hash::make('bon-mot-de-passe')]);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->deleteJson('/api/account', ['password' => 'mauvais'])->assertStatus(422);
        }

        $this->deleteJson('/api/account', ['password' => 'bon-mot-de-passe'])->assertStatus(429);
        $this->assertModelExists($user);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function formulairesAvecMotDePasse(): array
    {
        $inscription = [
            'name' => 'Léa',
            'email' => 'lea@example.com',
            'accept_conditions' => true,
            'cgu_version' => '2026-09-25',
            'confidentialite_version' => '2026-09-25',
        ];

        return [
            'inscription' => ['/api/register', $inscription],
            'réinitialisation' => ['/api/reset-password', ['email' => 'lea@example.com', 'token' => 'x']],
        ];
    }

    /**
     * @dataProvider formulairesAvecMotDePasse
     *
     * @param  array<string, mixed>  $corps
     */
    public function test_un_mot_de_passe_de_moins_de_10_caracteres_est_refuse(string $route, array $corps): void
    {
        $reponse = $this->postJson($route, $corps + ['password' => '123456789', 'password_confirmation' => '123456789']);

        $reponse->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertStringContainsString('10', $reponse->json('errors.password.0'));
    }

    public function test_le_nouveau_mot_de_passe_doit_faire_10_caracteres(): void
    {
        Sanctum::actingAs(User::factory()->create(['password' => Hash::make('ancien-mdp-1')]));

        $this->putJson('/api/account/password', [
            'current_password' => 'ancien-mdp-1',
            'password' => '123456789',
            'password_confirmation' => '123456789',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->putJson('/api/account/password', [
            'current_password' => 'ancien-mdp-1',
            'password' => '1234567890',
            'password_confirmation' => '1234567890',
        ])->assertOk();
    }
}
