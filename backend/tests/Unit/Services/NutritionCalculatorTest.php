<?php

namespace Tests\Unit\Services;

use App\Services\NutritionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Vecteurs obligatoires du brief §2.3 + enveloppe §2.4. Service pur : pas de base ni d'application.
 */
class NutritionCalculatorTest extends TestCase
{
    private NutritionCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new NutritionCalculator;
    }

    /** @return array<string, mixed> */
    private function vecteurHomme70(): array
    {
        return [
            'sexe' => 'homme',
            'age' => 30,
            'taille' => 175,
            'poids' => 70,
            'poids_souhaite_kg' => 65,
            'delai_objectif_jours' => 90,
            'niveau_activite' => 'modere',
            'objectif_type' => 'perdre',
            'regime_alimentaire' => 'omnivore',
            'situation_particuliere' => 'aucune',
            'today' => '2026-09-16',
        ];
    }

    private function assertMacrosCoherentes(array $b): void
    {
        $somme = 4 * $b['proteines_g'] + 9 * $b['lipides_g'] + 4 * $b['glucides_g'];
        $ecart = abs($somme - $b['calories_recommandees']) / $b['calories_recommandees'];
        $this->assertLessThanOrEqual(0.03, $ecart, "Écart macros/cible {$ecart} > 3 % ({$somme} vs {$b['calories_recommandees']})");
    }

    public function test_vecteur_obligatoire_homme_70_kg_perdre_5_kg_en_90_jours(): void
    {
        $b = $this->calc->compute($this->vecteurHomme70());

        $this->assertSame(1648.75, $b['bmr']);
        $this->assertSame(2555.6, $b['tdee']);
        $this->assertSame(2130, $b['calories_recommandees']);
        $this->assertSame(-0.39, $b['variation_hebdo_kg']);
        $this->assertEqualsWithDelta(126, $b['proteines_g'], 5);
        $this->assertEqualsWithDelta(56, $b['lipides_g'], 5);
        $this->assertEqualsWithDelta(280, $b['glucides_g'], 5);
        $this->assertSame(90, $b['jours_restants']);
        $this->assertSame(22.9, $b['imc']);
        $this->assertSame(21.2, $b['imc_cible']);
        $this->assertSame(1649, $b['plancher_kcal']);
        $this->assertFalse($b['profil_mineur']);
        $this->assertTrue($b['is_estimate']);
        $this->assertSame([], $b['avertissements']);
        $this->assertSame(25, $b['fibres_g']);
        $this->assertSame(5, $b['sel_max_g']);
        $this->assertStringContainsString('Mifflin-St Jeor', $b['mention']);
        $this->assertNotEmpty($b['etapes']);
        $this->assertSame('2026-09-16', $b['cibles_calculees_le']);
        $this->assertMacrosCoherentes($b);
        $this->assertSame([], $this->calc->validateRequest($this->vecteurHomme70()));
    }

    public function test_femme_40_kg_150_cm_perdre_est_refusee_pour_imc_trop_bas(): void
    {
        $errors = $this->calc->validateRequest([
            'sexe' => 'femme', 'age' => 28, 'taille' => 150, 'poids' => 40,
            'poids_souhaite_kg' => 38, 'delai_objectif_jours' => 60,
            'niveau_activite' => 'sedentaire', 'objectif_type' => 'perdre',
        ]);

        $this->assertArrayHasKey('objectif_type', $errors);
        $this->assertSame(NutritionCalculator::MSG_IMC_TROP_BAS, $errors['objectif_type']);
    }

    public function test_imc_cible_trop_bas_est_signale_sur_le_poids_souhaite(): void
    {
        $errors = $this->calc->validateRequest([
            'sexe' => 'femme', 'age' => 28, 'taille' => 165, 'poids' => 60,
            'poids_souhaite_kg' => 48, 'delai_objectif_jours' => 200,
            'niveau_activite' => 'sedentaire', 'objectif_type' => 'perdre',
        ]);

        $this->assertSame(NutritionCalculator::MSG_IMC_TROP_BAS, $errors['poids_souhaite_kg'] ?? null);
    }

    public function test_homme_130_kg_165_cm_perdre_utilise_le_poids_de_reference_et_plafonne_le_deficit(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 40, 'taille' => 165, 'poids' => 130,
            'poids_souhaite_kg' => 100, 'delai_objectif_jours' => 180,
            'niveau_activite' => 'modere', 'objectif_type' => 'perdre', 'today' => '2026-09-16',
        ]);

        // poids_ref = min(130, 30 × 1,65²) = 81,7 → P = 1,8 × 81,7 ≈ 147 g (et non 234 g)
        $this->assertEqualsWithDelta(147, $b['proteines_g'], 5);
        $this->assertSame(2136.25, $b['bmr']);
        $this->assertSame(2310, $b['calories_recommandees']);
        $this->assertEqualsWithDelta(-1000, $b['ajustement_kcal'], 5);
        $this->assertTrue(collect($b['avertissements'])->contains(fn ($m) => str_starts_with($m, 'Délai trop court')));
        $this->assertMacrosCoherentes($b);
    }

    public function test_femme_120_kg_160_cm_perdre(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'femme', 'age' => 35, 'taille' => 160, 'poids' => 120,
            'poids_souhaite_kg' => 90, 'delai_objectif_jours' => 365,
            'niveau_activite' => 'leger', 'objectif_type' => 'perdre', 'today' => '2026-09-16',
        ]);

        $this->assertSame(1864.0, $b['bmr']);
        $this->assertSame(1930, $b['calories_recommandees']);
        $this->assertSame(1864, $b['plancher_kcal']);
        // poids_ref = min(120, 30 × 1,6²) = 76,8 → P ≈ 138 g
        $this->assertEqualsWithDelta(138, $b['proteines_g'], 5);
        $this->assertEqualsWithDelta(61, $b['lipides_g'], 5);
        $this->assertSame(46.9, $b['imc']);
        $this->assertMacrosCoherentes($b);
    }

    public function test_mineur_15_ans_maintenir_utilise_schofield_sans_plancher_ni_ajustement(): void
    {
        $input = [
            'sexe' => 'homme', 'age' => 15, 'taille' => 168, 'poids' => 55,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir',
            'regime_alimentaire' => 'omnivore', 'consentement_parental' => true, 'today' => '2026-09-16',
        ];

        $b = $this->calc->compute($input);

        $this->assertSame(1630.5, $b['bmr']); // 17,7 × 55 + 657
        $this->assertSame(2527.3, $b['tdee']);
        $this->assertSame(2530, $b['calories_recommandees']);
        $this->assertTrue($b['profil_mineur']);
        $this->assertSame(0, $b['plancher_kcal']);
        $this->assertSame(0.0, $b['variation_hebdo_kg']);
        $this->assertNull($b['jours_restants']);
        $this->assertSame(55, $b['proteines_g']); // 1,0 g/kg
        $this->assertEqualsWithDelta(84, $b['lipides_g'], 2); // 30 % des kcal
        $this->assertSame(20, $b['fibres_g']);
        $this->assertStringContainsString('Schofield', $b['mention']);
        $this->assertStringContainsString('adolescent', $b['mention']);
        $this->assertMacrosCoherentes($b);
        $this->assertSame([], $this->calc->validateRequest($input));
    }

    public function test_mineur_ne_peut_pas_perdre_ni_choisir_un_regime_interdit(): void
    {
        $errors = $this->calc->validateRequest([
            'sexe' => 'femme', 'age' => 16, 'taille' => 165, 'poids' => 60,
            'poids_souhaite_kg' => 55, 'delai_objectif_jours' => 60,
            'niveau_activite' => 'modere', 'objectif_type' => 'perdre', 'regime_alimentaire' => 'keto',
        ]);

        $this->assertSame(NutritionCalculator::MSG_MINEUR_OBJECTIF, $errors['objectif_type']);
        $this->assertSame(NutritionCalculator::MSG_MINEUR_REGIME, $errors['regime_alimentaire']);
    }

    public function test_moins_de_15_ans_requiert_le_consentement_parental(): void
    {
        $errors = $this->calc->validateRequest([
            'sexe' => 'homme', 'age' => 14, 'taille' => 160, 'poids' => 50,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'consentement_parental' => false,
        ]);

        $this->assertSame(NutritionCalculator::MSG_CONSENTEMENT_PARENTAL, $errors['consentement_parental']);
    }

    public function test_situation_particuliere_force_le_maintien(): void
    {
        $errors = $this->calc->validateRequest([
            'sexe' => 'femme', 'age' => 30, 'taille' => 165, 'poids' => 65,
            'poids_souhaite_kg' => 60, 'delai_objectif_jours' => 90,
            'niveau_activite' => 'modere', 'objectif_type' => 'perdre', 'situation_particuliere' => 'grossesse',
        ]);

        $this->assertSame(NutritionCalculator::MSG_SITUATION_OBJECTIF, $errors['objectif_type']);

        $b = $this->calc->compute([
            'sexe' => 'femme', 'age' => 30, 'taille' => 165, 'poids' => 65,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'situation_particuliere' => 'allaitement',
        ]);
        $this->assertSame(0, $b['ajustement_kcal']);
        $this->assertStringContainsString('suivi médical prime', $b['mention']);
    }

    public function test_incoherence_poids_souhaite_et_objectif(): void
    {
        $perte = $this->calc->validateRequest([
            'sexe' => 'homme', 'age' => 30, 'taille' => 180, 'poids' => 80,
            'poids_souhaite_kg' => 85, 'delai_objectif_jours' => 90, 'objectif_type' => 'perdre',
        ]);
        $this->assertSame(NutritionCalculator::MSG_PERTE_INCOHERENTE, $perte['poids_souhaite_kg']);

        $prise = $this->calc->validateRequest([
            'sexe' => 'homme', 'age' => 30, 'taille' => 180, 'poids' => 80,
            'poids_souhaite_kg' => 75, 'delai_objectif_jours' => 90, 'objectif_type' => 'prendre',
        ]);
        $this->assertSame(NutritionCalculator::MSG_PRISE_INCOHERENTE, $prise['poids_souhaite_kg']);

        $manquant = $this->calc->validateRequest([
            'sexe' => 'homme', 'age' => 30, 'taille' => 180, 'poids' => 80, 'objectif_type' => 'perdre',
        ]);
        $this->assertArrayHasKey('poids_souhaite_kg', $manquant);
        $this->assertArrayHasKey('delai_objectif_jours', $manquant);
    }

    public function test_plancher_de_securite_remonte_la_cible(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'femme', 'age' => 30, 'taille' => 165, 'poids' => 55,
            'poids_souhaite_kg' => 45, 'delai_objectif_jours' => 60,
            'niveau_activite' => 'sedentaire', 'objectif_type' => 'perdre', 'today' => '2026-09-16',
        ]);

        // BMR 1270,25 > 1200 → plancher = BMR ; cible arrondie au-dessus du plancher
        $this->assertSame(1270, $b['plancher_kcal']);
        $this->assertGreaterThanOrEqual(1270.25, $b['calories_recommandees']);
        $this->assertSame(1280, $b['calories_recommandees']);
        $this->assertTrue(collect($b['avertissements'])->contains(fn ($m) => str_contains($m, 'seuil minimal de sécurité')));
        $this->assertTrue(collect($b['avertissements'])->contains(fn ($m) => str_starts_with($m, 'Délai trop court')));
        $this->assertMacrosCoherentes($b);
    }

    public function test_delai_tres_long_porte_l_ajustement_au_minimum(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 30, 'taille' => 180, 'poids' => 80,
            'poids_souhaite_kg' => 79, 'delai_objectif_jours' => 365,
            'niveau_activite' => 'modere', 'objectif_type' => 'perdre', 'today' => '2026-09-16',
        ]);

        $this->assertEqualsWithDelta(-250, $b['ajustement_kcal'], 5);
        $this->assertTrue(collect($b['avertissements'])->contains(fn ($m) => str_starts_with($m, 'Délai très long')));
    }

    public function test_delai_depasse_recalcule_sur_quatre_semaines(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 30, 'taille' => 180, 'poids' => 80,
            'poids_souhaite_kg' => 78, 'objectif_date_fin' => '2026-09-01',
            'niveau_activite' => 'modere', 'objectif_type' => 'perdre', 'today' => '2026-09-16',
        ]);

        $this->assertSame(28, $b['jours_restants']);
        $this->assertTrue(collect($b['avertissements'])->contains(fn ($m) => str_starts_with($m, 'Ton délai est dépassé')));
    }

    public function test_prise_de_poids_plafonnee_a_500_kcal_et_imc_superieur_a_30_avertit(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 25, 'taille' => 170, 'poids' => 85,
            'poids_souhaite_kg' => 95, 'delai_objectif_jours' => 60,
            'niveau_activite' => 'modere', 'objectif_type' => 'prendre', 'today' => '2026-09-16',
        ]);

        $this->assertEqualsWithDelta(500, $b['ajustement_kcal'], 5);
        $this->assertGreaterThan(0, $b['variation_hebdo_kg']);
        $this->assertContains('Le poids visé correspond à un IMC supérieur à 30.', $b['avertissements']);
        $this->assertEqualsWithDelta(2.0 * 85, $b['proteines_g'], 5); // 2,0 g/kg en prise
    }

    public function test_senior_deficit_limite_a_500_et_proteines_minimum_1_2_g_kg(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 70, 'taille' => 175, 'poids' => 90,
            'poids_souhaite_kg' => 80, 'delai_objectif_jours' => 60,
            'niveau_activite' => 'leger', 'objectif_type' => 'perdre', 'today' => '2026-09-16',
        ]);

        $this->assertEqualsWithDelta(-500, $b['ajustement_kcal'], 5);
        $this->assertGreaterThanOrEqual(1.2 * 90 - 1, $b['proteines_g']);
    }

    public function test_regime_keto_fixe_les_glucides_a_25_g(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 35, 'taille' => 180, 'poids' => 80,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'keto',
        ]);

        $this->assertSame(25, $b['glucides_g']);
        $this->assertGreaterThan(0.55 * $b['calories_recommandees'] / 9, $b['lipides_g']);
        $this->assertMacrosCoherentes($b);
    }

    public function test_regime_low_carb_limite_les_glucides_a_100_g(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 35, 'taille' => 180, 'poids' => 80,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'low_carb',
        ]);

        $this->assertLessThanOrEqual(100, $b['glucides_g']);
        $this->assertMacrosCoherentes($b);
    }

    public function test_regime_mediterraneen_et_montignac(): void
    {
        $med = $this->calc->compute([
            'sexe' => 'femme', 'age' => 40, 'taille' => 168, 'poids' => 62,
            'niveau_activite' => 'leger', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'mediterraneen',
        ]);
        $this->assertEqualsWithDelta(0.30 * $med['calories_recommandees'] / 9, $med['lipides_g'], 2);
        $this->assertMacrosCoherentes($med);

        $montignac = $this->calc->compute([
            'sexe' => 'femme', 'age' => 40, 'taille' => 168, 'poids' => 62,
            'niveau_activite' => 'leger', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'montignac',
        ]);
        $this->assertContains('Répartition indicative : l’index glycémique n’est pas calculé.', $montignac['avertissements']);

        $dash = $this->calc->compute([
            'sexe' => 'femme', 'age' => 40, 'taille' => 168, 'poids' => 62,
            'niveau_activite' => 'leger', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'dash',
        ]);
        $this->assertSame(5.75, $dash['sel_max_g']);
    }

    public function test_prise_de_muscle_impose_2_g_kg_de_proteines(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 28, 'taille' => 178, 'poids' => 75,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'sport_objectif' => 'prise_de_muscle',
        ]);

        $this->assertEqualsWithDelta(150, $b['proteines_g'], 2);
    }

    public function test_mention_double_comptage_sport(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'homme', 'age' => 28, 'taille' => 178, 'poids' => 75,
            'niveau_activite' => 'tres_eleve', 'objectif_type' => 'maintenir', 'sport_jours_semaine' => 4,
        ]);

        $this->assertStringContainsString('comptent deux fois', $b['mention']);
    }

    // --- Enveloppe des cibles manuelles (§2.4) --------------------------------------------

    public function test_overrides_sous_le_plancher_sont_refuses(): void
    {
        $errors = $this->calc->validateOverrides($this->vecteurHomme70(), ['calories_cibles' => 1500]);

        $this->assertArrayHasKey('calories_cibles', $errors);
        $this->assertStringContainsString('seuil de sécurité de 1 649 kcal', $errors['calories_cibles']);
    }

    public function test_overrides_au_dessus_de_tdee_plus_1000_sont_refuses(): void
    {
        $errors = $this->calc->validateOverrides($this->vecteurHomme70(), ['calories_cibles' => 3700]);

        $this->assertArrayHasKey('calories_cibles', $errors);
    }

    public function test_overrides_coherents_sont_acceptes(): void
    {
        $errors = $this->calc->validateOverrides($this->vecteurHomme70(), [
            'calories_cibles' => 2100,
            'proteines_cibles' => 126,
            'glucides_cibles' => 280,
            'lipides_cibles' => 56,
        ]);

        $this->assertSame([], $errors);
        $this->assertSame([], $this->calc->validateOverrides($this->vecteurHomme70(), []));
    }

    public function test_overrides_macros_incoherentes(): void
    {
        $errors = $this->calc->validateOverrides($this->vecteurHomme70(), [
            'calories_cibles' => 2500,
            'proteines_cibles' => 100,
            'glucides_cibles' => 100,
            'lipides_cibles' => 50,
        ]);
        $this->assertStringContainsString('La somme des macros (1 250 kcal) ne correspond pas aux calories cibles.', $errors['calories_cibles']);

        $errors = $this->calc->validateOverrides($this->vecteurHomme70(), ['glucides_cibles' => 30]);
        $this->assertSame('Les glucides cibles doivent être d’au moins 50 g.', $errors['glucides_cibles']);

        $errors = $this->calc->validateOverrides($this->vecteurHomme70(), ['proteines_cibles' => 30]);
        $this->assertArrayHasKey('proteines_cibles', $errors);

        $errors = $this->calc->validateOverrides($this->vecteurHomme70(), ['lipides_cibles' => 20]);
        $this->assertArrayHasKey('lipides_cibles', $errors);
    }
}
