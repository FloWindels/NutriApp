<?php

namespace Tests\Unit\Services;

use App\Services\Sport\CardioTemplates;
use App\Services\Sport\PrescriptionFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Sport v2 §4 : les modèles cardio produisent des blocs cohérents dont la durée totale tient
 * la durée demandée à ±10 %, avec des cibles chiffrées à chaque exercice.
 */
class CardioTemplatesTest extends TestCase
{
    private const GOALS = ['endurance', 'perte_de_gras', 'forme', 'prise_de_muscle', 'force'];

    private const LEVELS = ['debutant', 'intermediaire', 'avance'];

    /** @return array<string, mixed> */
    private function demande(string $goal, string $level, int $duration, int $seed = 0, bool $gentle = false): array
    {
        return ['goal' => $goal, 'level' => $level, 'duration_min' => $duration, 'seed' => $seed, 'gentle' => $gentle, 'zones_a_eviter' => []];
    }

    public function test_toutes_les_combinaisons_tiennent_la_duree_et_portent_des_cibles(): void
    {
        $templates = new CardioTemplates();
        $variantes = [];

        foreach (array_keys(CardioTemplates::ACTIVITES) as $activite) {
            foreach (self::GOALS as $goal) {
                foreach (self::LEVELS as $level) {
                    foreach ([10, 20, 30, 45, 60, 90] as $duration) {
                        foreach ([0, 1, 2] as $seed) {
                            $built = $templates->build($activite, $this->demande($goal, $level, $duration, $seed), 35);
                            $label = "$activite/$goal/$level/$duration/$seed ({$built['variante']})";
                            $variantes[$activite][$built['variante']] = true;

                            $cible = $duration * 60;
                            $this->assertEqualsWithDelta($cible, $built['duree_sec'], $cible * 0.10, "Durée hors tolérance : $label");

                            $this->assertSame(['echauffement', 'principal', 'retour_au_calme'], array_column($built['blocks'], 'key'));
                            foreach ($built['blocks'] as $block) {
                                $this->assertNotEmpty($block['exercises'], $label);
                                foreach ($block['exercises'] as $ex) {
                                    $p = $ex['prescription'];
                                    $this->assertContains($p['type'], ['cardio_continu', 'intervalles'], $label);
                                    $this->assertNotNull($p['cible'], "Cible absente : $label");
                                    $this->assertNotSame('', PrescriptionFormatter::resume($ex));
                                    $this->assertNotSame([], PrescriptionFormatter::etapes($ex));
                                    $this->assertGreaterThan(1.0, $ex['met'], $label);
                                    $this->assertLessThanOrEqual(30, $ex['sets'], $label);
                                    if ($activite !== 'natation') {
                                        $this->assertNotNull($p['cible']['zone_fc'], "Zone absente : $label");
                                        $this->assertNotNull($p['cible']['fc_bpm'], "Battements absents : $label");
                                    } else {
                                        $this->assertNotNull($p['distance_m'], "Distance absente : $label");
                                        $this->assertNotNull($p['cible']['rpe'], $label);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Chaque modèle annoncé est atteignable.
        $this->assertEqualsCanonicalizing(['endurance', 'seuil', 'fractionne', 'force'], array_keys($variantes['velo_interieur']));
        $this->assertEqualsCanonicalizing(['endurance', 'seuil', 'fractionne'], array_keys($variantes['rameur']));
        $this->assertEqualsCanonicalizing(['endurance', 'fractionne'], array_keys($variantes['natation']));
    }

    public function test_le_fractionne_velo_donne_cadence_resistance_et_zone(): void
    {
        $built = (new CardioTemplates())->build('velo_interieur', $this->demande('perte_de_gras', 'intermediaire', 45), 31);

        $this->assertSame('fractionne', $built['variante']);
        $principal = end($built['blocks'][1]['exercises']);
        $p = $principal['prescription'];

        $this->assertSame('intervalles', $p['type']);
        $this->assertSame(30, $p['effort_sec']);
        $this->assertSame(90, $p['recup_sec']);
        $this->assertSame([100, 110], $p['cible']['cadence_rpm']);
        $this->assertSame([7, 8], $p['cible']['resistance']);
        $this->assertSame(5, $p['cible']['zone_fc']);
        $this->assertSame([80, 85], $p['recup_cible']['cadence_rpm']);
        $this->assertSame([3, 3], $p['recup_cible']['resistance']);

        $resume = PrescriptionFormatter::resume($principal);
        $this->assertMatchesRegularExpression('/^\d+ × 30 s à 100–110 tr\/min · résistance 7–8\/10 · zone 5 \(167–186 bpm\) — récup 1 min 30 à 80–85 tr\/min, résistance 3\/10$/u', $resume);

        $etapes = PrescriptionFormatter::etapes($principal);
        $this->assertSame('100–110 tr/min · résistance 7–8/10 · 167–186 bpm', $etapes[0]['cible']);
        $this->assertSame('velo', $principal['movement']);
        $this->assertSame('velo', $principal['equipment']);
    }

    public function test_le_velo_de_route_se_regle_au_braquet_pas_a_la_resistance(): void
    {
        $built = (new CardioTemplates())->build('velo_route', $this->demande('perte_de_gras', 'avance', 60), 40);

        foreach ($built['blocks'] as $block) {
            foreach ($block['exercises'] as $ex) {
                $this->assertNull($ex['prescription']['cible']['resistance']);
                $this->assertNotNull($ex['prescription']['cible']['cadence_rpm']);
            }
        }
    }

    public function test_le_rameur_donne_coups_par_minute_et_temps_au_500m(): void
    {
        $built = (new CardioTemplates())->build('rameur', $this->demande('perte_de_gras', 'intermediaire', 40), 35);
        $p = end($built['blocks'][1]['exercises'])['prescription'];

        $this->assertSame([28, 32], $p['cible']['cadence_coups_min']);
        $this->assertSame([115, 122], $p['cible']['allure_500m_sec']);
        $this->assertStringContainsString('28–32 coups/min', PrescriptionFormatter::resume(end($built['blocks'][1]['exercises'])));
        $this->assertStringContainsString('1:55–2:02 /500 m', PrescriptionFormatter::resume(end($built['blocks'][1]['exercises'])));
    }

    public function test_tapis_de_course_en_km_h_et_course_dehors_au_ressenti(): void
    {
        $tapis = (new CardioTemplates())->build('course_tapis', $this->demande('endurance', 'intermediaire', 40), 35);
        $p = $tapis['blocks'][1]['exercises'][0]['prescription'];
        $this->assertEquals([9, 10], $p['cible']['vitesse_kmh']);
        $this->assertEquals([1, 1], $p['cible']['inclinaison_pct']);

        $dehors = (new CardioTemplates())->build('course', $this->demande('endurance', 'intermediaire', 40), 35);
        $p = $dehors['blocks'][1]['exercises'][0]['prescription'];
        $this->assertNull($p['cible']['vitesse_kmh']);
        $this->assertSame(2, $p['cible']['zone_fc']);
        $this->assertNotNull($p['cible']['rpe']);
    }

    public function test_une_situation_particuliere_reste_en_endurance(): void
    {
        $built = (new CardioTemplates())->build('velo_interieur', $this->demande('perte_de_gras', 'avance', 45, 0, true), 35);

        $this->assertSame('endurance', $built['variante']);
        $this->assertSame('faible', $built['intensity']);
    }

    public function test_un_debutant_en_course_alterne_course_et_marche(): void
    {
        $built = (new CardioTemplates())->build('course', $this->demande('forme', 'debutant', 30), null);
        $ex = $built['blocks'][1]['exercises'][0];

        $this->assertSame('Course / marche alternées', $ex['name']);
        $this->assertSame('intervalles', $ex['prescription']['type']);
        $this->assertNull($ex['prescription']['cible']['fc_bpm'], 'Sans âge, pas de battements.');
    }
}
