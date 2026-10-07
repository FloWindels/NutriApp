<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RedefinirMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    public function test_redefinit_le_mot_de_passe_ferme_les_sessions_et_laisse_une_trace(): void
    {
        $user = User::factory()->create(['email' => 'florent@exemple.test']);
        $user->createToken('ancien');

        $this->artisan('mavioh:mot-de-passe', ['email' => 'Florent@Exemple.test '])
            ->expectsQuestion('Nouveau mot de passe (10 caractères au moins)', 'nouveau-secret-42')
            ->expectsQuestion('Confirme le mot de passe', 'nouveau-secret-42')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('nouveau-secret-42', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('admin_actions', ['action' => 'redefinir_mot_de_passe', 'cible_id' => $user->id]);

        $this->postJson('/api/login', ['email' => 'florent@exemple.test', 'password' => 'nouveau-secret-42'])
            ->assertOk();
    }

    public function test_refuse_un_mot_de_passe_trop_court_ou_mal_confirme(): void
    {
        $user = User::factory()->create(['email' => 'florent@exemple.test']);
        $avant = $user->password;

        $this->artisan('mavioh:mot-de-passe', ['email' => 'florent@exemple.test'])
            ->expectsQuestion('Nouveau mot de passe (10 caractères au moins)', 'court')
            ->expectsQuestion('Confirme le mot de passe', 'court')
            ->assertFailed();

        $this->artisan('mavioh:mot-de-passe', ['email' => 'florent@exemple.test'])
            ->expectsQuestion('Nouveau mot de passe (10 caractères au moins)', 'nouveau-secret-42')
            ->expectsQuestion('Confirme le mot de passe', 'autre-secret-42')
            ->assertFailed();

        $this->assertSame($avant, $user->fresh()->password);
    }

    public function test_adresse_inconnue(): void
    {
        $this->artisan('mavioh:mot-de-passe', ['email' => 'personne@exemple.test'])->assertFailed();
    }
}
