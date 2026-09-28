<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verrou d'offre : `->middleware('offre:stock')`.
 *
 * Renvoie 402 « Paiement requis », et non 403 ni 404. Le choix compte : 403 signifie déjà autre
 * chose dans ce projet (refus du foyer) et son message est écrasé par le gestionnaire, 404 est
 * réservé au camouflage de l'espace d'administration. Le 402 doit porter un message explicite,
 * car il ne figure pas dans la table des messages par défaut.
 *
 * La réponse dit toujours ce qui manque, pour que l'interface propose la bonne offre.
 */
class EnsureOffreAllows
{
    public function handle(Request $request, Closure $next, string $capacite): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->peut($capacite)) {
            return response()->json([
                'message' => 'Cette fonctionnalité fait partie d’une offre payante.',
                'capacite_requise' => $capacite,
                'offre_actuelle' => $user?->offreEffective()->value ?? 'gratuit',
            ], 402);
        }

        return $next($request);
    }
}
