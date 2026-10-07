<?php

namespace Tests\Unit\Services;

use App\Services\NutritionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Rythme de perte sans plafond de vitesse : avec le consentement au rythme intense ET l'option,
 * le déficit n'est plus borné que par le seuil absolu (1 500 kcal pour un homme) — le plancher
 * habituel d'une perte, le métabolisme de base, ne s'applique plus. Le seuil absolu, jamais.
 *
 * Vecteur : homme 90 kg / 185 cm / 30 ans / très actif. Métabolisme 1 911 kcal, dépense
 * 3 631 kcal : marge jusqu'au seuil absolu 2 131 kcal/j. Conseillé 990, intense 1 000.
 */
class NutritionRythmeLibreTest extends TestCase
{
    private NutritionCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new NutritionCalculator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sportif(array $overrides = []): array
    {
        return array_replace([
            'sexe' => 'homme',
            'age' => 30,
            'taille' => 185,
            'poids' => 90,
            'poids_souhaite_kg' => 78,
            'delai_objectif_jours' => 60,
            'niveau_activite' => 'tres_eleve',
            'objectif_type' => 'perdre',
            'regime_alimentaire' => 'omnivore',
            'situation_particuliere' => 'aucune',
            'today' => '2026-10-07',
        ], $overrides);
    }

    public function test_l_option_est_proposee_avec_le_deficit_jusqu_au_plancher(): void
    {
        $b = $this->calc->compute($this->sportif());

        $this->assertTrue($b['rythme_intense_possible']);
        $this->assertTrue($b['rythme_libre_possible']);
        $this->assertFalse($b['rythme_libre_accepte']);
        $this->assertSame(1000, $b['rythme_intense_kcal']);
        $this->assertSame(2131, $b['rythme_libre_kcal']);
        $this->assertSame(1.94, $b['rythme_libre_kg_semaine']);
        $this->assertSame(1500, $b['rythme_libre_plancher_kcal']);
    }

    public function test_sans_le_consentement_au_rythme_intense_l_option_ne_fait_rien(): void
    {
        $b = $this->calc->compute($this->sportif(['rythme_libre' => true]));

        $this->assertFalse($b['rythme_libre_accepte']);
        $this->assertSame(-990, $b['ajustement_kcal']);
    }

    public function test_avec_les_deux_accords_l_objectif_demande_s_applique_sans_avertissement(): void
    {
        // 12 kg en 60 jours : 1 540 kcal/j, au-delà du plafond intense, sous la marge.
        $b = $this->calc->compute($this->sportif(['rythme_intense' => true, 'rythme_libre' => true]));

        $this->assertTrue($b['rythme_libre_accepte']);
        $this->assertSame(-1540, $b['ajustement_kcal']);
        // Cibles arrondies à la dizaine.
        $this->assertSame(2090, $b['calories_recommandees']);
        foreach ($b['avertissements'] as $avertissement) {
            $this->assertStringNotContainsString('Délai trop court', $avertissement);
        }
    }

    public function test_meme_sans_plafond_le_seuil_absolu_tient(): void
    {
        // 20 kg en 60 jours : 2 567 kcal/j demandés, plus que la marge de 2 131.
        $b = $this->calc->compute($this->sportif([
            'poids_souhaite_kg' => 70,
            'rythme_intense' => true,
            'rythme_libre' => true,
        ]));

        $this->assertSame(-2131, $b['ajustement_kcal']);
        $this->assertSame(1500, $b['calories_recommandees']);
        $this->assertSame(1500, $b['plancher_kcal']);
        $this->assertNotEmpty(array_filter(
            $b['avertissements'],
            fn ($a) => str_contains($a, 'Même sans plafond de vitesse') && str_contains($a, 'plancher de sécurité'),
        ));
    }

