<?php

namespace App\Services\Profile;

use App\Models\Profile;
use App\Models\User;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application des règles métier de PUT /profile et POST /profile/preview (brief §2.2, §2.4) :
 * consentement santé, cohérence de l'objectif, précédence des cibles (auto / manuel),
 * dates d'objectif, poids de référence et persistance des cibles calculées.
 */
class ProfileService
{
    public const MSG_CONSENTEMENT_SANTE = 'Ton accord est nécessaire pour calculer des objectifs à partir de tes données de santé.';

    public const CHAMPS_CIBLES = ['calories_cibles', 'proteines_cibles', 'glucides_cibles', 'lipides_cibles'];

    /** Champs dont le changement redémarre la période d'objectif. */
    public const CHAMPS_OBJECTIF = ['poids_souhaite_kg', 'delai_objectif_jours', 'objectif_type'];

    /** Champs « sometimes » repris du profil existant quand absents du corps. */
    private const CHAMPS_OPTIONNELS = [
        'objectif', 'allergenes', 'aliments_exclus', 'preferences',
        'sport_niveau', 'sport_objectif', 'sport_materiel', 'sport_temps_dispo_min', 'sport_jours_semaine',
        'sport_lieu', 'sport_zones_a_eviter', 'sport_focus', 'sport_notes', 'sport_coef_calories',
        'situation_particuliere', 'consentement_parental',
    ];

    public function __construct(private readonly NutritionCalculator $nutrition)
    {
    }

    /**
     * Applique une mise à jour validée (FormRequest) et retourne le profil persistant rechargé.
     *
     * @param  array<string, mixed>  $data  Données validées d'UpdateProfileRequest
     *
     * @throws ValidationException 422 (consentement, cohérence, enveloppe des cibles)
     */
    public function update(User $user, array $data): Profile
    {
        $today = Clock::today($user);
        $existing = $user->profile()->first();

        $plan = $this->plan($user, $existing, $data, $today, checkConsent: true);

        return DB::transaction(function () use ($user, $existing, $data, $plan, $today) {
            if ($plan['consent_now']) {
                $user->consentement_sante_at = now();
            }
            if (array_key_exists('nom', $data)) {
                $user->name = $data['nom'];
            }
            if ($user->isDirty()) {
                $user->save();
            }

            $attributes = $plan['attributes'];

            if ($existing) {
                $existing->fill($attributes)->save();
                $profile = $existing;
            } else {
                $profile = new Profile($attributes);
                $profile->user_id = $user->id;
                $profile->save();
            }

            return $profile->refresh();
        });
    }

    /**
     * Prévisualisation sans persistance ni consentement (POST /profile/preview).
     *
     * @param  array<string, mixed>  $data
     * @return array{besoins: array<string, mixed>, cibles_effectives: array<string, mixed>, imc: float|null, imc_cible: float|null}
     *
     * @throws ValidationException
     */
    public function preview(User $user, array $data): array
    {
        $today = Clock::today($user);
        $existing = $user->profile()->first();

        $plan = $this->plan($user, $existing, $data, $today, checkConsent: false);
        $besoins = $plan['besoins'];

        $auto = $plan['attributes']['objectif_calcul_auto'];

        return [
            'besoins' => $besoins,
            'cibles_effectives' => [
                'calories' => (int) $plan['attributes']['calories_cibles'],
                'proteines' => (int) $plan['attributes']['proteines_cibles'],
                'glucides' => (int) $plan['attributes']['glucides_cibles'],
                'lipides' => (int) $plan['attributes']['lipides_cibles'],
                'source' => $auto ? 'calcul' : 'utilisateur',
            ],
            'imc' => $besoins['imc'],
            'imc_cible' => $besoins['imc_cible'],
        ];
    }

    // ------------------------------------------------------------------------------------

