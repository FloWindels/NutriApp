<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Jeu de données partagé : vecteur obligatoire du brief §2.3 (homme 70 kg / 175 cm / 30 ans /
 * modéré / perdre 5 kg en 90 jours → 2 130 kcal, P 126 / L 56 / G 280).
 */
trait ProfileTestHelpers
{
    protected function actingAsUser(?User $user = null): User
    {
        $user = $user ?? User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function vecteurHomme70(array $overrides = []): array
    {
        return array_replace([
            'nom' => 'Jean Dupont',
            'sexe' => 'homme',
            'age' => 30,
            'taille' => 175,
            'poids' => 70,
            'poids_souhaite_kg' => 65,
            'delai_objectif_jours' => 90,
            'niveau_activite' => 'modere',
            'objectif_type' => 'perdre',
            'regime_alimentaire' => 'omnivore',
            'consentement_sante' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function maintien(array $overrides = []): array
    {
        return array_replace([
            'nom' => 'Marie Martin',
            'sexe' => 'femme',
            'age' => 28,
            'taille' => 165,
            'poids' => 60,
            'poids_souhaite_kg' => null,
            'delai_objectif_jours' => null,
            'niveau_activite' => 'leger',
            'objectif_type' => 'maintenir',
            'regime_alimentaire' => 'omnivore',
            'consentement_sante' => true,
        ], $overrides);
    }
}