    /**
     * Sédentaire au métabolisme élevé : le plancher habituel (le métabolisme de base, 2 228 kcal)
     * ne laisse que 446 kcal/j de marge. C'est lui qui borne, pas le plafond de vitesse.
     */
    public function test_le_plancher_habituel_borne_et_l_avertissement_dit_le_vrai_chiffre(): void
    {
        $sedentaire = $this->sportif([
            'age' => 21,
            'taille' => 190,
            'poids' => 114,
            'poids_souhaite_kg' => 105,
            'niveau_activite' => 'sedentaire',
        ]);

        $b = $this->calc->compute($sedentaire);

        // Le rythme intense n'ouvre rien ici ; seul le rythme libre le peut, d'où la proposition.
        $this->assertTrue($b['rythme_intense_possible']);
        $this->assertTrue($b['rythme_libre_possible']);
        $this->assertSame(-446, $b['ajustement_kcal']);
        // Le message annonce le déficit réellement appliqué, pas le plafond théorique de 1 000.
        $this->assertNotEmpty(array_filter(
            $b['avertissements'],
            fn ($a) => str_starts_with($a, 'Délai trop court : objectif ramené à −446 kcal/j'),
        ));

        // Avec le rythme libre : 9 kg en 60 jours, soit 1 155 kcal/j, sous la marge de 1 173.
        $libre = $this->calc->compute(array_replace($sedentaire, ['rythme_intense' => true, 'rythme_libre' => true]));
        $this->assertTrue($libre['rythme_libre_accepte']);
        $this->assertSame(-1155, $libre['ajustement_kcal']);
        $this->assertSame(1500, $libre['plancher_kcal']);
    }

    public function test_un_delai_trop_court_propose_la_duree_qui_respecte_le_metabolisme(): void
    {
        // 9 kg ; marge de 445,5 kcal/j au-dessus du métabolisme : 156 jours, soit 22 semaines.
        $b = $this->calc->compute($this->sportif([
            'age' => 21,
            'taille' => 190,
            'poids' => 114,
            'poids_souhaite_kg' => 105,
            'niveau_activite' => 'sedentaire',
        ]));

        $this->assertSame(156, $b['delai_conseille_jours']);
        $this->assertSame('2027-03-12', $b['delai_conseille_fin']);
        $this->assertContains(
            'Pour perdre 9 kg en respectant ton métabolisme, compte environ 22 semaines (156 jours, jusqu’au 12 mars 2027).',
            $b['avertissements'],
        );

        // Ce délai-là, repris tel quel, ne déclenche plus ni « délai trop court » ni proposition.
        $suivi = $this->calc->compute($this->sportif([
            'age' => 21,
            'taille' => 190,
            'poids' => 114,
            'poids_souhaite_kg' => 105,
            'niveau_activite' => 'sedentaire',
            'delai_objectif_jours' => 156,
        ]));
        $this->assertNull($suivi['delai_conseille_jours']);
        foreach ($suivi['avertissements'] as $avertissement) {
            $this->assertStringNotContainsString('Délai trop court', $avertissement);
        }
    }

    public function test_une_prise_trop_rapide_propose_aussi_une_duree(): void
    {
        // 5 kg en 30 jours : 1 283 kcal/j demandés, plafond de prise 500 → 77 jours.
        $b = $this->calc->compute($this->sportif([
            'objectif_type' => 'prendre',
            'poids_souhaite_kg' => 95,
            'delai_objectif_jours' => 30,
        ]));

        $this->assertSame(77, $b['delai_conseille_jours']);
    }

    public function test_aucune_proposition_quand_le_delai_convient(): void
    {
        $b = $this->calc->compute($this->sportif(['poids_souhaite_kg' => 88]));

        $this->assertNull($b['delai_conseille_jours']);
        $this->assertNull($b['delai_conseille_fin']);
    }

    public function test_jamais_propose_a_65_ans_et_plus(): void
    {
        $b = $this->calc->compute($this->sportif(['age' => 65, 'rythme_intense' => true, 'rythme_libre' => true]));

        $this->assertFalse($b['rythme_libre_possible']);
        $this->assertFalse($b['rythme_libre_accepte']);
        $this->assertGreaterThanOrEqual(-750, $b['ajustement_kcal']);
    }

    public function test_jamais_propose_a_un_mineur(): void
    {
        $b = $this->calc->compute($this->sportif(['age' => 16, 'rythme_intense' => true, 'rythme_libre' => true]));

        $this->assertFalse($b['rythme_libre_possible']);
        $this->assertSame(0, $b['ajustement_kcal']);
    }
}
