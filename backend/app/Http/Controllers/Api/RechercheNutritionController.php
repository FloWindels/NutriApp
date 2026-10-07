<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoodResource;
use App\Services\Foods\RechercheNutritionEnLigne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Un aliment introuvable : chercher ses valeurs nutritionnelles sur Internet. Rien n'est
 * enregistré ici ; la proposition pré-remplit le formulaire de création, à vérifier.
 */
class RechercheNutritionController extends Controller
{
    public function __invoke(Request $request, RechercheNutritionEnLigne $recherche): JsonResponse
    {
        $valide = $request->validate([
            'nom' => ['required', 'string', 'min:2', 'max:120'],
            'marque' => ['nullable', 'string', 'max:120'],
        ], [], ['nom' => 'nom de l’aliment', 'marque' => 'marque']);

        $resultat = $recherche->chercher($valide['nom'], $valide['marque'] ?? null);

        return response()->json(['data' => [
            'source' => $resultat['source'],
            'food' => $resultat['food'] !== null ? FoodResource::forViewer($resultat['food'], $request->user()) : null,
            'proposition' => $resultat['proposition'],
            'sources' => $resultat['sources'],
            'avertissements' => $resultat['avertissements'],
            'message' => $resultat['message'],
        ]]);
    }
}
