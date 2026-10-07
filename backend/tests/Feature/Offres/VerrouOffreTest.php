<?php

namespace Tests\Feature\Offres;

use App\Enums\Offre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verrouillage des fonctionnalités par offre.
 *
 * Le test le plus important est le premier : il parcourt les routes RÉELLES de l'application au
 * lieu de relire une liste recopiée à la main. Une route ajoutée demain dans un module payant
 * sans son verrou fait échouer la suite, ce qu'aucune relecture humaine ne garantit.
 */
class VerrouOffreTest extends TestCase
{
    use RefreshDatabase;

    /** Préfixes dont toute route doit porter un verrou, et la capacité attendue. */
    private const MODULES_PAYANTS = [
        '/household' => 'foyer',
        '/stocks' => 'stock',
        '/shopping-list' => 'courses',
        '/planner' => 'planificateur',
        '/recommendations' => 'coach',
        '/diets' => 'regimes',
        // Le catalogue des magasins ne relève d'aucune offre (voir CATALOGUES_SANS_OFFRE) : seules
        // les promotions, qui pèsent sur la liste et sur les propositions de repas, sont payantes.
        '/magasins' => 'courses',
    ];

    /**
     * Routes d'un module payant dont le verrou est posé par le contrôleur, faute de pouvoir l'être
     * par la route : c'est l'offre du PROPRIÉTAIRE du foyer visé qui décide si l'invité entre.
     */
    private const VERROU_DANS_LE_CONTROLEUR = [
        '/household/preview',
        '/household/join',
    ];

    /**
     * Sorties du foyer, par méthode : voir son foyer, le quitter, le dissoudre, retirer le
     * partage de son profil. Jamais derrière une offre — quand l'offre Foyer du propriétaire
     * expire, chacun doit pouvoir reprendre la main sur ses données.
     */
    private const SORTIES_DU_FOYER = [
        'GET /household',
        'POST /household/leave',
        'DELETE /household',
        'PUT /household/members/me',
    ];

    /**
     * Catalogues de référence : réservés aux comptes (auth:sanctum), mais sans offre requise.
     */
    private const CATALOGUES_SANS_OFFRE = [
        '/diets',
        '/diets/{key}',
        '/magasins',
        '/magasins/{magasin}/produits',
    ];

    public function test_toute_route_d_un_module_payant_porte_son_verrou(): void
    {
        $oublis = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            // Seul le montage /api est examiné : /api/v1 sert le même fichier de routes.
            if (! str_starts_with($uri, '/api/')) {
                continue;
            }

            $chemin = substr($uri, 4);

            if (in_array($chemin, self::VERROU_DANS_LE_CONTROLEUR, true)
                || in_array($chemin, self::CATALOGUES_SANS_OFFRE, true)) {
                continue;
            }

            $methodes = array_diff($route->methods(), ['HEAD']);
            if (array_diff(array_map(fn ($m) => $m.' '.$chemin, $methodes), self::SORTIES_DU_FOYER) === []) {
                continue;
            }

            $middlewares = $route->gatherMiddleware();

            // Seules les routes authentifiées peuvent relever d'une offre.
            if (! in_array('auth:sanctum', $middlewares, true)) {
                continue;
            }

            foreach (self::MODULES_PAYANTS as $prefixe => $capacite) {
                if ($chemin !== $prefixe && ! str_starts_with($chemin, $prefixe.'/')) {
                    continue;
                }

                $verrous = array_filter(
                    $middlewares,
                    fn ($m) => is_string($m) && str_starts_with($m, 'offre:'),
                );

                if ($verrous === []) {
                    $oublis[] = $chemin.' (attendu : offre:'.$capacite.')';
                }
            }
        }

        $this->assertSame(
            [],
            $oublis,
            "Ces routes appartiennent à un module payant et ne portent aucun verrou :\n".implode("\n", $oublis),
        );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function routesPayantes(): array
    {
        return [
            'foyer' => ['post', '/api/household/regenerate-code'],
            'stock' => ['get', '/api/stocks'],
            'alertes de stock' => ['get', '/api/stocks/alerts'],
            'courses' => ['get', '/api/shopping-list'],
            'planificateur' => ['get', '/api/planner'],
            // Le catalogue /api/diets ne relève d'aucune offre ; c'est l'évaluation qui est payante.
            'régimes' => ['get', '/api/diets/evaluate'],
        ];
    }

