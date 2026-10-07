<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\Admin\AdminJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `mavioh:promouvoir` accorde le pouvoir le plus large de l'application : le geste doit laisser
 * une trace au journal, attribuée au système puisqu'aucun compte n'en est l'auteur.
 */
class PromouvoirAdministrateurTest extends TestCase
{
    use RefreshDatabase;

    public function test_promouvoir_puis_retirer_est_journalise(): void
    {
        $user = User::factory()->create(['email' => 'camille@example.com']);

        $this->artisan('mavioh:promouvoir', ['email' => 'Camille@example.com'])->assertSuccessful();
        $this->assertSame(UserRole::Administrateur, $user->fresh()->role);

        $this->artisan('mavioh:promouvoir', ['email' => 'camille@example.com', '--retirer' => true])->assertSuccessful();
        $this->assertSame(UserRole::Utilisateur, $user->fresh()->role);

        $lignes = AdminAction::query()->orderBy('id')->get();
        $this->assertSame(['promouvoir_administrateur', 'retirer_administrateur'], $lignes->pluck('action')->all());

        foreach ($lignes as $ligne) {
            $this->assertNull($ligne->admin_id);
            $this->assertSame(AdminJournal::AUTEUR_SYSTEME, $ligne->admin_email);
            $this->assertSame('user', $ligne->cible_type);
            $this->assertSame($user->id, (int) $ligne->cible_id);
            $this->assertSame('camille@example.com', $ligne->cible_libelle);
        }
    }

    public function test_rien_n_est_journalise_quand_rien_ne_change(): void
    {
        $user = User::factory()->create(['email' => 'camille@example.com']);
        $user->forceFill(['role' => UserRole::Administrateur])->save();

        $this->artisan('mavioh:promouvoir', ['email' => 'camille@example.com'])->assertSuccessful();
        $this->artisan('mavioh:promouvoir', ['email' => 'inconnu@example.com'])->assertFailed();

        $this->assertSame(0, AdminAction::query()->count());
    }
}
