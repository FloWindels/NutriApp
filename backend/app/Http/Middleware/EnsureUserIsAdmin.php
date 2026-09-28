<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porte de l'espace d'administration.
 *
 * Renvoie 404 et non 403 : un 403 confirmerait l'existence du couloir à qui tente l'URL. Le
 * gestionnaire d'exceptions transforme déjà toute NotFoundHttpException en « Introuvable. », si
 * bien qu'une route d'administration est indistinguable d'une URL inexistante.
 *
 * Le middleware résout lui-même l'utilisateur avec le garde `sanctum`, plutôt que de s'empiler
 * derrière `auth:sanctum` : sans cela un visiteur anonyme recevrait 401 ici et 404 ailleurs, et
 * cette différence suffirait à trahir le préfixe.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user === null || ! $user->isAdmin() || $user->estSuspendu()) {
            abort(404);
        }

        // Les contrôleurs d'administration utilisent $request->user() sans préciser le garde.
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
