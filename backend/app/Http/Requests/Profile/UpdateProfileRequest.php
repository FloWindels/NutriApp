<?php

namespace App\Http\Requests\Profile;

use App\Enums\Equipment;
use App\Enums\Lieu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /profile (brief §2.2) : à la création, les 10 champs historiques sont requis, sauf poids_souhaite_kg et
 * delai_objectif_jours (exigés par NutritionCalculator::validateRequest selon l'objectif) ;
 * les nouveaux champs sont `sometimes|nullable`. L'enveloppe des cibles (§2.4) est vérifiée
 * par le service, d'où l'absence du `min:500` historique.
 */
class UpdateProfileRequest extends FormRequest
{
    public const SEXES = ['homme', 'femme'];

    public const NIVEAUX_ACTIVITE = ['sedentaire', 'leger', 'modere', 'eleve', 'tres_eleve'];

    public const OBJECTIFS = ['perdre', 'maintenir', 'prendre'];

    public const REGIMES = [
        'omnivore', 'mediterraneen', 'dash', 'flexitarien', 'low_carb', 'keto', 'jeune_intermittent',
        'vegetarien', 'vegan', 'sans_gluten', 'sans_lactose', 'montignac', 'halal', 'autre',
    ];

    public const SPORT_NIVEAUX = ['debutant', 'intermediaire', 'avance'];

    public const SPORT_OBJECTIFS = ['perte_de_gras', 'prise_de_muscle', 'endurance', 'forme', 'force'];

    public const ZONES = ['genoux', 'dos', 'epaules', 'poignets', 'hanches', 'cou', 'chevilles', 'coudes'];

    public const FOCUS = [
        'perte_de_gras', 'prise_de_muscle', 'endurance', 'force', 'mobilite', 'gainage', 'haut_du_corps',
        'bas_du_corps', 'fessiers', 'abdos', 'dos', 'bras', 'pectoraux', 'epaules', 'jambes', 'cardio',
    ];

    public const SITUATIONS = ['aucune', 'grossesse', 'allaitement', 'suivi_medical'];

    public const COEF_CALORIES = [50, 75, 100];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Premier enregistrement : le profil doit être complet pour que les calculs aient
        // toutes leurs entrées. Profil déjà créé : mise à jour partielle autorisée (le service
        // fusionne avec l'existant), ce qui permet aux écrans Paramètres de ne modifier qu'un
        // réglage. Les clients qui envoient tous les champs continuent de fonctionner à l'identique.
        $creation = $this->user()?->profile === null;
        $base = $creation ? ['required'] : ['sometimes', 'required'];

        return [
            'nom' => [...$base, 'string', 'max:255'],
            'sexe' => [...$base, Rule::in(self::SEXES)],
            'age' => [...$base, 'integer', 'min:12', 'max:120'],
            'taille' => [...$base, 'numeric', 'min:100', 'max:300'],
            'poids' => [...$base, 'numeric', 'min:20', 'max:500'],
            'poids_souhaite_kg' => ['nullable', 'numeric', 'min:20', 'max:500'],
            'delai_objectif_jours' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'niveau_activite' => [...$base, Rule::in(self::NIVEAUX_ACTIVITE)],
            'objectif_type' => [...$base, Rule::in(self::OBJECTIFS)],
            'objectif' => ['nullable', 'string', 'max:100'],
            'regime_alimentaire' => [...$base, Rule::in(self::REGIMES)],

            // Cibles manuelles (enveloppe §2.4 vérifiée par le service)
            'calories_cibles' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'proteines_cibles' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'glucides_cibles' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'lipides_cibles' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'objectif_calcul_auto' => ['sometimes', 'nullable', 'boolean'],

            // Consentements
            'consentement_sante' => ['sometimes', 'nullable', 'boolean'],
            'consentement_parental' => ['sometimes', 'nullable', 'boolean'],

            // Alimentation
            'allergenes' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allergenes.*' => ['string', 'max:64'],
            'aliments_exclus' => ['sometimes', 'nullable', 'array', 'max:100'],
            'aliments_exclus.*' => ['string', 'max:64'],
            'preferences' => ['sometimes', 'nullable', 'array'],
            'preferences.aime' => ['sometimes', 'nullable', 'array', 'max:100'],
            'preferences.aime.*' => ['string', 'max:64'],
            'preferences.evite' => ['sometimes', 'nullable', 'array', 'max:100'],
            'preferences.evite.*' => ['string', 'max:64'],

            // Sport
            'sport_niveau' => ['sometimes', 'nullable', Rule::in(self::SPORT_NIVEAUX)],
            'sport_objectif' => ['sometimes', 'nullable', Rule::in(self::SPORT_OBJECTIFS)],
            'sport_materiel' => ['sometimes', 'nullable', 'array'],
            'sport_materiel.*' => [Rule::in(Equipment::values())],
            'sport_temps_dispo_min' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:600'],
            'sport_jours_semaine' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:7'],
            'sport_lieu' => ['sometimes', 'nullable', Rule::in(Lieu::values())],
            'sport_zones_a_eviter' => ['sometimes', 'nullable', 'array'],
            'sport_zones_a_eviter.*' => [Rule::in(self::ZONES)],
            'sport_focus' => ['sometimes', 'nullable', 'array'],
            'sport_focus.*' => [Rule::in(self::FOCUS)],
            'sport_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sport_coef_calories' => ['sometimes', 'nullable', 'integer', Rule::in(self::COEF_CALORIES)],

            // Sécurité
            'situation_particuliere' => ['sometimes', 'nullable', Rule::in(self::SITUATIONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nom' => 'nom',
            'sexe' => 'sexe',
            'age' => 'âge',
            'taille' => 'taille',
            'poids' => 'poids',
            'poids_souhaite_kg' => 'poids souhaité',
            'delai_objectif_jours' => 'délai de l’objectif',
            'niveau_activite' => 'niveau d’activité',
            'objectif_type' => 'type d’objectif',
            'objectif' => 'objectif',
            'regime_alimentaire' => 'régime alimentaire',
            'calories_cibles' => 'calories cibles',
            'proteines_cibles' => 'protéines cibles',
            'glucides_cibles' => 'glucides cibles',
            'lipides_cibles' => 'lipides cibles',
            'objectif_calcul_auto' => 'calcul automatique des cibles',
            'consentement_sante' => 'accord santé',
            'consentement_parental' => 'accord parental',
            'allergenes' => 'allergènes',
            'allergenes.*' => 'allergène',
            'aliments_exclus' => 'aliments exclus',
            'aliments_exclus.*' => 'aliment exclu',
            'preferences' => 'préférences',
            'preferences.aime' => 'aliments appréciés',
            'preferences.aime.*' => 'aliment apprécié',
            'preferences.evite' => 'aliments évités',
            'preferences.evite.*' => 'aliment évité',
            'sport_niveau' => 'niveau sportif',
            'sport_objectif' => 'objectif sportif',
            'sport_materiel' => 'matériel',
            'sport_materiel.*' => 'matériel',
            'sport_temps_dispo_min' => 'temps disponible',
            'sport_jours_semaine' => 'jours d’entraînement par semaine',
            'sport_lieu' => 'lieu d’entraînement',
            'sport_zones_a_eviter' => 'zones à éviter',
            'sport_zones_a_eviter.*' => 'zone à éviter',
            'sport_focus' => 'focus',
            'sport_focus.*' => 'focus',
            'sport_notes' => 'notes sportives',
            'sport_coef_calories' => 'réintégration des calories brûlées',
            'situation_particuliere' => 'situation particulière',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sport_coef_calories.in' => 'La réintégration des calories brûlées doit valoir 50, 75 ou 100 %.',
        ];
    }
}
