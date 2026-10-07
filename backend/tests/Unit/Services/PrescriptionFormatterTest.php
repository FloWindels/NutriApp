<?php

namespace Tests\Unit\Services;

use App\Services\Sport\HeartRateZones;
use App\Services\Sport\Prescription;
use App\Services\Sport\PrescriptionFormatter;
use App\Services\Sport\PrescriptionRules;
use PHPUnit\Framework\TestCase;

/**
 * Sport v2 §3 : la phrase lisible et les étapes minutées se calculent depuis la prescription,
 * et une ligne d'avant la v2 reste lisible — jamais « 10×30 1 min 52 ».
 */
class PrescriptionFormatterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function veloFractionne(?int $age = 31): array
    {
        return [
            'name' => 'Fractionné 10 × 30 s',
            'category' => 'cardio',
            'sets' => 10,
            'duration_sec' => 30,
            'rest_sec' => 90,
            'prescription' => Prescription::sanitize([
                'type' => 'intervalles',
                'repetitions' => 10,
                'effort_sec' => 30,
                'recup_sec' => 90,
                'cible' => ['cadence_rpm' => [100, 110], 'resistance' => [7, 8], 'zone_fc' => 4, 'rpe' => [8, 9]],
                'recup_cible' => ['cadence_rpm' => [80, 85], 'resistance' => [3, 3], 'zone_fc' => 2],
            ], $age),
        ];
    }

    public function test_le_resume_du_fractionne_velo_est_celui_du_contrat(): void
    {
        // 31 ans : FC max = 208 − 21,7 = 186 ; zone 4 = 80–90 % = 149–167 bpm.
        $this->assertSame(
            '10 × 30 s à 100–110 tr/min · résistance 7–8/10 · zone 4 (149–167 bpm) — récup 1 min 30 à 80–85 tr/min, résistance 3/10',
            PrescriptionFormatter::resume($this->veloFractionne())
        );
    }

    public function test_sans_age_la_zone_reste_sans_battements(): void
    {
        $resume = PrescriptionFormatter::resume($this->veloFractionne(null));

        $this->assertStringContainsString('· zone 4 — récup 1 min 30', $resume);
        $this->assertStringNotContainsString('bpm', $resume);
    }

    public function test_les_etapes_alternent_effort_et_recup_avec_les_cibles(): void
    {
        $etapes = PrescriptionFormatter::etapes($this->veloFractionne());

        $this->assertCount(19, $etapes, '10 efforts et 9 récupérations');
        $this->assertSame(['label' => 'Effort 1/10', 'type' => 'effort', 'duree_sec' => 30, 'cible' => '100–110 tr/min · résistance 7–8/10 · 149–167 bpm'], $etapes[0]);
        $this->assertSame('recup', $etapes[1]['type']);
        $this->assertSame(90, $etapes[1]['duree_sec']);
        $this->assertSame('80–85 tr/min · résistance 3/10 · 112–130 bpm', $etapes[1]['cible']);
        $this->assertSame('Effort 10/10', end($etapes)['label']);
        $this->assertSame(10 * 30 + 9 * 90, PrescriptionFormatter::dureeTotale($this->veloFractionne()));
    }

    public function test_les_lignes_sans_prescription_restent_lisibles(): void
    {
        $this->assertSame('3 séries de 12 répétitions · repos 1 min 30', PrescriptionFormatter::resume(['sets' => 3, 'reps' => 12, 'rest_sec' => 90]));
        $this->assertSame('10 séries de 30 s · repos 1 min 52', PrescriptionFormatter::resume(['sets' => 10, 'duration_sec' => 30, 'rest_sec' => 112]));
        $this->assertSame('8 min', PrescriptionFormatter::resume(['sets' => 1, 'duration_sec' => 480]));
        $this->assertSame('À ton rythme', PrescriptionFormatter::resume([]));

        $etapes = PrescriptionFormatter::etapes(['sets' => 3, 'reps' => 12, 'rest_sec' => 90]);
        $this->assertSame(['travail', 'repos', 'travail', 'repos', 'travail'], array_column($etapes, 'type'));
        $this->assertNull($etapes[0]['duree_sec'], 'Une série sans durée attend la validation.');
    }

    public function test_les_durees_sont_ecrites_en_francais(): void
    {
        $this->assertSame('30 s', PrescriptionFormatter::duree(30));
        $this->assertSame('1 min 30', PrescriptionFormatter::duree(90));
        $this->assertSame('1 min 05', PrescriptionFormatter::duree(65));
        $this->assertSame('8 min', PrescriptionFormatter::duree(480));
        $this->assertSame('1 h 15', PrescriptionFormatter::duree(4500));
        $this->assertSame('5,5–6', PrescriptionFormatter::plage([5.5, 6]));
    }

    public function test_une_serie_de_force_montre_tempo_reserve_et_repos(): void
    {
        $item = PrescriptionRules::complete(
            ['name' => 'Développé couché haltères', 'category' => 'force', 'equipment' => 'halteres', 'sets' => 3, 'reps' => 10, 'rest_sec' => 90],
            ['goal' => 'prise_de_muscle', 'level' => 'intermediaire', 'age' => 40],
        );

        $this->assertSame(2, $item['prescription']['rir']);
        $this->assertSame('3-0-1', $item['prescription']['tempo']);
        $this->assertSame('Une charge que tu pourrais soulever 12 fois ; arrête-toi à 10.', $item['prescription']['charge_conseil']);
        $this->assertSame('3 séries de 10 répétitions · tempo 3-0-1 · garde 2 répétitions en réserve · repos 1 min 30', PrescriptionFormatter::resume($item));

        $etapes = PrescriptionFormatter::etapes($item);
        $this->assertSame('Série 1/3', $etapes[0]['label']);
        $this->assertSame('travail', $etapes[0]['type']);
        $this->assertNull($etapes[0]['duree_sec']);
        $this->assertSame('10 répétitions · tempo 3-0-1 · 2 en réserve', $etapes[0]['cible']);
        $this->assertSame(['type' => 'repos', 'duree_sec' => 90], ['type' => $etapes[1]['type'], 'duree_sec' => $etapes[1]['duree_sec']]);
    }

    public function test_le_rir_suit_le_niveau(): void
    {
        $this->assertSame(3, PrescriptionRules::rirFor('debutant'));
        $this->assertSame(2, PrescriptionRules::rirFor('intermediaire'));
        $this->assertSame(1, PrescriptionRules::rirFor('avance'));
        $this->assertSame([4, 6], PrescriptionRules::repsFor('force'));
        $this->assertSame([8, 12], PrescriptionRules::repsFor('prise_de_muscle'));
        $this->assertSame([15, 20], PrescriptionRules::repsFor('endurance'));
    }

    public function test_zones_cardiaques_tanaka(): void
    {
        $this->assertSame(180, HeartRateZones::fcMax(40));
        $this->assertSame([144, 162], HeartRateZones::bpm(4, 40));
        $this->assertSame([90, 108], HeartRateZones::bpm(1, 40));
        $this->assertSame([162, 180], HeartRateZones::bpm(5, 40));
        $this->assertNull(HeartRateZones::bpm(3, null));
        $this->assertNull(HeartRateZones::bpm(3, 5));
        $this->assertNull(HeartRateZones::bpm(null, 40));
    }

    public function test_la_prescription_recue_est_nettoyee_et_les_bpm_recalcules(): void
    {
        $p = Prescription::sanitize([
            'type' => 'intervalles',
            'repetitions' => 99,
            'effort_sec' => 30,
            'cible' => ['cadence_rpm' => [110, 100], 'resistance' => 14, 'zone_fc' => 4, 'fc_bpm' => [1, 2], 'inconnu' => 'x'],
            'consigne' => '<script>alert(1)</script> Pédale fort',
            'pirate' => true,
        ], 40);

        $this->assertSame(30, $p['repetitions']);
        $this->assertSame([100, 110], $p['cible']['cadence_rpm']);
        $this->assertSame([10, 10], $p['cible']['resistance']);
        $this->assertSame([144, 162], $p['cible']['fc_bpm'], 'Jamais la valeur du client.');
        $this->assertArrayNotHasKey('inconnu', $p['cible']);
        $this->assertArrayNotHasKey('pirate', $p);
        $this->assertSame('alert(1) Pédale fort', $p['consigne']);
        $this->assertNull(Prescription::sanitize(['type' => 'inconnu']));
        $this->assertNull(Prescription::sanitize('texte'));
    }

    public function test_natation_en_distances(): void
    {
        $ex = ['sets' => 8, 'duration_sec' => 55, 'rest_sec' => 20, 'prescription' => Prescription::sanitize([
            'type' => 'intervalles', 'repetitions' => 8, 'effort_sec' => 55, 'recup_sec' => 20, 'distance_m' => 50,
            'cible' => ['rpe' => [7, 8]],
        ])];

        $this->assertSame('8 × 50 m · effort ressenti 7–8/10 — récup 20 s', PrescriptionFormatter::resume($ex));
        $this->assertSame('50 m · effort 7–8/10', PrescriptionFormatter::etapes($ex)[0]['cible']);
    }
}
