<?php

namespace Tests\Feature\Offres;

use App\Enums\Offre;
use App\Enums\UserRole;
use App\Models\CodeAcces;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Codes d'accès : ouvrir l'application entière à quelqu'un, sans paiement.
 */
class CodeAccesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Administrateur])->save();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function creerCode(array $corps = []): string
    {
        $this->admin();

        return $this->postJson('/api/admin/codes', array_replace([
            'offre' => 'foyer',
            'utilisations_max' => 3,
            'note' => 'Invitations amis',
        ], $corps))->assertCreated()->json('data.code');
    }

    public function test_un_administrateur_cree_un_code_lisible_et_trace(): void
    {
        $admin = $this->admin();

        $code = $this->postJson('/api/admin/codes', [
            'offre' => 'foyer', 'utilisations_max' => 5, 'note' => 'Bêta',
        ])->assertCreated()->json('data.code');

        // Le code doit pouvoir se dicter : ni O/0, ni I/1, ni S/5.
        $this->assertSame(8, strlen($code));
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRTUVWXYZ2346789]+$/', $code);

        $this->assertDatabaseHas('admin_actions', [
            'admin_email' => $admin->email,
            'action' => 'creer_code_acces',
            'cible_libelle' => $code,
        ]);
    }

    public function test_un_utilisateur_ordinaire_ne_peut_pas_creer_de_code(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/admin/codes', ['offre' => 'foyer'])->assertNotFound();
        $this->getJson('/api/admin/codes')->assertNotFound();
    }

    public function test_un_code_ouvre_l_application_entiere(): void
    {
        $code = $this->creerCode();

        $ami = User::factory()->gratuit()->create();
        Sanctum::actingAs($ami, ['*']);

        // Avant : tout est fermé.
        $this->getJson('/api/stocks')->assertStatus(402);

        $this->postJson('/api/account/code', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.offre', 'foyer');

        // Après : tout est ouvert.
        $this->getJson('/api/stocks')->assertSuccessful();
        $this->getJson('/api/household')->assertSuccessful();
        $this->assertSame(Offre::Foyer, $ami->fresh()->offreEffective());
    }

    public function test_le_code_est_accepte_quelle_que_soit_sa_mise_en_forme(): void
    {
        $code = $this->creerCode();
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $melange = strtolower(substr($code, 0, 4)).'-'.strtolower(substr($code, 4));

        $this->postJson('/api/account/code', ['code' => $melange])->assertOk();
    }

    public function test_un_code_epuise_est_refuse(): void
    {
        $code = $this->creerCode(['utilisations_max' => 1]);

        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);
        $this->postJson('/api/account/code', ['code' => $code])->assertOk();

        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);
        $this->postJson('/api/account/code', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_la_meme_personne_ne_consomme_pas_deux_fois_le_meme_code(): void
    {
        $code = $this->creerCode(['utilisations_max' => 10]);
        $ami = User::factory()->gratuit()->create();
        Sanctum::actingAs($ami, ['*']);

        $this->postJson('/api/account/code', ['code' => $code])->assertOk();
        $this->postJson('/api/account/code', ['code' => $code])->assertStatus(422);

        $this->assertSame(1, CodeAcces::query()->first()->utilisations);
    }

    public function test_un_code_inconnu_est_refuse_sans_rien_reveler(): void
    {
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $this->postJson('/api/account/code', ['code' => 'ZZZZZZZZ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_un_code_a_duree_limitee_expire(): void
    {
        $code = $this->creerCode(['duree_jours' => 30]);
        $ami = User::factory()->gratuit()->create();
        Sanctum::actingAs($ami, ['*']);

        $this->postJson('/api/account/code', ['code' => $code])->assertOk();
        $this->assertNotNull($ami->fresh()->offre_expire_le);

        $this->travel(31)->days();
        $this->assertSame(Offre::Gratuit, $ami->fresh()->offreEffective());
        $this->getJson('/api/stocks')->assertStatus(402);
    }

    public function test_revoquer_un_code_empeche_les_nouvelles_utilisations(): void
    {
        $code = $this->creerCode(['utilisations_max' => 10]);
        $id = CodeAcces::query()->first()->id;

        $this->admin();
        $this->postJson("/api/admin/codes/{$id}/revoke", ['motif' => 'Diffusé par erreur'])->assertOk();

        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);
        $this->postJson('/api/account/code', ['code' => $code])->assertStatus(422);
    }

    /**
     * Un code ouvre, il ne referme jamais.
     *
     * Depuis que l'administrateur peut poser une offre à la main, le cas devient concret :
     * quelqu'un à qui on a accordé le Foyer perdrait son foyer — et les siens avec — en saisissant
     * un code « Complet » reçu ailleurs.
     */
    public function test_un_code_ne_retrograde_jamais_une_offre_deja_meilleure(): void
    {
        $this->admin();
        $code = $this->postJson('/api/admin/codes', ['offre' => 'complet', 'utilisations_max' => 5])
            ->assertCreated()->json('data.code');

        $ami = User::factory()->create();
        $ami->forceFill(['offre' => Offre::Foyer, 'offre_expire_le' => null])->save();
        Sanctum::actingAs($ami, ['*']);

        $this->postJson('/api/account/code', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(Offre::Foyer, $ami->fresh()->offre);
        $this->assertNull($ami->fresh()->offre_expire_le);
    }

    /** À rang égal, une échéance ne se rapproche pas : un accès sans terme le reste. */
    public function test_un_code_a_duree_ne_raccourcit_pas_un_acces_deja_accorde(): void
    {
        $this->admin();
        $code = $this->postJson('/api/admin/codes', [
            'offre' => 'foyer', 'utilisations_max' => 5, 'duree_jours' => 30,
        ])->assertCreated()->json('data.code');

        $ami = User::factory()->create();
        $ami->forceFill(['offre' => Offre::Foyer, 'offre_expire_le' => null])->save();
        Sanctum::actingAs($ami, ['*']);

        $this->postJson('/api/account/code', ['code' => $code])->assertOk();

        $this->assertNull($ami->fresh()->offre_expire_le, 'Un code de 30 jours ne borne pas un accès sans terme.');
    }

    public function test_les_essais_repetes_sont_limites(): void
    {
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/account/code', ['code' => 'AAAAAAA'.$i])->assertStatus(422);
        }

        // Le sixième essai dans la minute est refusé : deviner un code devient sans espoir.
        $this->postJson('/api/account/code', ['code' => 'BBBBBBBB'])->assertStatus(429);
    }

    /**
     * Un seul message de refus, quelle qu'en soit la raison : sinon la réponse dirait qu'un code
     * existe (déjà utilisé, épuisé, expiré, moins bon que l'offre actuelle).
     */
    public function test_tous_les_refus_portent_le_meme_message(): void
    {
        $this->admin();
        $epuise = $this->postJson('/api/admin/codes', ['offre' => 'foyer', 'utilisations_max' => 1])->json('data.code');
        $complet = $this->postJson('/api/admin/codes', ['offre' => 'complet', 'utilisations_max' => 5])->json('data.code');
        $expire = $this->postJson('/api/admin/codes', ['offre' => 'foyer', 'utilisations_max' => 5, 'expire_le' => now()->addDay()->toDateString()])->json('data.code');
        $reutilise = $this->postJson('/api/admin/codes', ['offre' => 'foyer', 'utilisations_max' => 5])->json('data.code');

        $messages = [];
        $essai = function (User $user, string $code) use (&$messages) {
            Sanctum::actingAs($user, ['*']);
            $messages[] = $this->postJson('/api/account/code', ['code' => $code])
                ->assertStatus(422)
                ->json('errors.code.0');
        };

        // Inconnu.
        $essai(User::factory()->gratuit()->create(), 'ZZZZZZZZ');

        // Épuisé.
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);
        $this->postJson('/api/account/code', ['code' => $epuise])->assertOk();
        $essai(User::factory()->gratuit()->create(), $epuise);

        // Déjà utilisé par la même personne.
        $ami = User::factory()->gratuit()->create();
        Sanctum::actingAs($ami, ['*']);
        $this->postJson('/api/account/code', ['code' => $reutilise])->assertOk();
        $essai($ami, $reutilise);

        // Offre actuelle déjà supérieure.
        $foyer = User::factory()->create();
        $foyer->forceFill(['offre' => Offre::Foyer, 'offre_expire_le' => null])->save();
        $essai($foyer, $complet);

        // Expiré.
        $this->travel(3)->days();
        $essai(User::factory()->gratuit()->create(), $expire);

        $this->assertCount(5, $messages);
        $this->assertSame([\App\Services\Offres\CodeAccesService::MSG_REFUS], array_values(array_unique($messages)));
    }

    public function test_les_essais_sont_aussi_limites_par_adresse_ip(): void
    {
        // Six comptes, cinq essais chacun : aucun n'atteint son plafond personnel, mais l'adresse
        // a épuisé ses 30 essais de l'heure.
        for ($compte = 0; $compte < 6; $compte++) {
            Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

            for ($i = 0; $i < 5; $i++) {
                $this->postJson('/api/account/code', ['code' => 'AAAAAAA'.$i])->assertStatus(422);
            }
        }

        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);
        $this->postJson('/api/account/code', ['code' => 'BBBBBBBB'])->assertStatus(429);
    }
}
