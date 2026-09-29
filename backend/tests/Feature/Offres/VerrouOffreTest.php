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
        // Le catalogue des magasins est public (routes/api_public) : seules les promotions, qui
        // pèsent sur la liste et sur les propositions de repas, sont authentifiées.
        '/magasins' => 'courses',
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
            $middlewares = $route->gatherMiddleware();

            // Les routes publiques sont hors sujet : le catalogue des régimes, comme celui des
            // portions, est une donnée de référence consultable sans compte. Seules les routes
            // authentifiées peuvent relever d'une offre.
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
            'foyer' => ['get', '/api/household'],
            'stock' => ['get', '/api/stocks'],
            'alertes de stock' => ['get', '/api/stocks/alerts'],
            'courses' => ['get', '/api/shopping-list'],
            'planificateur' => ['get', '/api/planner'],
            // Le catalogue /api/diets est public ; c'est l'évaluation personnelle qui est payante.
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

        $this->{$verbe.'Json'}($route)->assertSuccessful();
    }

    public function test_l_offre_complete_ouvre_tout_sauf_le_foyer(): void
    {
        // La fabrique donne l'offre la plus haute : ce test demande explicitement Complet.
        $user = User::factory()->create();
        $user->forceFill(['offre' => Offre::Complet])->save();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/stocks')->assertSuccessful();
        $this->getJson('/api/planner')->assertSuccessful();
        // Le foyer est la seule chose que l'offre Complet ne donne pas.
        $this->getJson('/api/household')->assertStatus(402);
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
