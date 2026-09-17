<?php

namespace Tests\Feature\Profile;

use App\Http\Resources\ProfilePayload;
use App\Models\Profile;
use App\Models\User;
use App\Services\NutritionCalculator;
use App\Services\Profile\ProfileService;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use ProfileTestHelpers;
    use RefreshDatabase;

    // ------------------------------------------------------------------ vecteur obligatoire

    public function test_put_profile_mandatory_vector_computes_and_persists_targets(): void
    {
        $user = $this->actingAsUser();
        $today = Clock::today($user);

        $response = $this->putJson('/api/profile', $this->vecteurHomme70())->assertOk();

        $response->assertJsonPath('message', 'Profil mis à jour.')
            ->assertJsonPath('nom', 'Jean Dupont')
            ->assertJsonPath('has_profile', true)
            ->assertJsonPath('calories_cibles', 2130)
            ->assertJsonPath('proteines_cibles', 126)
            ->assertJsonPath('lipides_cibles', 56)
            ->assertJsonPath('glucides_cibles', 280)
            ->assertJsonPath('objectif_calcul_auto', true)
            ->assertJsonPath('cibles_effectives', ['calories' => 2130, 'proteines' => 126, 'glucides' => 280, 'lipides' => 56, 'source' => 'calcul'])
            ->assertJsonPath('besoins.bmr', 1648.75)
            ->assertJsonPath('besoins.tdee', 2555.6)
            ->assertJsonPath('besoins.calories_recommandees', 2130)
            ->assertJsonPath('besoins.variation_hebdo_kg', -0.39)
            ->assertJsonPath('besoins.jours_restants', 90)
            ->assertJsonPath('besoins.avertissements', [])
            ->assertJsonPath('imc', 22.9)
            ->assertJsonPath('imc_cible', 21.2)
            ->assertJsonPath('poids_reference', 70)
            ->assertJsonPath('cibles_calculees_le', $today)
            ->assertJsonPath('objectif_date_debut', $today)
            ->assertJsonPath('objectif_date_fin', CarbonImmutable::parse($today)->addDays(90)->toDateString())
            ->assertJsonPath('consentement_sante', true);

        // Le PUT renvoie exactement la charge utile du GET + message.
        $expected = array_merge(['message'], ProfilePayload::LEGACY_KEYS, ProfilePayload::NEW_KEYS);
        sort($expected);
        $actual = array_keys($response->json());
        sort($actual);
        $this->assertSame($expected, $actual);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'calories_cibles' => 2130,
            'proteines_cibles' => 126,
            'glucides_cibles' => 280,
            'lipides_cibles' => 56,
            'objectif_calcul_auto' => 1,
            'poids_reference' => 70,
            'cibles_calculees_le' => $today,
        ]);
        $this->assertSame('Jean Dupont', $user->fresh()->name);
        $this->assertNotNull($user->fresh()->consentement_sante_at);

        // Le GET renvoie la même chose (sans message).
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('calories_cibles', 2130)->assertJsonMissing(['message']);
    }

    public function test_put_profile_serializes_types_correctly(): void
    {
        $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['poids' => 70.5, 'taille' => 175.5, 'poids_souhaite_kg' => 64.5]))->assertOk()->json();

        $this->assertIsFloat($json['poids']);
        $this->assertIsFloat($json['taille']);
        $this->assertIsFloat($json['poids_souhaite_kg']);
        $this->assertIsInt($json['delai_objectif_jours']);
        $this->assertIsInt($json['age']);
        $this->assertIsInt($json['calories_cibles']);
        $this->assertIsBool($json['objectif_calcul_auto']);
        $this->assertIsBool($json['has_profile']);
        $this->assertIsBool($json['consentement_parental']);
        $this->assertIsFloat($json['imc']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $json['objectif_date_debut']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $json['objectif_date_fin']);
    }

    // ------------------------------------------------------------------ consentement

    public function test_first_save_without_health_consent_is_rejected(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70(['consentement_sante' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consentement_sante' => ProfileService::MSG_CONSENTEMENT_SANTE]);

        $this->putJson('/api/profile', $this->vecteurHomme70(['consentement_sante' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consentement_sante']);

        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
        $this->assertNull($user->fresh()->consentement_sante_at);
    }

    public function test_consent_is_not_required_once_recorded(): void
    {
        $user = $this->actingAsUser();
        $this->putJson('/api/profile', $this->vecteurHomme70())->assertOk();
        $consentAt = $user->fresh()->consentement_sante_at;

        $this->putJson('/api/profile', $this->vecteurHomme70(['consentement_sante' => null, 'poids' => 69]))->assertOk();
        $this->putJson('/api/profile', array_diff_key($this->vecteurHomme70(['poids' => 68]), ['consentement_sante' => 1]))->assertOk();

        $this->assertEquals($consentAt, $user->fresh()->consentement_sante_at);
    }

    // ------------------------------------------------------------------ mineurs & situations

    public function test_minor_cannot_choose_weight_loss_goal(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70(['age' => 16, 'poids' => 60, 'poids_souhaite_kg' => 55]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objectif_type' => NutritionCalculator::MSG_MINEUR_OBJECTIF]);
    }

    public function test_minor_cannot_choose_forbidden_diet(): void
    {
        $this->actingAsUser();

        foreach (['keto', 'low_carb', 'jeune_intermittent', 'montignac'] as $regime) {
            $this->putJson('/api/profile', $this->maintien(['age' => 16, 'regime_alimentaire' => $regime]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['regime_alimentaire' => NutritionCalculator::MSG_MINEUR_REGIME]);
        }
    }

    public function test_under_15_requires_parental_consent(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->maintien(['age' => 14]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consentement_parental' => NutritionCalculator::MSG_CONSENTEMENT_PARENTAL]);

        $json = $this->putJson('/api/profile', $this->maintien(['age' => 14, 'consentement_parental' => true]))
            ->assertOk()
            ->json();

        $this->assertTrue($json['consentement_parental']);
        $this->assertTrue($json['besoins']['profil_mineur']);
        $this->assertSame(0, $json['besoins']['plancher_kcal']);
        $this->assertStringContainsString('Schofield', $json['besoins']['mention']);
    }

    public function test_minor_15_maintenir_uses_schofield(): void
    {
        $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->maintien([
            'sexe' => 'homme', 'age' => 15, 'taille' => 170, 'poids' => 55, 'niveau_activite' => 'modere',
        ]))->assertOk()->json();

        // Schofield garçon : 17.7 × 55 + 657 = 1630.5
        $this->assertSame(1630.5, $json['besoins']['bmr']);
        $this->assertTrue($json['besoins']['profil_mineur']);
        $this->assertSame(20, $json['besoins']['fibres_g']);
    }

    public function test_special_situation_forces_maintenir(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->maintien([
            'objectif_type' => 'perdre', 'poids_souhaite_kg' => 55, 'delai_objectif_jours' => 60,
            'situation_particuliere' => 'grossesse',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objectif_type' => NutritionCalculator::MSG_SITUATION_OBJECTIF]);

        $json = $this->putJson('/api/profile', $this->maintien(['situation_particuliere' => 'allaitement']))->assertOk()->json();
        $this->assertSame('allaitement', $json['situation_particuliere']);
        $this->assertStringContainsString('ton suivi médical prime', $json['besoins']['mention']);
    }

    // ------------------------------------------------------------------ cohérence & IMC

    public function test_weight_loss_target_must_be_below_current_weight(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70(['poids_souhaite_kg' => 72]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['poids_souhaite_kg' => NutritionCalculator::MSG_PERTE_INCOHERENTE]);

        $this->putJson('/api/profile', $this->vecteurHomme70(['objectif_type' => 'prendre', 'poids_souhaite_kg' => 65]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['poids_souhaite_kg' => NutritionCalculator::MSG_PRISE_INCOHERENTE]);
    }

    public function test_weight_loss_requires_target_and_delay_for_adults(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70(['poids_souhaite_kg' => null, 'delai_objectif_jours' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'poids_souhaite_kg' => NutritionCalculator::MSG_POIDS_SOUHAITE_REQUIS,
                'delai_objectif_jours' => NutritionCalculator::MSG_DELAI_REQUIS,
            ]);
    }

    public function test_bmi_below_18_5_blocks_weight_loss(): void
    {
        $this->actingAsUser();

        // femme 40 kg / 150 cm → IMC 17,8
        $this->putJson('/api/profile', $this->maintien([
            'poids' => 40, 'taille' => 150, 'niveau_activite' => 'sedentaire',
            'objectif_type' => 'perdre', 'poids_souhaite_kg' => 38, 'delai_objectif_jours' => 60,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['objectif_type' => NutritionCalculator::MSG_IMC_TROP_BAS]);

        // IMC actuel correct mais cible sous 18,5 → erreur sur le poids souhaité
        $this->putJson('/api/profile', $this->maintien([
            'poids' => 55, 'taille' => 165, 'objectif_type' => 'perdre', 'poids_souhaite_kg' => 48, 'delai_objectif_jours' => 90,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['poids_souhaite_kg' => NutritionCalculator::MSG_IMC_TROP_BAS]);
    }

    public function test_maintenir_ignores_target_weight_and_delay(): void
    {
        $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->maintien(['poids_souhaite_kg' => 55, 'delai_objectif_jours' => 30]))
            ->assertOk()
            ->json();

        $this->assertNull($json['poids_souhaite_kg']);
        $this->assertNull($json['delai_objectif_jours']);
        $this->assertNull($json['objectif_date_fin']);
        $this->assertNull($json['imc_cible']);
        $this->assertSame(0, $json['besoins']['ajustement_kcal']);
    }

    // ------------------------------------------------------------------ enveloppe des cibles (§2.4)

    public function test_override_below_safety_floor_is_rejected(): void
    {
        $this->actingAsUser();

        // Plancher = BMR (perte) = 1 649 kcal
        $response = $this->putJson('/api/profile', $this->vecteurHomme70(['calories_cibles' => 1500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calories_cibles']);

        $this->assertStringContainsString('seuil de sécurité de 1 649 kcal', $response->json('errors.calories_cibles.0'));
    }

    public function test_override_above_tdee_plus_1000_is_rejected(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70([
            'calories_cibles' => 3600, 'proteines_cibles' => 130, 'glucides_cibles' => 450, 'lipides_cibles' => 120,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['calories_cibles']);
    }

    public function test_override_macro_sum_must_match_calories_within_10_percent(): void
    {
        $this->actingAsUser();

        $response = $this->putJson('/api/profile', $this->vecteurHomme70([
            'calories_cibles' => 2100, 'proteines_cibles' => 100, 'glucides_cibles' => 100, 'lipides_cibles' => 60,
        ]))->assertStatus(422);

        // 4×100 + 4×100 + 9×60 = 1 340 kcal
        $this->assertStringContainsString('La somme des macros (1 340 kcal) ne correspond pas aux calories cibles.', $response->json('errors.calories_cibles.0'));
    }

    public function test_override_protein_fat_and_carb_bounds(): void
    {
        $this->actingAsUser();

        // Protéines > 2,2 g/kg (154 g)
        $this->putJson('/api/profile', $this->vecteurHomme70(['proteines_cibles' => 200]))
            ->assertStatus(422)->assertJsonValidationErrors(['proteines_cibles']);

        // Lipides < 20 % des calories
        $this->putJson('/api/profile', $this->vecteurHomme70(['lipides_cibles' => 20]))
            ->assertStatus(422)->assertJsonValidationErrors(['lipides_cibles']);

        // Glucides < 50 g
        $this->putJson('/api/profile', $this->vecteurHomme70(['glucides_cibles' => 30]))
            ->assertStatus(422)->assertJsonValidationErrors(['glucides_cibles']);
    }

    public function test_valid_overrides_are_stored_and_switch_to_manual_mode(): void
    {
        $user = $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70([
            'calories_cibles' => 2000, 'proteines_cibles' => 130, 'glucides_cibles' => 230, 'lipides_cibles' => 60,
        ]))->assertOk()->json();

        $this->assertFalse($json['objectif_calcul_auto']);
        $this->assertSame(
            ['calories' => 2000, 'proteines' => 130, 'glucides' => 230, 'lipides' => 60, 'source' => 'utilisateur'],
            $json['cibles_effectives']
        );
        // Les avertissements/besoins calculés restent visibles malgré la surcharge.
        $this->assertSame(2130, $json['besoins']['calories_recommandees']);
        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'objectif_calcul_auto' => 0, 'calories_cibles' => 2000]);
    }

    public function test_partial_override_is_completed_from_computed_values(): void
    {
        $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['calories_cibles' => 2200]))->assertOk()->json();

        $this->assertFalse($json['objectif_calcul_auto']);
        $this->assertSame(2200, $json['calories_cibles']);
        $this->assertSame(126, $json['proteines_cibles']);
        $this->assertSame(280, $json['glucides_cibles']);
        $this->assertSame(56, $json['lipides_cibles']);
    }

    // ------------------------------------------------------------------ précédence

    public function test_explicit_auto_true_wins_over_targets_in_body(): void
    {
        $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70([
            'objectif_calcul_auto' => true, 'calories_cibles' => 2000, 'proteines_cibles' => 130, 'glucides_cibles' => 230, 'lipides_cibles' => 60,
        ]))->assertOk()->json();

        $this->assertTrue($json['objectif_calcul_auto']);
        $this->assertSame(2130, $json['calories_cibles']);
        $this->assertSame('calcul', $json['cibles_effectives']['source']);
    }

    public function test_explicit_auto_false_without_targets_keeps_stored_overrides(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70([
            'calories_cibles' => 2000, 'proteines_cibles' => 130, 'glucides_cibles' => 230, 'lipides_cibles' => 60,
        ]))->assertOk();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['objectif_calcul_auto' => false]))->assertOk()->json();

        $this->assertFalse($json['objectif_calcul_auto']);
        $this->assertSame(2000, $json['calories_cibles']);
        $this->assertSame(130, $json['proteines_cibles']);
    }

    public function test_stored_manual_mode_is_kept_when_body_has_neither_flag_nor_targets(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70([
            'calories_cibles' => 2000, 'proteines_cibles' => 130, 'glucides_cibles' => 230, 'lipides_cibles' => 60,
        ]))->assertOk();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['poids' => 69.5]))->assertOk()->json();

        $this->assertFalse($json['objectif_calcul_auto']);
        $this->assertSame(2000, $json['calories_cibles']);
        $this->assertSame('utilisateur', $json['cibles_effectives']['source']);
    }

    public function test_switching_back_to_auto_recomputes_targets(): void
    {
        $user = $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70([
            'calories_cibles' => 2000, 'proteines_cibles' => 130, 'glucides_cibles' => 230, 'lipides_cibles' => 60,
        ]))->assertOk();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['objectif_calcul_auto' => true]))->assertOk()->json();

        $this->assertTrue($json['objectif_calcul_auto']);
        $this->assertSame(2130, $json['calories_cibles']);
        $this->assertSame(Clock::today($user), $json['cibles_calculees_le']);
    }

    // ------------------------------------------------------------------ dates d'objectif

    public function test_objective_dates_restart_only_when_goal_fields_change(): void
    {
        $user = $this->actingAsUser();
        $today = Clock::today($user);

        $this->putJson('/api/profile', $this->vecteurHomme70())->assertOk();

        // Simule un objectif démarré il y a 10 jours.
        Profile::where('user_id', $user->id)->update([
            'objectif_date_debut' => CarbonImmutable::parse($today)->subDays(10)->toDateString(),
            'objectif_date_fin' => CarbonImmutable::parse($today)->addDays(80)->toDateString(),
        ]);

        // Même objectif, seul le poids change → dates conservées, jours restants = 80.
        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['poids' => 69]))->assertOk()->json();
        $this->assertSame(CarbonImmutable::parse($today)->subDays(10)->toDateString(), $json['objectif_date_debut']);
        $this->assertSame(CarbonImmutable::parse($today)->addDays(80)->toDateString(), $json['objectif_date_fin']);
        $this->assertSame(80, $json['besoins']['jours_restants']);

        // Changement du délai → redémarrage.
        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['delai_objectif_jours' => 120]))->assertOk()->json();
        $this->assertSame($today, $json['objectif_date_debut']);
        $this->assertSame(CarbonImmutable::parse($today)->addDays(120)->toDateString(), $json['objectif_date_fin']);
        $this->assertSame(120, $json['besoins']['jours_restants']);

        // Changement du poids souhaité → redémarrage.
        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['delai_objectif_jours' => 120, 'poids_souhaite_kg' => 64]))->assertOk()->json();
        $this->assertSame($today, $json['objectif_date_debut']);
    }

    public function test_expired_objective_is_recomputed_over_four_weeks_with_warning(): void
    {
        $user = $this->actingAsUser();
        $today = Clock::today($user);

        $this->putJson('/api/profile', $this->vecteurHomme70())->assertOk();
        Profile::where('user_id', $user->id)->update([
            'objectif_date_debut' => CarbonImmutable::parse($today)->subDays(100)->toDateString(),
            'objectif_date_fin' => CarbonImmutable::parse($today)->subDays(10)->toDateString(),
        ]);

        $json = $this->getJson('/api/profile')->assertOk()->json();

        $this->assertSame(28, $json['besoins']['jours_restants']);
        $this->assertContains('Ton délai est dépassé : objectif recalculé sur 4 semaines. Mets à jour ton objectif.', $json['besoins']['avertissements']);
    }

    // ------------------------------------------------------------------ nouveaux champs & validation

    public function test_new_fields_are_persisted_and_returned(): void
    {
        $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70([
            'allergenes' => ['gluten', 'arachide'],
            'aliments_exclus' => ['coriandre'],
            'preferences' => ['aime' => ['poulet'], 'evite' => ['brocoli']],
            'sport_niveau' => 'intermediaire',
            'sport_objectif' => 'prise_de_muscle',
            'sport_materiel' => ['halteres', 'elastiques'],
            'sport_temps_dispo_min' => 45,
            'sport_jours_semaine' => 3,
            'sport_lieu' => 'salle_publique',
            'sport_zones_a_eviter' => ['genoux'],
            'sport_focus' => ['fessiers', 'dos'],
            'sport_notes' => 'J’ai mal au genou droit',
            'sport_coef_calories' => 75,
        ]))->assertOk()->json();

        $this->assertSame(['gluten', 'arachide'], $json['allergenes']);
        $this->assertSame(['coriandre'], $json['aliments_exclus']);
        $this->assertSame(['aime' => ['poulet'], 'evite' => ['brocoli']], $json['preferences']);
        $this->assertSame('intermediaire', $json['sport_niveau']);
        $this->assertSame('prise_de_muscle', $json['sport_objectif']);
        $this->assertSame(['halteres', 'elastiques'], $json['sport_materiel']);
        $this->assertSame(45, $json['sport_temps_dispo_min']);
        $this->assertSame(3, $json['sport_jours_semaine']);
        $this->assertSame('salle_publique', $json['sport_lieu']);
        $this->assertSame(['genoux'], $json['sport_zones_a_eviter']);
        $this->assertSame(['fessiers', 'dos'], $json['sport_focus']);
        $this->assertSame('J’ai mal au genou droit', $json['sport_notes']);
        $this->assertSame(75, $json['sport_coef_calories']);
        // prise_de_muscle → 2,0 g/kg → 140 g de protéines
        $this->assertSame(140, $json['proteines_cibles']);
    }

    public function test_optional_fields_are_kept_when_absent_from_body(): void
    {
        $this->actingAsUser();

        $this->putJson('/api/profile', $this->vecteurHomme70(['allergenes' => ['gluten'], 'sport_coef_calories' => 50]))->assertOk();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['poids' => 69]))->assertOk()->json();

        $this->assertSame(['gluten'], $json['allergenes']);
        $this->assertSame(50, $json['sport_coef_calories']);
    }

    public function test_sport_coef_calories_accepts_only_50_75_100(): void
    {
        $this->actingAsUser();

        foreach ([50, 75, 100] as $coef) {
            $this->putJson('/api/profile', $this->vecteurHomme70(['sport_coef_calories' => $coef]))
                ->assertOk()->assertJsonPath('sport_coef_calories', $coef);
        }

        $this->putJson('/api/profile', $this->vecteurHomme70(['sport_coef_calories' => 60]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sport_coef_calories' => 'La réintégration des calories brûlées doit valoir 50, 75 ou 100 %.']);
    }

    public function test_validation_errors_are_french(): void
    {
        $this->actingAsUser();

        $response = $this->putJson('/api/profile', [
            'nom' => '', 'sexe' => 'autre', 'age' => 5, 'taille' => 'grande', 'poids' => 10,
            'niveau_activite' => 'intense', 'objectif_type' => 'grossir', 'regime_alimentaire' => 'paleo',
            'sport_lieu' => 'plage', 'sport_zones_a_eviter' => ['orteils'], 'situation_particuliere' => 'inconnue',
        ])->assertStatus(422);

        $response->assertJsonValidationErrors([
            'nom', 'sexe', 'age', 'taille', 'poids', 'niveau_activite', 'objectif_type', 'regime_alimentaire',
            'sport_lieu', 'sport_zones_a_eviter.0', 'situation_particuliere',
        ]);

        $this->assertSame('Le champ nom est obligatoire.', $response->json('errors.nom.0'));
        $this->assertStringContainsString('régime alimentaire', $response->json('errors.regime_alimentaire.0'));
        $this->assertStringContainsString('âge', $response->json('errors.age.0'));
    }

    public function test_all_fourteen_diets_are_accepted_for_adults(): void
    {
        $this->actingAsUser();

        foreach (['omnivore', 'mediterraneen', 'dash', 'flexitarien', 'low_carb', 'keto', 'jeune_intermittent', 'vegetarien', 'vegan', 'sans_gluten', 'sans_lactose', 'montignac', 'halal', 'autre'] as $regime) {
            $this->putJson('/api/profile', $this->vecteurHomme70(['regime_alimentaire' => $regime]))
                ->assertOk()->assertJsonPath('regime_alimentaire', $regime);
        }
    }

    public function test_keto_targets_carbs_at_25_g(): void
    {
        $this->actingAsUser();

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['regime_alimentaire' => 'keto']))->assertOk()->json();

        $this->assertSame(25, $json['glucides_cibles']);
        $this->assertSame(5, $json['besoins']['sel_max_g']);

        $json = $this->putJson('/api/profile', $this->vecteurHomme70(['regime_alimentaire' => 'dash']))->assertOk()->json();
        $this->assertSame(5.75, $json['besoins']['sel_max_g']);
    }

    public function test_calories_override_has_no_static_minimum_of_500(): void
    {
        $this->actingAsUser();

        // 400 kcal est refusé par l'enveloppe (plancher), pas par un min:500 statique.
        $response = $this->putJson('/api/profile', $this->vecteurHomme70(['calories_cibles' => 400]))->assertStatus(422);
        $this->assertStringContainsString('seuil de sécurité', $response->json('errors.calories_cibles.0'));
    }

    public function test_put_profile_updates_only_the_authenticated_users_profile(): void
    {
        $other = User::factory()->create();
        Profile::factory()->for($other)->create(['poids' => 99]);

        $this->actingAsUser();
        $this->putJson('/api/profile', $this->vecteurHomme70())->assertOk();

        $this->assertSame(99.0, (float) Profile::where('user_id', $other->id)->value('poids'));
        $this->assertSame(2, Profile::count());
    }
}