    /**
     * Calcule tout ce qu'une mise à jour doit écrire, sans toucher à la base.
     *
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, besoins: array<string, mixed>, consent_now: bool}
     *
     * @throws ValidationException
     */
    private function plan(User $user, ?Profile $existing, array $data, string $today, bool $checkConsent): array
    {
        $errors = [];
        $consentNow = false;

        // 1. Consentement santé (première sauvegarde uniquement)
        if ($checkConsent && $user->consentement_sante_at === null) {
            $accord = filter_var($data['consentement_sante'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($accord) {
                $consentNow = true;
            } else {
                $errors['consentement_sante'] = self::MSG_CONSENTEMENT_SANTE;
            }
        }

        // 2. Attributs du profil (corps > profil existant > défauts)
        $attributes = $this->mergeAttributes($existing, $data);

        // 3. Dates d'objectif
        [$dateDebut, $dateFin] = $this->objectifDates($existing, $attributes, $today);
        $attributes['objectif_date_debut'] = $dateDebut;
        $attributes['objectif_date_fin'] = $dateFin;

        // 4. Cohérence (§2.3 r.3 et r.4)
        $input = $this->calculatorInput($attributes, $today);
        $errors += $this->nutrition->validateRequest($input);

        // 5. Précédence des cibles
        $bodyOverrides = $this->bodyOverrides($data);
        $storedAuto = $existing?->objectif_calcul_auto ?? true;

        if (array_key_exists('objectif_calcul_auto', $data) && $data['objectif_calcul_auto'] !== null) {
            $auto = filter_var($data['objectif_calcul_auto'], FILTER_VALIDATE_BOOLEAN);
        } elseif ($bodyOverrides !== []) {
            $auto = false;
        } else {
            $auto = (bool) $storedAuto;
        }

        if (! $auto && $bodyOverrides !== [] && $errors === []) {
            $errors += $this->nutrition->validateOverrides($input, $bodyOverrides);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $errors));
        }

        $besoins = $this->nutrition->compute($input);
        $attributes['objectif_calcul_auto'] = $auto;

        if ($auto) {
            $attributes['calories_cibles'] = (int) $besoins['calories_recommandees'];
            $attributes['proteines_cibles'] = (int) $besoins['proteines_g'];
            $attributes['glucides_cibles'] = (int) $besoins['glucides_g'];
            $attributes['lipides_cibles'] = (int) $besoins['lipides_g'];
            $attributes['poids_reference'] = (float) $attributes['poids'];
            $attributes['cibles_calculees_le'] = $today;
        } else {
            $computed = [
                'calories_cibles' => (int) $besoins['calories_recommandees'],
                'proteines_cibles' => (int) $besoins['proteines_g'],
                'glucides_cibles' => (int) $besoins['glucides_g'],
                'lipides_cibles' => (int) $besoins['lipides_g'],
            ];
            foreach (self::CHAMPS_CIBLES as $champ) {
                $attributes[$champ] = (int) ($bodyOverrides[$champ] ?? $existing?->{$champ} ?? $computed[$champ]);
            }
            $attributes['poids_reference'] = $existing?->poids_reference;
            $attributes['cibles_calculees_le'] = $existing?->cibles_calculees_le?->format('Y-m-d');
        }

        return ['attributes' => $attributes, 'besoins' => $besoins, 'consent_now' => $consentNow];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeAttributes(?Profile $existing, array $data): array
    {
        $attributes = [
            'poids' => $data['poids'] ?? $existing?->poids,
            'poids_souhaite_kg' => array_key_exists('poids_souhaite_kg', $data) ? $data['poids_souhaite_kg'] : $existing?->poids_souhaite_kg,
            'delai_objectif_jours' => array_key_exists('delai_objectif_jours', $data) ? $data['delai_objectif_jours'] : $existing?->delai_objectif_jours,
            'taille' => $data['taille'] ?? $existing?->taille,
            'age' => $data['age'] ?? $existing?->age,
            'sexe' => $data['sexe'] ?? $existing?->sexe,
            'objectif_type' => $data['objectif_type'] ?? $existing?->objectif_type ?? 'maintenir',
            'niveau_activite' => $data['niveau_activite'] ?? $existing?->niveau_activite ?? 'sedentaire',
            'regime_alimentaire' => $data['regime_alimentaire'] ?? $existing?->regime_alimentaire ?? 'omnivore',
        ];

        foreach (self::CHAMPS_OPTIONNELS as $champ) {
            $attributes[$champ] = array_key_exists($champ, $data) ? $data[$champ] : $existing?->{$champ};
        }

        // Maintien : poids souhaité et délai ignorés (§2.3 r.3).
        if ($attributes['objectif_type'] === 'maintenir') {
            $attributes['poids_souhaite_kg'] = null;
            $attributes['delai_objectif_jours'] = null;
        }

        $attributes['objectif'] = $attributes['objectif'] ?? $attributes['objectif_type'];
        $attributes['situation_particuliere'] = $attributes['situation_particuliere'] ?? 'aucune';
        $attributes['consentement_parental'] = (bool) ($attributes['consentement_parental'] ?? false);
        $attributes['sport_coef_calories'] = (int) ($attributes['sport_coef_calories'] ?? 100);

        return $attributes;
    }

    /**
     * Redémarre la période d'objectif quand poids souhaité, délai ou type changent.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: string|null, 1: string|null}
     */
    private function objectifDates(?Profile $existing, array $attributes, string $today): array
    {
        $changed = $existing === null;
        if ($existing) {
            foreach (self::CHAMPS_OBJECTIF as $champ) {
                if ($this->scalarDiffers($existing->{$champ}, $attributes[$champ])) {
                    $changed = true;
                    break;
                }
            }
        }

        if (! $changed) {
            return [
                $existing?->objectif_date_debut?->format('Y-m-d'),
                $existing?->objectif_date_fin?->format('Y-m-d'),
            ];
        }

        $delai = $attributes['delai_objectif_jours'];
        $fin = $delai !== null && $delai !== ''
            ? CarbonImmutable::parse($today)->addDays((int) $delai)->toDateString()
            : null;

        return [$today, $fin];
    }

    private function scalarDiffers(mixed $a, mixed $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) > 0.0001;
        }

        return (string) $a !== (string) $b;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function calculatorInput(array $attributes, string $today): array
    {
        return [
            'sexe' => $attributes['sexe'],
            'age' => $attributes['age'],
            'taille' => $attributes['taille'],
            'poids' => $attributes['poids'],
            'poids_souhaite_kg' => $attributes['poids_souhaite_kg'],
            'delai_objectif_jours' => $attributes['delai_objectif_jours'],
            'objectif_date_fin' => $attributes['objectif_date_fin'],
            'niveau_activite' => $attributes['niveau_activite'],
            'objectif_type' => $attributes['objectif_type'],
            'regime_alimentaire' => $attributes['regime_alimentaire'],
            'sport_objectif' => $attributes['sport_objectif'],
            'situation_particuliere' => $attributes['situation_particuliere'],
            'sport_jours_semaine' => $attributes['sport_jours_semaine'],
            'consentement_parental' => $attributes['consentement_parental'],
            'today' => $today,
        ];
    }

    /**
     * Cibles explicitement fournies dans le corps (non nulles).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|float>
     */
    private function bodyOverrides(array $data): array
    {
        $overrides = [];
        foreach (self::CHAMPS_CIBLES as $champ) {
            if (array_key_exists($champ, $data) && $data[$champ] !== null && $data[$champ] !== '') {
                $overrides[$champ] = $data[$champ];
            }
        }

        return $overrides;
    }
}
