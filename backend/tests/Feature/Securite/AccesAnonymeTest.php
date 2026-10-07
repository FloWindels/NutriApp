<?php

namespace Tests\Feature\Securite;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exigence du propriétaire : sans compte, on n'atteint RIEN d'autre que ce dont l'inscription,
 * la connexion et la réinitialisation du mot de passe ont besoin.
 */
class AccesAnonymeTest extends TestCase
{
    use RefreshDatabase;

    /** Les seules routes API qu'un visiteur anonyme a le droit d'atteindre. */
    private const ROUTES_ANONYMES = [
        'POST /register',
        'POST /login',
        'POST /forgot-password',
        'POST /reset-password',
    ];

    /**
     * Parcourt les routes RÉELLES : une route publique ajoutée demain fait échouer la suite.
     * (/api/admin est monté à part et répond 404 à tout non-administrateur : AdminAccessTest.)
     */
    public function test_toute_route_api_hors_authentification_exige_un_jeton(): void
    {
        $exposees = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            // Ignition (pages d'erreur) n'existe qu'en développement, APP_DEBUG=true.
            if (str_starts_with($uri, '/api/admin') || str_starts_with($uri, '/_ignition/')) {
                continue;
            }

            if (! str_starts_with($uri, '/api/')) {
                $exposees[] = implode('|', $route->methods()).' '.$uri;

                continue;
            }

            $chemin = preg_replace('#^/api(/v1)?#', '', $uri);

            if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach ($route->methods() as $methode) {
                if (in_array($methode, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                if (! in_array($methode.' '.$chemin, self::ROUTES_ANONYMES, true)) {
                    $exposees[] = $methode.' '.$uri;
                }
            }
        }

        $this->assertSame([], $exposees, "Routes atteignables sans compte :\n".implode("\n", $exposees));
    }

    /** @return array<string, array{0: string}> */
    public static function anciensCataloguesPublics(): array
    {
        $routes = [
            'recherche d’aliments' => '/foods/search?q=pomme',
            'code-barres' => '/foods/barcode/3017620422003',
            'portions' => '/portions',
            'régimes' => '/diets',
            'un régime' => '/diets/mediterraneen',
            'magasins' => '/magasins',
            'assortiment' => '/magasins/1/produits',
            'exercices' => '/sport/exercises',
        ];

        $cas = [];
        foreach (['/api', '/api/v1'] as $prefixe) {
            foreach ($routes as $nom => $route) {
                $cas[$nom.' ('.$prefixe.')'] = [$prefixe.$route];
            }
        }

        return $cas;
    }

    /**
     * @dataProvider anciensCataloguesPublics
     */
    public function test_les_anciens_catalogues_publics_repondent_401_sans_jeton(string $route): void
    {
        $this->getJson($route)->assertStatus(401)->assertExactJson(['message' => 'Non authentifié.']);
    }

    public function test_les_catalogues_restent_ouverts_a_un_compte_gratuit(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/diets')->assertOk();
        $this->getJson('/api/magasins')->assertOk();
        $this->getJson('/api/sport/exercises')->assertOk();
        $this->getJson('/api/portions')->assertOk();
    }

    public function test_l_inscription_et_la_connexion_restent_anonymes(): void
    {
        // Simple joignabilité : 422 (validation) et non 401.
        $this->postJson('/api/register', [])->assertStatus(422);
        $this->postJson('/api/login', [])->assertStatus(422);
        $this->postJson('/api/forgot-password', [])->assertStatus(422);
        $this->postJson('/api/reset-password', [])->assertStatus(422);
    }
}
