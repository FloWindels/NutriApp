<?php

namespace Tests\Unit\Services;

use App\Services\NutritionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Consentement à un rythme de perte plus rapide que celui conseillé (1 % du poids par semaine).
 *
 * Deux garanties sont vérifiées ici : sans le drapeau, les chiffres sont ceux d'avant, au kcal
 * près ; avec le drapeau, seule la vitesse change — le plancher calorique, lui, ne bouge jamais.
 */
class NutritionRythmeIntenseTest extends TestCase
{
    private NutritionCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new NutritionCalculator;
    }

    /**
     * Homme 70 kg / 175 cm / 30 ans / modéré, qui veut perdre 10 kg en 60 jours.
     * Conseillé : 770 kcal/j (1 % du poids). Demandé : 1 283 kcal/j. Plancher : 1 649 kcal.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function presse(array $overrides = []): array
    {
        return array_replace([
            'sexe' => 'homme',
            'age' => 30,
            'taille' => 175,
            'poids' => 70,
            'poids_souhaite_kg' => 60,
            'delai_objectif_jours' => 60,
            'niveau_activite' => 'modere',
            'objectif_type' => 'perdre',
            'regime_alimentaire' => 'omnivore',
            'situation_particuliere' => 'aucune',
            'today' => '2026-09-16',
        ], $overrides);
    }

    public function test_sans_le_drapeau_le_rythme_reste_celui_conseille(): void
    {
        $b = $this->calc->compute($this->presse());

        $this->assertSame(1790, $b['calories_recommandees']);
        $this->assertSame(-770, $b['ajustement_kcal']);
        $this->assertSame(-0.7, $b['variation_hebdo_kg']);
        $this->assertFalse($b['rythme_intense_accepte']);
    }

    public function test_un_drapeau_absent_et_un_drapeau_a_faux_donnent_le_meme_resultat(): void
    {
        $absent = $this->calc->compute($this->presse());
        $refuse = $this->calc->compute($this->presse(['rythme_intense' => false]));

        $this->assertSame($absent, $refuse);
    }

    public function test_la_proposition_donne_de_quoi_afficher_les_trois_rythmes(): void
    {
        $b = $this->calc->compute($this->presse());

        $this->assertTrue($b['rythme_intense_possible']);
        $this->assertSame(770, $b['rythme_conseille_kcal']);
        $this->assertSame(0.7, $b['rythme_conseille_kg_semaine']);
        $this->assertSame(1283, $b['rythme_demande_kcal']);
        $this->assertSame(1.17, $b['rythme_demande_kg_semaine']);
        $this->assertSame(907, $b['rythme_intense_kcal']);
        $this->assertSame(0.82, $b['rythme_intense_kg_semaine']);
        $this->assertSame(NutritionCalculator::AVERTISSEMENT_RYTHME_VERSION, $b['rythme_avertissement_version']);
    }

    public function test_le_consentement_accelere_mais_s_arrete_au_plancher(): void
    {
        $b = $this->calc->compute($this->presse(['rythme_intense' => true]));

        // 1,5 % du poids vaudrait 1 155 kcal/j, ramenés à 1 000 par la borne absolue, puis à 907
        // par le plancher : la cible se pose dessus, jamais dessous.
        $this->assertSame(-907, $b['ajustement_kcal']);
        $this->assertSame(1650, $b['calories_recommandees']);
        $this->assertSame(1649, $b['plancher_kcal']);
        $this->assertGreaterThanOrEqual($b['plancher_kcal'], $b['calories_recommandees']);
        $this->assertTrue($b['rythme_intense_accepte']);
    }

    public function test_le_plancher_d_une_femme_ne_descend_pas_sous_1200_kcal(): void
    {
        $b = $this->calc->compute([
            'sexe' => 'femme',
            'age' => 30,
            'taille' => 160,
            'poids' => 50,
            'poids_souhaite_kg' => 48,
            'delai_objectif_jours' => 28,
            'niveau_activite' => 'sedentaire',
            'objectif_type' => 'perdre',
            'regime_alimentaire' => 'omnivore',
            'today' => '2026-09-16',
            'rythme_intense' => true,
        ]);

        $this->assertGreaterThanOrEqual(1200, $b['calories_recommandees']);
        $this->assertGreaterThanOrEqual($b['plancher_kcal'], $b['calories_recommandees']);
    }

    public function test_un_mineur_ne_peut_pas_consentir(): void
    {
        $sans = $this->calc->compute($this->presse(['age' => 16]));
        $avec = $this->calc->compute($this->presse(['age' => 16, 'rythme_intense' => true]));

        $this->assertFalse($avec['rythme_intense_possible']);
        $this->assertFalse($avec['rythme_intense_accepte']);
        $this->assertSame($sans['calories_recommandees'], $avec['calories_recommandees']);
    }

    public function test_une_situation_particuliere_ne_permet_pas_de_consentir(): void
    {
        foreach (['grossesse', 'allaitement', 'suivi_medical'] as $situation) {
            $sans = $this->calc->compute($this->presse(['situation_particuliere' => $situation]));
            $avec = $this->calc->compute($this->presse([
                'situation_particuliere' => $situation,
                'rythme_intense' => true,
            ]));

            $this->assertFalse($avec['rythme_intense_possible'], $situation);
            $this->assertSame($sans['calories_recommandees'], $avec['calories_recommandees'], $situation);
        }
    }

    public function test_un_poids_vise_sous_l_imc_minimum_reste_un_refus(): void
    {
        $entree = $this->presse(['poids_souhaite_kg' => 50, 'delai_objectif_jours' => 60]);

        $b = $this->calc->compute($entree + ['rythme_intense' => true]);
        $this->assertFalse($b['rythme_intense_possible']);
        $this->assertFalse($b['rythme_intense_accepte']);

        $erreurs = $this->calc->validateRequest($entree + ['rythme_intense' => true]);
        $this->assertSame(NutritionCalculator::MSG_IMC_TROP_BAS, $erreurs['poids_souhaite_kg'] ?? null);
    }

    public function test_apres_65_ans_le_plafond_passe_de_500_a_750(): void
    {
        $entree = [
            'sexe' => 'homme',
            'age' => 70,
            'taille' => 175,
            'poids' => 80,
            'poids_souhaite_kg' => 70,
            'delai_objectif_jours' => 60,
            'niveau_activite' => 'modere',
            'objectif_type' => 'perdre',
            'regime_alimentaire' => 'omnivore',
            'today' => '2026-09-16',
        ];

        $sans = $this->calc->compute($entree);
        $avec = $this->calc->compute($entree + ['rythme_intense' => true]);

        $this->assertSame(-500, $sans['ajustement_kcal']);
        $this->assertSame(-750, $avec['ajustement_kcal']);
        $this->assertSame(500, $avec['rythme_conseille_kcal']);
        $this->assertSame(750, $avec['rythme_intense_kcal']);
        $this->assertGreaterThanOrEqual($avec['plancher_kcal'], $avec['calories_recommandees']);
    }

    public function test_rien_a_proposer_quand_le_rythme_demande_est_deja_raisonnable(): void
    {
        $b = $this->calc->compute($this->presse(['poids_souhaite_kg' => 67, 'delai_objectif_jours' => 90]));

        $this->assertFalse($b['rythme_intense_possible']);
        $this->assertSame(-257, $b['ajustement_kcal']);
    }

    public function test_rien_a_proposer_hors_perte_de_poids(): void
    {
        $b = $this->calc->compute($this->presse([
            'objectif_type' => 'prendre',
            'poids_souhaite_kg' => 78,
            'delai_objectif_jours' => 60,
            'rythme_intense' => true,
        ]));

        $this->assertFalse($b['rythme_intense_possible']);
        $this->assertNull($b['rythme_conseille_kcal']);
        $this->assertSame(500, $b['ajustement_kcal']);
    }
}