    /**
     * @dataProvider routesPayantes
     */
    public function test_un_compte_gratuit_est_refuse_avec_402_et_un_message(string $verbe, string $route): void
    {
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $reponse = $this->{$verbe.'Json'}($route)->assertStatus(402);

        // Le message doit être explicite : 402 ne figure pas dans la table par défaut de Laravel.
        $this->assertNotSame('Erreur.', $reponse->json('message'));
        $this->assertStringContainsString('offre', mb_strtolower((string) $reponse->json('message')));
        $this->assertNotNull($reponse->json('capacite_requise'));
    }

    /**
     * @dataProvider routesPayantes
     */
    public function test_un_compte_foyer_passe_partout(string $verbe, string $route): void
    {
        $user = User::factory()->create();
        $user->forceFill(['offre' => Offre::Foyer])->save();
        Sanctum::actingAs($user, ['*']);

        // Le verrou d'offre laisse passer ; la suite (foyer absent, corps vide) est une autre affaire.
        $this->assertNotSame(402, $this->{$verbe.'Json'}($route)->status());
    }

    public function test_l_offre_complete_ouvre_tout_sauf_le_foyer(): void
    {
        // La fabrique donne l'offre la plus haute : ce test demande explicitement Complet.
        $user = User::factory()->create();
        $user->forceFill(['offre' => Offre::Complet])->save();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/stocks')->assertSuccessful();
        $this->getJson('/api/planner')->assertSuccessful();
        // Le foyer est la seule chose que l'offre Complet ne donne pas : on ne peut pas en créer.
        $this->postJson('/api/household', ['name' => 'Chez nous'])->assertStatus(402);
    }

    public function test_le_suivi_normal_reste_ouvert_au_compte_gratuit(): void
    {
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        // Ce qui fait l'offre gratuite : repas, aliments, profil, paramètres, historique, poids.
        $this->getJson('/api/meals?date='.now()->toDateString())->assertSuccessful();
        $this->getJson('/api/profile')->assertSuccessful();
        $this->getJson('/api/settings')->assertSuccessful();
        $this->getJson('/api/weights')->assertSuccessful();
        $this->getJson('/api/portions')->assertSuccessful();

        // Le coach du jour est volontairement ouvert : c'est un moteur de règles, il ne coûte
        // rien, et c'est lui qui donne envie des fonctions payantes vers lesquelles il renvoie.
        $this->getJson('/api/recommendations')->assertSuccessful();
    }

    public function test_une_offre_expiree_retombe_sur_le_gratuit_sans_rien_detruire(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['offre' => Offre::Complet, 'offre_expire_le' => now()->subDay()])->save();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/stocks')->assertStatus(402);
        $this->assertSame(Offre::Gratuit, $user->fresh()->offreEffective());

