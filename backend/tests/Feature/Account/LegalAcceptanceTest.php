<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Preuve de l'acceptation des conditions (RGPD art. 7.1).
 *
 * Le responsable du traitement doit pouvoir démontrer que la personne a consenti, et à quoi.
 * Un compte ne peut donc pas exister sans date ni version des textes acceptés.
 */
class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function inscription(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Léa Martin',
            'email' => 'lea@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'accept_conditions' => true,
            'cgu_version' => '2026-09-25',
            'confidentialite_version' => '2026-09-25',
        ], $overrides);
    }

    public function test_l_inscription_enregistre_la_date_et_les_versions_acceptees(): void
    {
        $this->postJson('/api/register', $this->inscription())->assertCreated();

        $user = User::query()->where('email', 'lea@example.com')->firstOrFail();

        $this->assertNotNull($user->cgu_accepted_at, 'La date d’acceptation est la preuve exigée.');
        $this->assertSame('2026-09-25', $user->cgu_version);
        $this->assertSame('2026-09-25', $user->confidentialite_version);
    }

    public function test_l_inscription_est_refusee_sans_acceptation(): void
    {
        $this->postJson('/api/register', $this->inscription(['accept_conditions' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('accept_conditions');

        $this->postJson('/api/register', $this->inscription(['accept_conditions' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('accept_conditions');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_l_inscription_est_refusee_sans_version_de_texte(): void
    {
        $sansVersion = $this->inscription();
        unset($sansVersion['cgu_version']);

        $this->postJson('/api/register', $sansVersion)
            ->assertStatus(422)
            ->assertJsonValidationErrors('cgu_version');
    }

    public function test_la_preuve_est_visible_par_la_personne_concernee(): void
    {
        $this->postJson('/api/register', $this->inscription())->assertCreated();
        $user = User::query()->where('email', 'lea@example.com')->firstOrFail();
        Sanctum::actingAs($user, ['*']);

        // Art. 15 : la personne doit pouvoir consulter ce qui la concerne.
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('conditions.cgu_version', '2026-09-25')
            ->assertJsonPath('conditions.confidentialite_version', '2026-09-25');

        // Art. 20 : et le retrouver dans son export.
        $export = $this->getJson('/api/account/export')->assertOk()->json();
        $this->assertSame('2026-09-25', data_get($export, 'data.user.cgu_version'));
        $this->assertNotNull(data_get($export, 'data.user.conditions_acceptees_le'));
    }
}
