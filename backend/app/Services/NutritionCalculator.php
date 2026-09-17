<?php

namespace App\Services;

use App\Models\Profile;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Calcul des besoins nutritionnels (brief §2.3 — 12 règles) et validation des cibles
 * manuelles (§2.4). Service sans état, entrées/sorties en tableaux : testable sans base.
 *
 * Entrée `compute()` : sexe, age, taille (cm), poids (kg), poids_souhaite_kg, delai_objectif_jours,
 * objectif_date_fin (Y-m-d|null), niveau_activite, objectif_type, regime_alimentaire (alias `regime`),
 * sport_objectif, situation_particuliere, sport_jours_semaine, today (Y-m-d, défaut date UTC).
 */
class NutritionCalculator
{
    public const FACTEURS_ACTIVITE = [
        'sedentaire' => 1.2,
        'leger' => 1.375,
        'modere' => 1.55,
        'eleve' => 1.725,
        'tres_eleve' => 1.9,
    ];

    public const PROTEINES_G_KG = [
        'maintenir' => 1.6,
        'perdre' => 1.8,
        'prendre' => 2.0,
    ];

    public const REGIMES_INTERDITS_MINEURS = ['keto', 'low_carb', 'jeune_intermittent', 'montignac'];

    public const KCAL_PAR_KG = 7700;
    public const JOURS_MIN = 28;
    public const AJUSTEMENT_MIN = 250;
    public const PRISE_MAX = 500;
    public const DEFICIT_MAX_ABSOLU = 1000;
    public const DEFICIT_MAX_SENIOR = 500;
    public const IMC_MIN = 18.5;
    public const IMC_MAX_PRISE = 30.0;
    public const GLUCIDES_MIN = 50;
    public const GLUCIDES_MIN_KETO = 25;

    public const MSG_PERTE_INCOHERENTE = 'Le poids souhaité doit être inférieur au poids actuel pour un objectif de perte.';
    public const MSG_PRISE_INCOHERENTE = 'Le poids souhaité doit être supérieur au poids actuel pour un objectif de prise.';
    public const MSG_MINEUR_OBJECTIF = 'Pour les moins de 18 ans, Mavi’oh ne propose pas d’objectif de perte ou de prise de poids. Parle-en à un professionnel de santé.';
    public const MSG_SITUATION_OBJECTIF = 'Dans ta situation, Mavi’oh ne propose pas d’objectif de perte ou de prise de poids : suis les recommandations de ton professionnel de santé.';
    public const MSG_MINEUR_REGIME = 'Ce régime n’est pas proposé aux moins de 18 ans.';
    public const MSG_CONSENTEMENT_PARENTAL = 'L’accord d’un parent est requis pour les moins de 15 ans.';
    public const MSG_IMC_TROP_BAS = 'Ce poids correspond à un IMC inférieur à 18,5. Mavi’oh ne propose pas d’objectif en dessous de ce seuil ; rapproche-toi d’un professionnel de santé.';
    public const MSG_POIDS_SOUHAITE_REQUIS = 'Le poids souhaité est requis pour cet objectif.';
    public const MSG_DELAI_REQUIS = 'Le délai est requis pour cet objectif.';
    public const MSG_SOMME_MACROS = 'La somme des macros (:n kcal) ne correspond pas aux calories cibles.';

    // ------------------------------------------------------------------------------------
    // Calcul principal
    // ------------------------------------------------------------------------------------

