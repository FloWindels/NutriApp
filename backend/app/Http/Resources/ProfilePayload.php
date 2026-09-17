<?php

namespace App\Http\Resources;

use App\Models\Profile;
use App\Models\User;
use App\Services\NutritionCalculator;
use App\Support\Clock;

/**
 * Charge utile de GET /profile et PUT /profile (brief §0.3 + §2.2) : les 15 clés historiques
 * restent au premier niveau (jamais enveloppées dans `data`), les nouveaux champs sont des
 * clés sœurs additives. Le contrôleur renvoie `toArray()` tel quel.
 */
class ProfilePayload
{
    /** Clés historiques figées (§0.3) — ne jamais renommer ni retirer. */
    public const LEGACY_KEYS = [
        'nom', 'poids', 'poids_souhaite_kg', 'delai_objectif_jours', 'taille', 'age', 'sexe',
        'objectif', 'objectif_type', 'niveau_activite',
        'calories_cibles', 'proteines_cibles', 'glucides_cibles', 'lipides_cibles', 'regime_alimentaire',
    ];

    /** Clés sœurs ajoutées par le brief §2 et l'addendum §A.4. */
    public const NEW_KEYS = [
        'allergenes', 'aliments_exclus', 'preferences',
        'sport_niveau', 'sport_objectif', 'sport_materiel', 'sport_temps_dispo_min', 'sport_jours_semaine',
        'sport_lieu', 'sport_zones_a_eviter', 'sport_focus', 'sport_notes', 'sport_coef_calories',
        'objectif_calcul_auto', 'objectif_date_debut', 'objectif_date_fin', 'poids_reference', 'cibles_calculees_le',
        'situation_particuliere', 'consentement_parental', 'consentement_sante',
        'besoins', 'cibles_effectives', 'has_profile', 'imc', 'imc_cible',
    ];

    public function __construct(
        private readonly User $user,
        private readonly ?Profile $profile,
        private readonly NutritionCalculator $nutrition,
    ) {
    }

    public static function for(User $user, ?Profile $profile, NutritionCalculator $nutrition): self
    {
        return new self($user, $profile, $nutrition);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $p = $this->profile;
        $today = Clock::today($this->user);

        $hasProfile = $p !== null && $this->nutrition->isComplete($p);
        $besoins = $hasProfile ? $this->nutrition->compute($this->nutrition->inputFromProfile($p, $today)) : null;

        $cibles = $p
            ? $this->nutrition->ciblesEffectives($p, $today)
            : ['calories' => null, 'proteines' => null, 'glucides' => null, 'lipides' => null, 'source' => 'calcul'];

        [$imc, $imcCible] = $this->imc($p, $besoins);

        return [
            // --- Contrat historique (§0.3) ---
            'nom' => $this->user->name,
            'poids' => $p?->poids,
            'poids_souhaite_kg' => $p?->poids_souhaite_kg,
            'delai_objectif_jours' => $p?->delai_objectif_jours,
            'taille' => $p?->taille,
            'age' => $p?->age,
            'sexe' => $p?->sexe,
            'objectif' => $p?->objectif,
            'objectif_type' => $p?->objectif_type,
            'niveau_activite' => $p?->niveau_activite,
            'calories_cibles' => $p?->calories_cibles,
            'proteines_cibles' => $p?->proteines_cibles,
            'glucides_cibles' => $p?->glucides_cibles,
            'lipides_cibles' => $p?->lipides_cibles,
            'regime_alimentaire' => $p?->regime_alimentaire,

            // --- Alimentation (§2.1) ---
            'allergenes' => $p?->allergenes ?? [],
            'aliments_exclus' => $p?->aliments_exclus ?? [],
            'preferences' => $this->preferences($p),

            // --- Sport (§2.1 + addendum §A.4) ---
            'sport_niveau' => $p?->sport_niveau,
            'sport_objectif' => $p?->sport_objectif,
            'sport_materiel' => $p?->sport_materiel ?? [],
            'sport_temps_dispo_min' => $p?->sport_temps_dispo_min,
            'sport_jours_semaine' => $p?->sport_jours_semaine,
            'sport_lieu' => $p?->sport_lieu,
            'sport_zones_a_eviter' => $p?->sport_zones_a_eviter ?? [],
            'sport_focus' => $p?->sport_focus ?? [],
            'sport_notes' => $p?->sport_notes,
            'sport_coef_calories' => $p?->sport_coef_calories ?? 100,

            // --- Objectif & cibles ---
            'objectif_calcul_auto' => $p ? (bool) $p->objectif_calcul_auto : true,
            'objectif_date_debut' => $p?->objectif_date_debut?->format('Y-m-d'),
            'objectif_date_fin' => $p?->objectif_date_fin?->format('Y-m-d'),
            'poids_reference' => $p?->poids_reference,
            'cibles_calculees_le' => $p?->cibles_calculees_le?->format('Y-m-d'),

            // --- Sécurité ---
            'situation_particuliere' => $p?->situation_particuliere ?? 'aucune',
            'consentement_parental' => $p ? (bool) $p->consentement_parental : false,
            'consentement_sante' => $this->user->consentement_sante_at !== null,

            // --- Calculs (§2.3) ---
            'besoins' => $besoins,
            'cibles_effectives' => $cibles,
            'has_profile' => $hasProfile,
            'imc' => $imc,
            'imc_cible' => $imcCible,
        ];
    }

    /**
     * @return array{aime: array<int, string>, evite: array<int, string>}
     */
    private function preferences(?Profile $p): array
    {
        $prefs = is_array($p?->preferences) ? $p->preferences : [];

        return [
            'aime' => array_values((array) ($prefs['aime'] ?? [])),
            'evite' => array_values((array) ($prefs['evite'] ?? [])),
        ];
    }

    /**
     * IMC (1 décimale) depuis les besoins calculés, sinon directement depuis poids/taille.
     *
     * @param  array<string, mixed>|null  $besoins
     * @return array{0: float|null, 1: float|null}
     */
    private function imc(?Profile $p, ?array $besoins): array
    {
        if ($besoins !== null) {
            return [
                $besoins['imc'] === null ? null : (float) $besoins['imc'],
                $besoins['imc_cible'] === null ? null : (float) $besoins['imc_cible'],
            ];
        }

        if ($p === null || ! $p->taille || ! $p->poids) {
            return [null, null];
        }

        $hm2 = ((float) $p->taille / 100) ** 2;
        $imc = round((float) $p->poids / $hm2, 1);
        $imcCible = $p->poids_souhaite_kg ? round((float) $p->poids_souhaite_kg / $hm2, 1) : null;

        return [$imc, $imcCible];
    }
}
