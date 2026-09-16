<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $profile = $user->profile;

        return response()->json([
            'nom' => $user->name,
            'poids' => $profile?->poids,
            'poids_souhaite_kg' => $profile?->poids_souhaite_kg,
            'delai_objectif_jours' => $profile?->delai_objectif_jours,
            'taille' => $profile?->taille,
            'age' => $profile?->age,
            'sexe' => $profile?->sexe,
            'objectif' => $profile?->objectif,
            'objectif_type' => $profile?->objectif_type,
            'niveau_activite' => $profile?->niveau_activite,
            'calories_cibles' => $profile?->calories_cibles,
            'proteines_cibles' => $profile?->proteines_cibles,
            'glucides_cibles' => $profile?->glucides_cibles,
            'lipides_cibles' => $profile?->lipides_cibles,
            'regime_alimentaire' => $profile?->regime_alimentaire,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'sexe' => ['required', Rule::in(['homme', 'femme'])],
            'age' => ['required', 'integer', 'min:12', 'max:120'],
            'taille' => ['required', 'numeric', 'min:100', 'max:300'],
            'poids' => ['required', 'numeric', 'min:20', 'max:500'],
            'poids_souhaite_kg' => ['required', 'numeric', 'min:20', 'max:500'],
            'delai_objectif_jours' => ['required', 'integer', 'min:1', 'max:2000'],
            'niveau_activite' => ['required', Rule::in(['sedentaire', 'leger', 'modere', 'eleve', 'tres_eleve'])],
            'objectif_type' => ['required', Rule::in(['perdre', 'maintenir', 'prendre'])],
            'objectif' => ['nullable', 'string', 'max:100'],
            'calories_cibles' => ['nullable', 'integer', 'min:500', 'max:10000'],
            'proteines_cibles' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'glucides_cibles' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'lipides_cibles' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'regime_alimentaire' => ['required', Rule::in([
                'omnivore',
                'vegetarien',
                'vegan',
                'keto',
                'low_carb',
                'mediterraneen',
                'halal',
                'sans_gluten',
                'autre',
            ])],
        ]);

        $user = $request->user();
        $user->name = $validated['nom'];
        $user->save();

        Profile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'poids' => $validated['poids'],
                'poids_souhaite_kg' => $validated['poids_souhaite_kg'],
                'delai_objectif_jours' => $validated['delai_objectif_jours'],
                'taille' => $validated['taille'],
                'age' => $validated['age'],
                'sexe' => $validated['sexe'],
                'objectif' => $validated['objectif'] ?? $validated['objectif_type'],
                'objectif_type' => $validated['objectif_type'],
                'niveau_activite' => $validated['niveau_activite'],
                'calories_cibles' => $validated['calories_cibles'] ?? null,
                'proteines_cibles' => $validated['proteines_cibles'] ?? null,
                'glucides_cibles' => $validated['glucides_cibles'] ?? null,
                'lipides_cibles' => $validated['lipides_cibles'] ?? null,
                'regime_alimentaire' => $validated['regime_alimentaire'],
            ]
        );

        return response()->json([
            'message' => 'Profil mis a jour.',
        ]);
    }
}