    /**
     * Calcule le bloc `besoins` (§2.3).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compute(array $input): array
    {
        $sexe = (string) ($input['sexe'] ?? 'homme');
        $femme = $sexe === 'femme';
        $age = (int) ($input['age'] ?? 30);
        $taille = (float) ($input['taille'] ?? 170);
        $poids = (float) ($input['poids'] ?? 70);
        $poidsSouhaite = $this->floatOrNull($input['poids_souhaite_kg'] ?? null);
        $delai = $this->intOrNull($input['delai_objectif_jours'] ?? null);
        $dateFin = $this->dateOrNull($input['objectif_date_fin'] ?? null);
        $niveau = (string) ($input['niveau_activite'] ?? 'sedentaire');
        $objectif = (string) ($input['objectif_type'] ?? 'maintenir');
        $regime = (string) ($input['regime_alimentaire'] ?? $input['regime'] ?? 'omnivore');
        $sportObjectif = $input['sport_objectif'] ?? null;
        $situation = (string) ($input['situation_particuliere'] ?? 'aucune');
        $sportJours = (int) ($input['sport_jours_semaine'] ?? 0);
        $today = $this->dateOrNull($input['today'] ?? null) ?? CarbonImmutable::now('UTC');

        $mineur = $age < 18;
        $situationParticuliere = $situation !== 'aucune';
        $objectifEffectif = ($mineur || $situationParticuliere) ? 'maintenir' : $objectif;
        if (! in_array($objectifEffectif, ['perdre', 'maintenir', 'prendre'], true)) {
            $objectifEffectif = 'maintenir';
        }
        $senior = $age >= 65;

        $avertissements = [];
        $etapes = [];

        // 1. Métabolisme de base
        if ($mineur) {
            $bmr = $femme ? 13.4 * $poids + 692 : 17.7 * $poids + 657;
            $methode = 'Schofield';
        } else {
            $bmr = 10 * $poids + 6.25 * $taille - 5 * $age + ($femme ? -161 : 5);
            $methode = 'Mifflin-St Jeor';
        }
        $etapes[] = sprintf('Métabolisme de base (%s) : %s kcal.', $methode, $this->fr($bmr));

        // 2. Dépense totale
        $facteur = self::FACTEURS_ACTIVITE[$niveau] ?? self::FACTEURS_ACTIVITE['sedentaire'];
        $tdee = $bmr * $facteur;
        $etapes[] = sprintf('Dépense quotidienne (× %s, activité %s) : %s kcal.', $this->fr($facteur, 3), $this->niveauLabel($niveau), $this->fr($tdee));

        // 4. IMC
        $hm2 = ($taille / 100) ** 2;
        $imc = $hm2 > 0 ? round($poids / $hm2, 1) : null;
        $imcCible = ($poidsSouhaite !== null && $hm2 > 0) ? round($poidsSouhaite / $hm2, 1) : null;

        if ($objectifEffectif === 'prendre' && $imcCible !== null && $imcCible > self::IMC_MAX_PRISE) {
            $avertissements[] = 'Le poids visé correspond à un IMC supérieur à 30.';
        }

        // 5. Jours restants
        $joursRestants = null;
        if ($objectifEffectif !== 'maintenir') {
            if ($dateFin !== null) {
                $diff = (int) $today->startOfDay()->diffInDays($dateFin->startOfDay(), false);
                if ($diff < 0) {
                    $avertissements[] = 'Ton délai est dépassé : objectif recalculé sur 4 semaines. Mets à jour ton objectif.';
                }
                $joursRestants = max(self::JOURS_MIN, $diff);
            } else {
                $joursRestants = max(self::JOURS_MIN, $delai ?? 84);
            }
        }

        // 6. Ajustement calorique
        $ajustement = 0.0;
        if ($objectifEffectif !== 'maintenir' && $poidsSouhaite !== null && $joursRestants !== null) {
            $demande = abs($poids - $poidsSouhaite) * self::KCAL_PAR_KG / $joursRestants;

            if ($objectifEffectif === 'perdre') {
                $deficitMax = min(self::DEFICIT_MAX_ABSOLU, round(0.01 * $poids * self::KCAL_PAR_KG / 7));
                if ($senior) {
                    $deficitMax = min($deficitMax, self::DEFICIT_MAX_SENIOR);
                }
                $applique = $this->clamp($demande, self::AJUSTEMENT_MIN, $deficitMax);
                $ajustement = -$applique;
                $signe = '−';
            } else {
                $deficitMax = self::PRISE_MAX;
                $applique = $this->clamp($demande, self::AJUSTEMENT_MIN, $deficitMax);
                $ajustement = $applique;
                $signe = '+';
            }

            if ($demande > $deficitMax + 0.001) {
                $avertissements[] = sprintf(
                    'Délai trop court : objectif ramené à %s%s kcal/j, soit ~%s kg/semaine.',
                    $signe,
                    $this->fr(round($applique)),
                    $this->fr(round($applique * 7 / self::KCAL_PAR_KG, 2), 2)
                );
            } elseif ($demande < self::AJUSTEMENT_MIN - 0.001) {
                $avertissements[] = sprintf(
                    'Délai très long : ajustement porté au minimum de %s kcal/j.',
                    $this->fr(self::AJUSTEMENT_MIN)
                );
            }

            $etapes[] = sprintf(
                'Ajustement : %s%s kcal/j pour %s kg en %d jours.',
                $signe,
                $this->fr(round($applique)),
                $this->fr(abs($poids - $poidsSouhaite), 1),
                $joursRestants
            );
        } else {
            $etapes[] = 'Aucun ajustement calorique (maintien).';
        }

        // 7. Cible et plancher de sécurité
        $cible = $tdee + $ajustement;
        $plancher = 0.0;
        if (! $mineur) {
            $plancher = max($femme ? 1200.0 : 1500.0, $objectifEffectif === 'perdre' ? $bmr : 0.0);
            if ($cible < $plancher) {
                $cible = $plancher;
                $avertissements[] = sprintf(
                    'Les calories cibles ont été remontées au seuil minimal de sécurité (%s kcal).',
                    $this->fr(round($plancher))
                );
            }
        }
        $cibleAvantArrondi = $cible;

        // 8. Variation hebdomadaire et arrondi
        $ajustementEffectifBrut = $cibleAvantArrondi - $tdee;
        if ($objectifEffectif !== 'maintenir' && $poidsSouhaite !== null && abs($ajustementEffectifBrut) < self::AJUSTEMENT_MIN - 0.001) {
            if (abs($ajustementEffectifBrut) < 1) {
                $avertissements[] = 'Le seuil de sécurité ne laisse aucune marge d’ajustement : cet objectif n’est pas atteignable sans avis médical.';
            } else {
                $joursReels = (int) round(abs($poids - $poidsSouhaite) * self::KCAL_PAR_KG / abs($ajustementEffectifBrut));
                $avertissements[] = sprintf(
                    'Le déficit possible est limité à %s kcal/j par le seuil de sécurité : le délai réel sera d’environ %d jours.',
                    $this->fr(round(abs($ajustementEffectifBrut))),
                    $joursReels
                );
            }
        }

        $cible = $this->round10($cible);
        if (! $mineur && $cible < $plancher) {
            $cible = (int) (ceil($plancher / 10) * 10);
        }

        // 9. Macros
        $poidsRef = min($poids, 30 * $hm2);
        $ketoOuLowCarb = in_array($regime, ['keto', 'low_carb'], true);

        if ($mineur) {
            $p = 1.0 * $poidsRef;
            $l = 0.30 * $cible / 9;
            $g = ($cible - 4 * $p - 9 * $l) / 4;
        } else {
            $gkg = max(self::PROTEINES_G_KG[$objectifEffectif], $sportObjectif === 'prise_de_muscle' ? 2.0 : 0.0);
            $p = $gkg * $poidsRef;
            $p = min($p, 0.35 * $cible / 4);
            if ($senior) {
                $p = max($p, 1.2 * $poidsRef);
            }

            $l = max(0.8 * $poidsRef, 0.20 * $cible / 9);
            $l = min($l, 0.40 * $cible / 9);

            $g = ($cible - 4 * $p - 9 * $l) / 4;

            $gMin = $ketoOuLowCarb ? 0.0 : (float) self::GLUCIDES_MIN;
            if ($g < $gMin) {
                $l = 0.20 * $cible / 9;
                $g = ($cible - 4 * $p - 9 * $l) / 4;
                if ($g < $gMin) {
                    $p = 1.2 * $poidsRef;
                    $g = ($cible - 4 * $p - 9 * $l) / 4;
                    if ($g < $gMin) {
                        $cible = $this->round10(4 * $p + 9 * $l + 200);
                        $g = ($cible - 4 * $p - 9 * $l) / 4;
                        $avertissements[] = sprintf(
                            'Cibles remontées à %s kcal pour garantir un minimum de glucides et de lipides.',
                            $this->fr($cible)
                        );
                    }
                }
            }

            // 10. Régimes
            switch ($regime) {
                case 'keto':
                    $g = (float) self::GLUCIDES_MIN_KETO;
                    $l = ($cible - 4 * $p - 4 * $g) / 9;
                    if (9 * $l < 0.60 * $cible) {
                        $avertissements[] = 'Répartition cétogène : les lipides représentent moins de 60 % des calories.';
                    }
                    break;

                case 'low_carb':
                    if ($g > 100) {
                        $surplus = ($g - 100) * 4;
                        $g = 100.0;
                        $l += $surplus / 9;
                    }
                    break;

                case 'mediterraneen':
                case 'dash':
                case 'montignac':
                    $p = max($p, 0.20 * $cible / 4);
                    $l = 0.30 * $cible / 9;
                    $g = ($cible - 4 * $p - 9 * $l) / 4;
                    if ($regime === 'montignac') {
                        $avertissements[] = 'Répartition indicative : l’index glycémique n’est pas calculé.';
                    }
                    break;
            }
        }

        $p = (int) round($p);
        $l = (int) round($l);
        $g = (int) max(0, round($g / 5) * 5);

        // Variation hebdomadaire d'après la cible arrondie (règle 8) ; l'ajustement affiché est celui
        // réellement appliqué avant l'arrondi à la dizaine (exactement 0 en maintien).
        $ajustementEffectif = $cible - $tdee;
        $variation = round($ajustementEffectif * 7 / self::KCAL_PAR_KG, 2);
        $ajustementAffiche = $ajustementEffectifBrut;

        $etapes[] = sprintf('Cible arrondie : %s kcal/j (%s%s kcal par rapport à la dépense).', $this->fr($cible), $ajustementEffectif < 0 ? '−' : '+', $this->fr(round(abs($ajustementEffectif))));
        $etapes[] = sprintf('Macros : protéines %d g · lipides %d g · glucides %d g.', $p, $l, $g);

        // 11. Fibres et sel
        $fibres = $mineur ? 20 : 25;
        $selMax = $regime === 'dash' ? 5.75 : 5;

        // 12. Mention
        $mention = sprintf(
            'Estimation calculée à partir de ton profil (%s). Ce n’est pas une mesure clinique ni un avis médical.',
            $methode
        );
        if ($mineur) {
            $mention .= ' Estimation indicative pour un adolescent : les besoins varient avec la croissance. Ne remplace pas l’avis d’un médecin ou d’un diététicien.';
        }
        if ($situationParticuliere) {
            $mention .= ' Besoins indicatifs uniquement ; ton suivi médical prime.';
        }
        if ($sportJours >= 3 && in_array($niveau, ['eleve', 'tres_eleve'], true)) {
            $mention .= ' Choisis un niveau d’activité ≤ modéré si tu enregistres tes séances, sinon elles comptent deux fois.';
        }

        return [
            'bmr' => round($bmr, 2),
            'tdee' => round($tdee, 1),
            'calories_recommandees' => (int) $cible,
            'proteines_g' => $p,
            'glucides_g' => $g,
            'lipides_g' => $l,
            'ajustement_kcal' => (int) round($ajustementAffiche),
            'variation_hebdo_kg' => $variation,
            'plancher_kcal' => (int) round($plancher),
            'poids_reference' => $poids,
            'jours_restants' => $joursRestants,
            'cibles_calculees_le' => $today->toDateString(),
            'imc' => $imc,
            'imc_cible' => $imcCible,
            'fibres_g' => $fibres,
            'sel_max_g' => $selMax,
            'profil_mineur' => $mineur,
            'avertissements' => array_values(array_unique($avertissements)),
            'etapes' => $etapes,
            'mention' => $mention,
            'is_estimate' => true,
        ];
    }

    // ------------------------------------------------------------------------------------
    // Validation (règles 3 et 4 → 422)
    // ------------------------------------------------------------------------------------

    /**
     * Règles de cohérence (§2.3 r.3 et r.4). Retourne champ => message ; vide si tout est cohérent.
     * Le contrôleur lève `ValidationException::withMessages()` avec ce tableau.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function validateRequest(array $input): array
    {
        $errors = [];

        $age = (int) ($input['age'] ?? 30);
        $taille = (float) ($input['taille'] ?? 170);
        $poids = (float) ($input['poids'] ?? 70);
        $poidsSouhaite = $this->floatOrNull($input['poids_souhaite_kg'] ?? null);
        $delai = $this->intOrNull($input['delai_objectif_jours'] ?? null);
        $dateFin = $input['objectif_date_fin'] ?? null;
        $objectif = (string) ($input['objectif_type'] ?? 'maintenir');
        $regime = (string) ($input['regime_alimentaire'] ?? $input['regime'] ?? 'omnivore');
        $situation = (string) ($input['situation_particuliere'] ?? 'aucune');
        $consentementParental = filter_var($input['consentement_parental'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $mineur = $age < 18;

        if ($mineur && $age < 15 && ! $consentementParental) {
            $errors['consentement_parental'] = self::MSG_CONSENTEMENT_PARENTAL;
        }

        if ($mineur && in_array($regime, self::REGIMES_INTERDITS_MINEURS, true)) {
            $errors['regime_alimentaire'] = self::MSG_MINEUR_REGIME;
        }

        if ($objectif === 'maintenir') {
            return $errors;
        }

        if ($mineur) {
            $errors['objectif_type'] = self::MSG_MINEUR_OBJECTIF;

            return $errors;
        }

        if ($situation !== 'aucune') {
            $errors['objectif_type'] = self::MSG_SITUATION_OBJECTIF;

            return $errors;
        }

        if ($poidsSouhaite === null) {
            $errors['poids_souhaite_kg'] = self::MSG_POIDS_SOUHAITE_REQUIS;
        }

        if ($delai === null && empty($dateFin)) {
            $errors['delai_objectif_jours'] = self::MSG_DELAI_REQUIS;
        }

        if ($poidsSouhaite !== null) {
            if ($objectif === 'perdre' && $poidsSouhaite >= $poids) {
                $errors['poids_souhaite_kg'] = self::MSG_PERTE_INCOHERENTE;
            } elseif ($objectif === 'prendre' && $poidsSouhaite <= $poids) {
                $errors['poids_souhaite_kg'] = self::MSG_PRISE_INCOHERENTE;
            }
        }

        if ($objectif === 'perdre') {
            $hm2 = ($taille / 100) ** 2;
            $imc = $hm2 > 0 ? $poids / $hm2 : null;
            $imcCible = ($poidsSouhaite !== null && $hm2 > 0) ? $poidsSouhaite / $hm2 : null;

            if ($imc !== null && round($imc, 1) < self::IMC_MIN) {
                $errors['objectif_type'] = self::MSG_IMC_TROP_BAS;
            } elseif ($imcCible !== null && round($imcCible, 1) < self::IMC_MIN && ! isset($errors['poids_souhaite_kg'])) {
                $errors['poids_souhaite_kg'] = self::MSG_IMC_TROP_BAS;
            }
        }

        return $errors;
    }

    /**
     * Enveloppe de sécurité des cibles manuelles (§2.4). Retourne champ => message.
     *
     * @param  array<string, mixed>  $profile  Entrée de compute() (profil complet)
     * @param  array<string, mixed>  $overrides  calories_cibles, proteines_cibles, glucides_cibles, lipides_cibles (partiels acceptés)
     * @return array<string, string>
     */
    public function validateOverrides(array $profile, array $overrides): array
    {
        $errors = [];
        $besoins = $this->compute($profile);

        $poids = (float) ($profile['poids'] ?? 70);
        $taille = (float) ($profile['taille'] ?? 170);
        $regime = (string) ($profile['regime_alimentaire'] ?? $profile['regime'] ?? 'omnivore');
        $poidsRef = min($poids, 30 * ($taille / 100) ** 2);

        $plancher = (float) $besoins['plancher_kcal'];
        $tdee = (float) $besoins['tdee'];

        $calOverride = $this->floatOrNull($overrides['calories_cibles'] ?? null);
        $pOverride = $this->floatOrNull($overrides['proteines_cibles'] ?? null);
        $gOverride = $this->floatOrNull($overrides['glucides_cibles'] ?? null);
        $lOverride = $this->floatOrNull($overrides['lipides_cibles'] ?? null);

        if ($calOverride === null && $pOverride === null && $gOverride === null && $lOverride === null) {
            return $errors;
        }

        $cal = $calOverride ?? (float) $besoins['calories_recommandees'];
        $p = $pOverride ?? (float) $besoins['proteines_g'];
        $g = $gOverride ?? (float) $besoins['glucides_g'];
        $l = $lOverride ?? (float) $besoins['lipides_g'];

        if ($calOverride !== null) {
            if ($calOverride < $plancher) {
                $errors['calories_cibles'] = sprintf(
                    'Les calories cibles ne peuvent pas être inférieures au seuil de sécurité de %s kcal.',
                    $this->fr(round($plancher))
                );
            } elseif ($calOverride > $tdee + 1000) {
                $errors['calories_cibles'] = sprintf(
                    'Les calories cibles ne peuvent pas dépasser %s kcal (dépense quotidienne + 1 000).',
                    $this->fr(round($tdee + 1000))
                );
            }
        }

        if ($pOverride !== null) {
            $pMin = 0.8 * $poidsRef;
            $pMax = 2.2 * $poidsRef;
            if ($pOverride < $pMin - 0.5 || $pOverride > $pMax + 0.5) {
                $errors['proteines_cibles'] = sprintf(
                    'Les protéines cibles doivent être comprises entre %s et %s g (0,8 à 2,2 g par kg de poids de référence).',
                    $this->fr(round($pMin)),
                    $this->fr(round($pMax))
                );
            }
        }

        if ($lOverride !== null) {
            $lMin = 0.20 * $cal / 9;
            if ($lOverride < $lMin - 0.5) {
                $errors['lipides_cibles'] = sprintf(
                    'Les lipides cibles doivent couvrir au moins 20 %% des calories (%s g minimum).',
                    $this->fr(round($lMin))
                );
            }
        }

        if ($gOverride !== null) {
            $gMin = $regime === 'keto' ? self::GLUCIDES_MIN_KETO : self::GLUCIDES_MIN;
            if ($gOverride < $gMin) {
                $errors['glucides_cibles'] = sprintf('Les glucides cibles doivent être d’au moins %d g.', $gMin);
            }
        }

        $somme = 4 * $p + 4 * $g + 9 * $l;
        if ($cal > 0 && abs($somme - $cal) > 0.10 * $cal) {
            $message = str_replace(':n', $this->fr(round($somme)), self::MSG_SOMME_MACROS);
            if (! isset($errors['calories_cibles'])) {
                $errors['calories_cibles'] = $message;
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------------------------
    // Pont avec le modèle Profile
    // ------------------------------------------------------------------------------------

    /**
     * Entrée de compute() construite depuis un profil persistant.
     *
     * @return array<string, mixed>
     */
    public function inputFromProfile(Profile $profile, ?string $today = null): array
    {
        $dateFin = $profile->objectif_date_fin;

        return [
            'sexe' => $profile->sexe,
            'age' => $profile->age,
            'taille' => $profile->taille,
            'poids' => $profile->poids,
            'poids_souhaite_kg' => $profile->poids_souhaite_kg,
            'delai_objectif_jours' => $profile->delai_objectif_jours,
            'objectif_date_fin' => $dateFin instanceof \DateTimeInterface ? $dateFin->format('Y-m-d') : ($dateFin ?: null),
            'niveau_activite' => $profile->niveau_activite,
            'objectif_type' => $profile->objectif_type ?? 'maintenir',
            'regime_alimentaire' => $profile->regime_alimentaire ?? 'omnivore',
            'sport_objectif' => $profile->sport_objectif,
            'situation_particuliere' => $profile->situation_particuliere ?? 'aucune',
            'sport_jours_semaine' => $profile->sport_jours_semaine,
            'consentement_parental' => (bool) ($profile->consentement_parental ?? false),
            'today' => $today,
        ];
    }

    /**
     * Besoins d'un profil persistant ; null si le profil est incomplet (poids, taille, âge, sexe).
     *
     * @return array<string, mixed>|null
     */
    public function fromProfile(Profile $profile, ?string $today = null): ?array
    {
        if (! $this->isComplete($profile)) {
            return null;
        }

        return $this->compute($this->inputFromProfile($profile, $today));
    }

    /**
     * Cibles effectives {calories, proteines, glucides, lipides, source: calcul|utilisateur}.
     * Les valeurs persistées (`*_cibles`) priment quand elles sont complètes — elles sont
     * recalculées aux moments définis par le brief (PUT /profile, hystérésis du poids) ;
     * sinon repli sur un calcul à la volée.
     *
     * @return array{calories: int|null, proteines: int|null, glucides: int|null, lipides: int|null, source: string}
     */
    public function ciblesEffectives(Profile $profile, ?string $today = null): array
    {
        $auto = $profile->objectif_calcul_auto ?? true;
        $auto = $auto === null ? true : (bool) $auto;

        $stored = [
            'calories' => $this->intOrNull($profile->calories_cibles),
            'proteines' => $this->intOrNull($profile->proteines_cibles),
            'glucides' => $this->intOrNull($profile->glucides_cibles),
            'lipides' => $this->intOrNull($profile->lipides_cibles),
        ];
        $storedComplete = ! in_array(null, $stored, true);

        if ($storedComplete) {
            return $stored + ['source' => $auto ? 'calcul' : 'utilisateur'];
        }

        $besoins = $this->fromProfile($profile, $today);
        if ($besoins !== null) {
            return [
                'calories' => (int) $besoins['calories_recommandees'],
                'proteines' => (int) $besoins['proteines_g'],
                'glucides' => (int) $besoins['glucides_g'],
                'lipides' => (int) $besoins['lipides_g'],
                'source' => 'calcul',
            ];
        }

        return $stored + ['source' => $auto ? 'calcul' : 'utilisateur'];
    }

    public function isComplete(Profile $profile): bool
    {
        return $profile->poids !== null
            && $profile->taille !== null
            && $profile->age !== null
            && $profile->sexe !== null
            && (float) $profile->taille > 0
            && (float) $profile->poids > 0;
    }

    // ------------------------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------------------------

    private function clamp(float $value, float $min, float $max): float
    {
        if ($max < $min) {
            $max = $min;
        }

        return max($min, min($max, $value));
    }

    private function round10(float $value): int
    {
        return (int) (round($value / 10) * 10);
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    private function niveauLabel(string $niveau): string
    {
        return match ($niveau) {
            'sedentaire' => 'sédentaire',
            'leger' => 'légère',
            'modere' => 'modérée',
            'eleve' => 'élevée',
            'tres_eleve' => 'très élevée',
            default => $niveau,
        };
    }

    /**
     * Format français : 1 648,75 → « 1 648,75 », 2130 → « 2 130 ».
     */
    private function fr(float|int $value, int $decimals = 0): string
    {
        $formatted = number_format((float) $value, $decimals, ',', ' ');

        if ($decimals > 0 && $decimals <= 2 && ! str_contains((string) $value, '.')) {
            return number_format((float) $value, 0, ',', ' ');
        }

        return $formatted;
    }
}
