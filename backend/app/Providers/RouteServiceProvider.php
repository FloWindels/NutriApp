<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * L'API est exposée sous `/api` ET `/api/v1` (même fichier de routes, chargé deux fois).
     * Ne jamais utiliser `route('nom')` pour une route API : les noms `v1.*` ne sont là que
     * pour éviter les collisions de noms entre les deux montages.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Reconnaissance de photo d'assiette : appel long et coûteux, plafond dédié.
        RateLimiter::for('vision', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        // Codes d'accès : un plafond serré rend le tirage au hasard sans espoir. Deux compteurs :
        // par compte, et par adresse IP pour qu'une rafale de comptes jetables n'y échappe pas.
        RateLimiter::for('code', function (Request $request) {
            return [
                Limit::perMinute(5)->by('code-u|'.($request->user()?->id ?: $request->ip())),
                Limit::perHour(30)->by('code-ip|'.$request->ip()),
            ];
        });

        // Connexion : 5 essais par minute pour un couple (e-mail, IP), et 20 par heure pour un
        // même e-mail quelle que soit l'IP — deviner un mot de passe devient sans espoir.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login|'.$email.'|'.$request->ip()),
                Limit::perHour(20)->by('login-email|'.$email),
            ];
        });

        // Changement de mot de passe et suppression du compte : chacun exige le mot de passe
        // actuel, un jeton volé ne doit pas pouvoir le deviner à loisir.
        RateLimiter::for('compte-sensible', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api/v1')
                ->name('v1.')
                ->group(base_path('routes/api.php'));

            // Monté une seule fois, hors de routes/api.php : ce dernier est chargé deux fois
            // (sous /api et /api/v1) et place tout son contenu derrière auth:sanctum, ce qui
            // ferait répondre 401 — donc révélerait le préfixe — à un visiteur anonyme.
            Route::middleware('api')
                ->prefix('api/admin')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