        // La colonne garde l'offre souscrite : rien n'est effacé, seul le droit s'éteint.
        $this->assertSame(Offre::Complet, $user->fresh()->offre);
    }

    public function test_l_offre_foyer_du_proprietaire_profite_aux_membres(): void
    {
        $proprietaire = User::factory()->create();
        $proprietaire->forceFill(['offre' => Offre::Foyer])->save();

        $foyer = \App\Models\Household::factory()->create(['owner_id' => $proprietaire->id]);
        $proprietaire->forceFill(['household_id' => $foyer->id])->save();

        // Un membre resté sur l'offre gratuite.
        $membre = User::factory()->gratuit()->create(['household_id' => $foyer->id]);

        $this->assertSame(Offre::Foyer, $membre->offreEffective(), 'L’offre Foyer doit couvrir le ménage.');
        $this->assertTrue($membre->peut('stock'));

        Sanctum::actingAs($membre, ['*']);
        $this->getJson('/api/stocks')->assertSuccessful();
    }

    public function test_seule_l_offre_foyer_du_proprietaire_se_partage(): void
    {
        // Un propriétaire en offre Complet (individuelle) ne la fait pas profiter à ses membres.
        $proprietaire = User::factory()->create();
        $proprietaire->forceFill(['offre' => Offre::Complet])->save();
        $foyer = \App\Models\Household::factory()->create(['owner_id' => $proprietaire->id]);

        $membre = User::factory()->gratuit()->create(['household_id' => $foyer->id]);
        $this->assertSame(Offre::Gratuit, $membre->offreEffective());
        $this->assertFalse($membre->peut('stock'));

        // Un membre qui paie lui-même garde son offre, quel que soit celle du propriétaire.
        $complet = User::factory()->create(['household_id' => $foyer->id]);
        $complet->forceFill(['offre' => Offre::Complet])->save();
        $this->assertSame(Offre::Complet, $complet->fresh()->offreEffective());

        // Offre Foyer expirée chez le propriétaire : plus rien ne se partage.
        $proprietaire->forceFill(['offre' => Offre::Foyer, 'offre_expire_le' => now()->subDay()])->save();
        $this->assertSame(Offre::Gratuit, $membre->fresh()->offreEffective());
    }

    public function test_un_invite_gratuit_rejoint_un_foyer_dont_le_proprietaire_a_l_offre_foyer(): void
    {
        $proprietaire = User::factory()->create(); // offre Foyer par défaut
        $foyer = app(\App\Services\Household\HouseholdService::class)->create($proprietaire, 'Les Martin')['household'];

        $invite = User::factory()->gratuit()->create();
        Sanctum::actingAs($invite, ['*']);

        $this->getJson('/api/household/preview?invite_code='.$foyer->invite_code)
            ->assertOk()
            ->assertJsonPath('data.name', 'Les Martin');

        $this->postJson('/api/household/join', ['invite_code' => $foyer->invite_code])->assertOk();

        $this->assertSame(Offre::Foyer, $invite->fresh()->offreEffective());
        // Une fois membre, le reste du module lui est ouvert.
        $this->getJson('/api/household')->assertOk()->assertJsonPath('data.id', $foyer->id);
    }

    public function test_un_invite_gratuit_ne_rejoint_pas_un_foyer_sans_offre_foyer(): void
    {
        $proprietaire = User::factory()->create();
        $foyer = app(\App\Services\Household\HouseholdService::class)->create($proprietaire, 'Les Martin')['household'];
        // L'offre du propriétaire retombe ensuite sur Complet.
        $proprietaire->forceFill(['offre' => Offre::Complet])->save();

        $invite = User::factory()->gratuit()->create();
        Sanctum::actingAs($invite, ['*']);

        $this->getJson('/api/household/preview?invite_code='.$foyer->invite_code)
            ->assertStatus(402)
            ->assertJsonPath('capacite_requise', 'foyer')
            ->assertJsonPath('offre_actuelle', 'gratuit');

        $this->postJson('/api/household/join', ['invite_code' => $foyer->invite_code])
            ->assertStatus(402)
            ->assertJsonPath('capacite_requise', 'foyer');

        $this->assertNull($invite->fresh()->household_id);
    }

    public function test_offre_foyer_expiree_le_membre_reprend_la_main_sur_ses_donnees(): void
    {
        $proprietaire = User::factory()->create(); // offre Foyer par défaut
        $foyer = app(\App\Services\Household\HouseholdService::class)->create($proprietaire, 'Les Martin')['household'];
        $membre = User::factory()->gratuit()->create();
        Sanctum::actingAs($membre, ['*']);
        $this->postJson('/api/household/join', ['invite_code' => $foyer->invite_code])->assertOk();
        $this->putJson('/api/household/members/me', ['share_profile' => true])->assertOk();

        $proprietaire->forceFill(['offre_expire_le' => now()->subDay()])->save();
        $this->assertFalse($membre->fresh()->peut('foyer'));

        // Ce qui fait vivre le foyer se ferme…
        $this->postJson('/api/household/common-meal/preview', [])->assertStatus(402);
        // … mais le membre voit toujours où il est, retire son partage et s'en va.
        $this->getJson('/api/household')->assertOk()->assertJsonPath('data.id', $foyer->id);
        $this->putJson('/api/household/members/me', ['share_profile' => false])->assertOk();
        $this->postJson('/api/household/leave')->assertOk();
        $this->assertNull($membre->fresh()->household_id);
    }

    public function test_creer_un_foyer_exige_toujours_l_offre_foyer(): void
    {
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $this->postJson('/api/household', ['name' => 'Chez moi'])
            ->assertStatus(402)
            ->assertJsonPath('capacite_requise', 'foyer');
    }

    public function test_l_offre_est_annoncee_au_client(): void
    {
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('offre.nom', 'gratuit')
            // Le gratuit porte désormais une capacité : le coach du jour.
            ->assertJsonPath('offre.capacites', ['coach']);
    }
}
