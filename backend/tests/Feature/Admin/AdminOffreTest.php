<?php

namespace Tests\Feature\Admin;

use App\Enums\Offre;
use App\Enums\UserRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Poser l’offre d’un compte à la main, depuis le panneau d’administration.
 *
 * Aucun paiement n’est branché : ce geste et le code d’accès sont les deux seules façons
 * d’ouvrir le produit à quelqu’un. Il décide de ce à quoi une personne a droit, parfois contre
 * de l’argent — d’où le motif obligatoire et la trace au journal, qui rendent le geste
 * défendable devant la personne concernée.
 *
 * L’offre gratuite garde le coach du jour ; ce sont les séances personnalisées qui restent
 * payantes. Ces tests ne remettent pas ce découpage en cause, ils vérifient qu’on peut faire
 * passer un compte d’une offre à l’autre sans rien casser ni rien détruire.
 */
class AdminOffreTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Administrateur])->save();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_un_visiteur_anonyme_recoit_404(): void
    {
        $cible = User::factory()->gratuit()->create();

        // 401 confirmerait l’existence du couloir ; 404 le rend indistinguable d’une URL inconnue.
        $this->postJson("/api/admin/users/{$cible->id}/offre", ['offre' => 'foyer', 'motif' => 'essai'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Introuvable.');

        $this->assertSame(Offre::Gratuit, $cible->fresh()->offre);
    }

    public function test_un_utilisateur_ordinaire_ne_peut_pas_s_offrir_une_offre(): void
    {
        $cible = User::factory()->gratuit()->create();
        Sanctum::actingAs($cible, ['*']);

        // La tentation évidente : s’octroyer soi-même le foyer. La porte ne s’avoue même pas.
        $this->postJson("/api/admin/users/{$cible->id}/offre", ['offre' => 'foyer', 'motif' => 'pour moi'])
            ->assertNotFound();

        $this->assertSame(Offre::Gratuit, $cible->fresh()->offre);
    }

    public function test_un_administrateur_ouvre_aussitot_un_compte_gratuit(): void
    {
        $admin = $this->admin();
        $cible = User::factory()->gratuit()->create();

        // Avant : le stock est fermé, comme pour n’importe quel compte gratuit.
        Sanctum::actingAs($cible, ['*']);
        $this->getJson('/api/stocks')->assertStatus(402);

        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'foyer',
            'motif' => 'Bêta-testeur de la première heure',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Offre mise à jour.')
            ->assertJsonPath('data.offre', 'foyer')
            ->assertJsonPath('data.offre_libelle', 'Foyer')
            ->assertJsonPath('data.offre_expire_le', null);

        $this->assertSame(Offre::Foyer, $cible->fresh()->offre);
        $this->assertNull($cible->fresh()->offre_expire_le);

        // Après : le droit est effectif tout de suite, sans reconnexion ni nouveau jeton.
        Sanctum::actingAs($cible->fresh(), ['*']);
        $this->getJson('/api/stocks')->assertSuccessful();
    }

    public function test_le_geste_laisse_une_trace_lisible_au_journal(): void
    {
        $admin = $this->admin();
        $cible = User::factory()->gratuit()->create(['email' => 'camille@example.com']);

        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'foyer',
            'motif' => 'Gain du concours de septembre',
        ])->assertOk();

        // Le libellé doit se lire seul, des mois plus tard, sans recroiser aucune autre table.
        $this->assertDatabaseHas('admin_actions', [
            'admin_email' => $admin->email,
            'action' => 'changer_offre',
            'cible_type' => 'user',
            'cible_id' => $cible->id,
            'cible_libelle' => 'camille@example.com : Gratuit → Foyer · sans échéance',
            'motif' => 'Gain du concours de septembre',
        ]);
    }

    public function test_le_motif_est_obligatoire(): void
    {
        $this->admin();
        $cible = User::factory()->gratuit()->create();

        $this->postJson("/api/admin/users/{$cible->id}/offre", ['offre' => 'foyer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motif');

        // Un refus de validation ne doit rien avoir changé au passage.
        $this->assertSame(Offre::Gratuit, $cible->fresh()->offre);
    }

    public function test_une_offre_inconnue_est_refusee(): void
    {
        $this->admin();
        $cible = User::factory()->gratuit()->create();

        // Les offres vivent dans un enum : une valeur inventée ne doit pas atterrir en base.
        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'premium',
            'motif' => 'Offre imaginaire',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('offre');

        $this->assertSame(Offre::Gratuit, $cible->fresh()->offre);
    }

    public function test_une_duree_sur_l_offre_gratuite_est_refusee(): void
    {
        $this->admin();
        $cible = User::factory()->create();
        $cible->forceFill(['offre' => Offre::Foyer])->save();

        // Une offre gratuite n’expire pas : accepter une durée ici promettrait une échéance
        // qui ne se produirait jamais, et laisserait croire à un retour automatique au payant.
        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'gratuit',
            'duree_jours' => 30,
            'motif' => 'Fin de la période offerte',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('duree_jours');

        $this->assertSame(Offre::Foyer, $cible->fresh()->offre);
    }

    public function test_une_duree_pose_une_echeance_qui_s_eteint_sans_rien_effacer(): void
    {
        $admin = $this->admin();
        $cible = User::factory()->gratuit()->create();

        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'complet',
            'duree_jours' => 30,
            'motif' => 'Un mois offert après l’incident',
        ])
            ->assertOk()
            ->assertJsonPath('data.offre', 'complet');

        $this->assertNotNull($cible->fresh()->offre_expire_le);

        Sanctum::actingAs($cible->fresh(), ['*']);
        $this->getJson('/api/stocks')->assertSuccessful();

        $this->travel(31)->days();

        // L’échéance passée, le droit s’éteint de lui-même : personne n’a à repasser derrière.
        $this->assertSame(Offre::Gratuit, $cible->fresh()->offreEffective());
        $this->getJson('/api/stocks')->assertStatus(402);

        // Mais la colonne garde l’offre souscrite : on doit pouvoir dire ce qui a été accordé,
        // et la personne retrouve tout le jour où elle reprend une offre.
        $this->assertSame(Offre::Complet, $cible->fresh()->offre);

        Sanctum::actingAs($admin, ['*']);
        // L'échéance figure dans la trace : accorder un jour et accorder dix ans ne peuvent pas
        // s'écrire pareil, sans quoi le journal ne chiffre plus le geste.
        $this->assertDatabaseHas('admin_actions', [
            'action' => 'changer_offre',
            'cible_libelle' => $cible->email.' : Gratuit → Complet · jusqu’au '
                .now()->subDays(31)->addDays(30)->format('d/m/Y'),
        ]);
    }

    /**
     * Reposer une offre déjà échue est un vrai geste commercial : la trace doit le montrer,
     * sinon « Complet → Complet » se lit comme un non-événement six mois plus tard.
     */
    public function test_la_trace_distingue_une_offre_echue_d_une_offre_encore_active(): void
    {
        $this->admin();
        $cible = User::factory()->create(['email' => 'camille@example.com']);
        $cible->forceFill(['offre' => Offre::Complet, 'offre_expire_le' => now()->subMonth()])->save();

        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'complet',
            'duree_jours' => 365,
            'motif' => 'Geste commercial',
        ])->assertOk();

        $this->assertDatabaseHas('admin_actions', [
            'action' => 'changer_offre',
            'cible_libelle' => 'camille@example.com : Complet (échue) → Complet · jusqu’au '
                .now()->addDays(365)->format('d/m/Y'),
        ]);
    }

    /**
     * « integer » laisse passer true, et Carbon::addDays(true) rend la date inchangée : l'offre
     * s'éteignait à la seconde où elle était accordée, pendant que la réponse annonçait un succès.
     */
    public function test_une_duree_booleenne_ne_pose_pas_une_echeance_deja_passee(): void
    {
        $this->admin();
        $cible = User::factory()->gratuit()->create();

        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'complet',
            'duree_jours' => true,
            'motif' => 'Un mois offert',
        ])->assertOk();

        $frais = $cible->fresh();
        $this->assertTrue($frais->offre_expire_le->isFuture());
        $this->assertSame(Offre::Complet, $frais->offreValide());
    }

    /**
     * Un identifiant fait de chiffres mais hors des bornes de PHP franchissait whereNumber puis
     * faisait exploser le paramètre typé : 500, avec la signature du contrôleur en prime.
     */
    public function test_un_identifiant_hors_bornes_repond_404_et_non_500(): void
    {
        $this->admin();

        $this->postJson('/api/admin/users/99999999999999999999/offre', [
            'offre' => 'complet',
            'motif' => 'Identifiant recopié de travers',
        ])->assertNotFound();
    }

    /**
     * L'espace d'administration tient sur un seul principe : il ne s'avoue pas. Un mauvais verbe
     * révélait pourtant le chemin exact et la méthode attendue, sans le moindre jeton.
     */
    public function test_un_mauvais_verbe_ne_revele_pas_l_espace_d_administration(): void
    {
        $cible = User::factory()->gratuit()->create();

        foreach ([
            "/api/admin/users/{$cible->id}/offre",
            "/api/admin/users/{$cible->id}/suspend",
            '/api/admin/stats',
            '/api/admin/codes',
        ] as $route) {
            $reponse = $this->getJson($route.'?x=1');

            // Une adresse voisine qui n'existe pas répond exactement pareil.
            $this->assertSame(404, $reponse->status(), "{$route} trahit l’espace d’administration.");
            $this->assertSame('Introuvable.', $reponse->json('message'));
        }

        $this->putJson("/api/admin/users/{$cible->id}/offre", ['offre' => 'foyer'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Introuvable.');
    }

    public function test_repasser_au_gratuit_efface_l_echeance_sans_detruire_le_compte(): void
    {
        $this->admin();
        $cible = User::factory()->create(['name' => 'Alex', 'email' => 'alex@example.com']);
        $cible->forceFill(['offre' => Offre::Foyer, 'offre_expire_le' => now()->addDays(10)])->save();
        $cible->profile()->create([
            'sexe' => 'homme', 'age' => 30, 'taille' => 180, 'poids' => 82,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'omnivore',
        ]);

        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'gratuit',
            'motif' => 'Abonnement résilié par la personne',
        ])
            ->assertOk()
            ->assertJsonPath('data.offre', 'gratuit')
            ->assertJsonPath('data.offre_libelle', 'Gratuit')
            ->assertJsonPath('data.offre_expire_le', null);

        $frais = $cible->fresh();
        $this->assertSame(Offre::Gratuit, $frais->offre);
        // Laisser traîner l’ancienne échéance ferait afficher « expire le… » sur un compte gratuit.
        $this->assertNull($frais->offre_expire_le);

        // Perdre une offre n’est pas perdre son compte : le suivi continue, les données restent.
        $this->assertSame('Alex', $frais->name);
        $this->assertSame('alex@example.com', $frais->email);
        $this->assertDatabaseHas('profiles', ['user_id' => $cible->id, 'poids' => 82]);

        Sanctum::actingAs($frais, ['*']);
        $this->getJson('/api/profile')->assertSuccessful();
        // Le coach du jour reste ouvert au gratuit : c’est la promesse faite au propriétaire.
        $this->getJson('/api/recommendations')->assertSuccessful();
    }

    public function test_le_listing_dit_l_offre_souscrite_et_celle_dont_on_dispose(): void
    {
        $this->admin();

        // Le cas qui compte : un foyer payé par son propriétaire, et un membre resté « gratuit ».
        // Sans l’offre effective, l’administrateur croirait ce membre bloqué et le « corrigerait ».
        $proprietaire = User::factory()->create(['email' => 'chef@example.com']);
        $proprietaire->forceFill(['offre' => Offre::Foyer])->save();
        $foyer = Household::factory()->create(['owner_id' => $proprietaire->id]);
        $proprietaire->forceFill(['household_id' => $foyer->id])->save();
        $membre = User::factory()->gratuit()->create([
            'email' => 'membre@example.com',
            'household_id' => $foyer->id,
        ]);

        // L’autre cas trompeur : une offre payante dont l’échéance est passée.
        $expire = User::factory()->create(['email' => 'expire@example.com']);
        $expire->forceFill(['offre' => Offre::Complet, 'offre_expire_le' => now()->subDay()])->save();

        $lignes = collect($this->getJson('/api/admin/users')->assertOk()->json('data'));

        foreach ($lignes as $ligne) {
            foreach (['offre', 'offre_libelle', 'offre_expire_le', 'offre_valide', 'offre_effective',
                'offre_effective_libelle', 'foyer_membres_couverts'] as $cle) {
                $this->assertArrayHasKey($cle, $ligne, "La ligne {$ligne['email']} n’expose pas « {$cle} ».");
            }
            // Les colonnes déjà en place ne bougent pas : le listing gagne, il ne remplace pas.
            $this->assertArrayHasKey('email', $ligne);
            $this->assertArrayHasKey('suspendu_le', $ligne);
        }

        $ligneMembre = $lignes->firstWhere('id', $membre->id);
        $this->assertSame('gratuit', $ligneMembre['offre']);
        $this->assertSame('Gratuit', $ligneMembre['offre_libelle']);
        $this->assertNull($ligneMembre['offre_expire_le']);
        $this->assertSame('foyer', $ligneMembre['offre_effective']);
        $this->assertSame('Foyer', $ligneMembre['offre_effective_libelle']);

        $ligneProprietaire = $lignes->firstWhere('id', $proprietaire->id);
        $this->assertSame('foyer', $ligneProprietaire['offre']);
        $this->assertSame('foyer', $ligneProprietaire['offre_effective']);

        $ligneExpiree = $lignes->firstWhere('id', $expire->id);
        $this->assertSame('complet', $ligneExpiree['offre'], 'L’offre souscrite ne s’efface pas à l’échéance.');
        $this->assertNotNull($ligneExpiree['offre_expire_le']);
        $this->assertSame('gratuit', $ligneExpiree['offre_effective']);
        $this->assertSame('Gratuit', $ligneExpiree['offre_effective_libelle']);

        // L'échéance se joue à l'heure près : c'est le serveur qui dit si l'offre tient encore,
        // sinon l'écran, qui ne voit qu'une date, la donnerait active des heures de trop.
        $this->assertSame('gratuit', $ligneExpiree['offre_valide']);
        $this->assertSame('foyer', $ligneProprietaire['offre_valide']);
        $this->assertSame('gratuit', $ligneMembre['offre_valide']);

        // Le propriétaire porte un membre : le panneau doit pouvoir prévenir avant de le couper.
        $this->assertSame(1, $ligneProprietaire['foyer_membres_couverts']);
        $this->assertSame(0, $ligneMembre['foyer_membres_couverts']);
        $this->assertSame(0, $ligneExpiree['foyer_membres_couverts']);
    }

    /**
     * Le compte de comptes couverts doit tenir en une requête, quelle que soit la page : c'est
     * exactement le genre de colonne qui réintroduit un N+1 sans que personne s'en aperçoive.
     */
    public function test_le_listing_ne_multiplie_pas_les_requetes_avec_les_foyers(): void
    {
        $this->admin();

        for ($i = 0; $i < 6; $i++) {
            $proprietaire = User::factory()->create();
            $proprietaire->forceFill(['offre' => Offre::Foyer])->save();
            $foyer = Household::factory()->create(['owner_id' => $proprietaire->id]);
            $proprietaire->forceFill(['household_id' => $foyer->id])->save();
            User::factory()->gratuit()->count(2)->create(['household_id' => $foyer->id]);
        }

        $requetes = 0;
        \DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $this->getJson('/api/admin/users')->assertOk();

        $this->assertLessThanOrEqual(6, $requetes, "Le listing a déclenché {$requetes} requêtes : un N+1 s’est glissé.");
    }

    public function test_changer_l_offre_ne_reveille_pas_un_compte_suspendu(): void
    {
        $this->admin();
        $cible = User::factory()->gratuit()->create([
            'email' => 'gene@example.com',
            'password' => bcrypt('secret123'),
        ]);
        $cible->forceFill([
            'suspendu_le' => now()->subDays(3),
            'suspension_motif' => 'Contenus répétés hors sujet',
        ])->save();
        $suspenduLe = $cible->fresh()->suspendu_le;

        $this->postJson("/api/admin/users/{$cible->id}/offre", [
            'offre' => 'complet',
            'motif' => 'Régularisation comptable',
        ])->assertOk();

        // Deux décisions distinctes : payer ne lève pas une suspension, et une suspension ne
        // doit pas se lever par un chemin détourné. Seule /restore le fait, avec son motif.
        $frais = $cible->fresh();
        $this->assertSame(Offre::Complet, $frais->offre);
        $this->assertNotNull($frais->suspendu_le);
        $this->assertTrue($suspenduLe->equalTo($frais->suspendu_le));
        $this->assertSame('Contenus répétés hors sujet', $frais->suspension_motif);

        $this->postJson('/api/login', ['email' => 'gene@example.com', 'password' => 'secret123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
