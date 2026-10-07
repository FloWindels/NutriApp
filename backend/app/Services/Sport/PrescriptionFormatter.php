<?php

namespace App\Services\Sport;

use App\Models\WorkoutExercise;

/**
 * Phrase lisible (`resume`) et étapes minutées (`etapes`) d'un exercice (Sport v2 §3), calculées
 * côté serveur depuis la prescription : une seule source de vérité, le site et l'application
 * affichent la même chose.
 *
 *   « 10 × 30 s à 100–110 tr/min · résistance 7–8/10 · zone 4 (150–167 bpm) — récup 1 min 30 à
 *     80–85 tr/min, résistance 3/10 »
 *
 * Les lignes enregistrées avant la v2 (sans prescription) gardent une phrase claire tirée des
 * séries / répétitions / durée / repos : « 3 séries de 12 répétitions · repos 1 min 30 ».
 * Jamais « 10×30 1 min 52 ».
 *
 * Étapes : `effort` / `recup` (cardio), `travail` / `repos` (séries). Une série sans durée
 * donne une étape `travail` dont `duree_sec` est null : le minuteur attend la validation.
 */
final class PrescriptionFormatter
{
    /** Cibles de rythme : la phrase les introduit par « à » (« 30 s à 100–110 tr/min »). */
    private const RYTHMES = ['cadence_rpm', 'cadence_coups_min', 'vitesse_kmh', 'allure_min_km', 'allure_500m_sec', 'puissance_w'];

    // ------------------------------------------------------------------------------------
    // Entrée : tableau de proposition ou ligne enregistrée
    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|WorkoutExercise  $ex
     * @return array{name: string, category: ?string, sets: ?int, reps: ?int, duration_sec: ?int, rest_sec: ?int, prescription: ?array<string, mixed>}
     */
    public static function fields(array|WorkoutExercise $ex, ?string $category = null): array
    {
        $get = fn (string $k) => $ex instanceof WorkoutExercise ? $ex->{$k} : ($ex[$k] ?? null);
        $int = fn ($v) => $v === null || $v === '' ? null : (int) $v;
        $prescription = $get('prescription');

        return [
            'name' => (string) ($get('name') ?? ''),
            'category' => $category ?? ($ex instanceof WorkoutExercise ? null : ($ex['category'] ?? null)),
            'sets' => $int($get('sets')),
            'reps' => $int($get('reps')),
            'duration_sec' => $int($get('duration_sec')),
            'rest_sec' => $int($get('rest_sec')),
            'prescription' => is_array($prescription) ? $prescription : null,
        ];
    }

    // ------------------------------------------------------------------------------------
    // Résumé
    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|WorkoutExercise  $ex
     */
    public static function resume(array|WorkoutExercise $ex, ?string $category = null): string
    {
        $f = self::fields($ex, $category);
        $p = $f['prescription'];

        if ($p === null) {
            return self::legacy($f);
        }

        return match ($p['type'] ?? null) {
            'intervalles' => self::resumeIntervalles($p, $f),
            'cardio_continu' => self::resumeContinu($p, $f),
            'force' => self::resumeForce($p, $f),
            default => self::resumeMobilite($p, $f),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $f
     */
    private static function resumeIntervalles(array $p, array $f): string
    {
        $n = (int) ($p['repetitions'] ?? $f['sets'] ?? 1);
        $effort = $p['effort_sec'] ?? $f['duration_sec'];
        $recup = $p['recup_sec'] ?? $f['rest_sec'];
        $distance = $p['distance_m'] ?? null;

        $unite = $distance !== null ? self::metres((int) $distance) : ($effort !== null ? self::duree((int) $effort) : 'effort');
        $tete = $n > 1 ? sprintf('%d × %s', $n, $unite) : $unite;

        $phrase = self::avecCibles($tete, self::ciblesResume($p['cible'] ?? null), ' · ');

        if ($recup !== null && (int) $recup > 0 && $n > 1) {
            $phrase .= ' — '.self::avecCibles('récup '.self::duree((int) $recup), self::ciblesResume($p['recup_cible'] ?? null, true), ', ');
        }

        return $phrase;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $f
     */
    private static function resumeContinu(array $p, array $f): string
    {
        $effort = $p['effort_sec'] ?? $f['duration_sec'];
        $distance = $p['distance_m'] ?? null;

        $tete = $distance !== null
            ? self::metres((int) $distance)
            : ($effort !== null ? self::duree((int) $effort) : 'À ton rythme');

        return self::avecCibles($tete, self::ciblesResume($p['cible'] ?? null), ' · ');
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $f
     */
    private static function resumeForce(array $p, array $f): string
    {
        $parts = [self::series($f['sets'], $f['reps'], $f['duration_sec'])];

        if (! empty($p['tempo'])) {
            $parts[] = 'tempo '.$p['tempo'];
        }
        if (isset($p['rir']) && $p['rir'] !== null) {
            $parts[] = self::rir((int) $p['rir']);
        }
        if ($f['rest_sec'] !== null && $f['rest_sec'] > 0 && ($f['sets'] ?? 1) > 1) {
            $parts[] = 'repos '.self::duree($f['rest_sec']);
        }

        return implode(' · ', array_filter($parts));
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $f
     */
    private static function resumeMobilite(array $p, array $f): string
    {
        $parts = [self::series($f['sets'], $f['reps'], $f['duration_sec'] ?? $p['effort_sec'] ?? null, true)];
        if ($f['rest_sec'] !== null && $f['rest_sec'] > 0 && ($f['sets'] ?? 1) > 1) {
            $parts[] = 'repos '.self::duree($f['rest_sec']);
        }

        return implode(' · ', array_filter($parts));
    }

    /**
     * Lignes sans prescription (avant la v2) : séries, répétitions ou durée, repos.
     *
     * @param  array<string, mixed>  $f
     */
    private static function legacy(array $f): string
    {
        $parts = [self::series($f['sets'], $f['reps'], $f['duration_sec'])];
        if ($f['rest_sec'] !== null && $f['rest_sec'] > 0) {
            $parts[] = 'repos '.self::duree($f['rest_sec']);
        }

        $phrase = implode(' · ', array_filter($parts));

        return $phrase !== '' ? $phrase : 'À ton rythme';
    }

    /**
     * « 3 séries de 12 répétitions », « 10 séries de 30 s », « 8 min », « 12 répétitions ».
     */
    private static function series(?int $sets, ?int $reps, ?int $duration, bool $compact = false): string
    {
        $unite = $reps !== null
            ? sprintf('%d répétition%s', $reps, $reps > 1 ? 's' : '')
            : ($duration !== null ? self::duree($duration) : null);

        if ($unite === null) {
            return $sets !== null && $sets > 1 ? sprintf('%d séries', $sets) : '';
        }
        if ($sets === null || $sets <= 1) {
            return $unite;
        }

        return $compact ? sprintf('%d × %s', $sets, $unite) : sprintf('%d séries de %s', $sets, $unite);
    }

    private static function rir(int $rir): string
    {
        return match (true) {
            $rir <= 0 => 'jusqu’à la dernière répétition propre',
            $rir === 1 => 'garde 1 répétition en réserve',
            default => sprintf('garde %d répétitions en réserve', $rir),
        };
    }

    /**
     * Tête de phrase suivie de ses cibles : « à » devant un rythme, « en » devant une zone.
     *
     * @param  list<array{0: string, 1: string}>  $cibles  [clé, texte]
     */
    private static function avecCibles(string $tete, array $cibles, string $separateur): string
    {
        if ($cibles === []) {
            return $tete;
        }

        $premiere = $cibles[0][0];
        $liaison = in_array($premiere, self::RYTHMES, true) || in_array($premiere, ['resistance', 'inclinaison_pct'], true)
            ? ' à '
            : ($premiere === 'zone_fc' ? ' en ' : ' · ');

        return $tete.$liaison.implode($separateur, array_column($cibles, 1));
    }

    /**
     * Cibles de la phrase : rythmes, résistance, pente, zone avec ses battements ; le RPE
     * seulement quand aucune zone n'est donnée. En récupération, la zone ne s'affiche que
     * faute d'autre repère : « récup 1 min 30 à 80–85 tr/min » se suffit.
     *
     * @param  array<string, mixed>|null  $cible
     * @return list<array{0: string, 1: string}>
     */
    private static function ciblesResume(?array $cible, bool $recup = false): array
    {
        if ($cible === null) {
            return [];
        }

        $out = self::ciblesCommunes($cible);
        if ($recup && $out !== []) {
            return $out;
        }

        if (isset($cible['zone_fc']) && $cible['zone_fc'] !== null) {
            $bpm = self::plageOuNull($cible['fc_bpm'] ?? null);
            $out[] = ['zone_fc', sprintf('zone %d', $cible['zone_fc']).($bpm !== null ? ' ('.$bpm.' bpm)' : '')];
        } elseif (($rpe = self::plageOuNull($cible['rpe'] ?? null)) !== null) {
            $out[] = ['rpe', 'effort ressenti '.$rpe.'/10'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $cible
     * @return list<array{0: string, 1: string}>
     */
    private static function ciblesCommunes(array $cible): array
    {
        $out = [];
        $formats = [
            'cadence_rpm' => fn ($r) => self::plage($r).' tr/min',
            'cadence_coups_min' => fn ($r) => self::plage($r).' coups/min',
            'vitesse_kmh' => fn ($r) => self::plage($r).' km/h',
            'allure_min_km' => fn ($r) => self::plageAllure($r).' /km',
            'allure_500m_sec' => fn ($r) => self::plageChrono($r).' /500 m',
            'puissance_w' => fn ($r) => self::plage($r).' W',
            'inclinaison_pct' => fn ($r) => 'pente '.self::plage($r).' %',
            'resistance' => fn ($r) => 'résistance '.self::plage($r).'/10',
        ];

        foreach ($formats as $key => $format) {
            if (is_array($cible[$key] ?? null) && $cible[$key] !== []) {
                $out[] = [$key, $format($cible[$key])];
            }
        }

        return $out;
    }

    /**
     * Cible d'une étape : « 100–110 tr/min · résistance 7–8/10 · 150–167 bpm ».
     *
     * @param  array<string, mixed>|null  $cible
     */
    private static function cibleEtape(?array $cible, ?int $distance = null): ?string
    {
        $parts = $distance !== null ? [self::metres($distance)] : [];

        if ($cible !== null) {
            $parts = array_merge($parts, array_column(self::ciblesCommunes($cible), 1));
            $bpm = self::plageOuNull($cible['fc_bpm'] ?? null);
            if ($bpm !== null) {
                $parts[] = $bpm.' bpm';
            } elseif (isset($cible['zone_fc']) && $cible['zone_fc'] !== null) {
                $parts[] = 'zone '.$cible['zone_fc'];
            } elseif (($rpe = self::plageOuNull($cible['rpe'] ?? null)) !== null) {
                $parts[] = 'effort '.$rpe.'/10';
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    // ------------------------------------------------------------------------------------
    // Étapes minutées
    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|WorkoutExercise  $ex
     * @return list<array{label: string, type: string, duree_sec: ?int, cible: ?string}>
     */
    public static function etapes(array|WorkoutExercise $ex, ?string $category = null, ?string $block = null): array
    {
        $f = self::fields($ex, $category);
        $p = $f['prescription'];
        $type = $p['type'] ?? null;

        if ($type === 'intervalles' || ($p === null && $f['category'] === 'cardio' && $f['duration_sec'] !== null && ($f['sets'] ?? 1) > 1)) {
            return self::etapesIntervalles($p ?? [], $f);
        }
        if ($type === 'cardio_continu' || ($p === null && $f['category'] === 'cardio' && $f['duration_sec'] !== null)) {
            $duree = $p['effort_sec'] ?? $f['duration_sec'];
            $distance = isset($p['distance_m']) ? (int) $p['distance_m'] : null;

            return [[
                'label' => $f['name'] !== '' ? $f['name'] : 'Effort continu',
                'type' => $block === 'retour_au_calme' ? 'recup' : 'effort',
                'duree_sec' => $duree !== null ? (int) $duree : null,
                'cible' => self::cibleEtape($p['cible'] ?? null, $distance),
            ]];
        }

        return self::etapesSeries($p, $f);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $f
     * @return list<array{label: string, type: string, duree_sec: ?int, cible: ?string}>
     */
    private static function etapesIntervalles(array $p, array $f): array
    {
        $n = max(1, (int) ($p['repetitions'] ?? $f['sets'] ?? 1));
        $effort = $p['effort_sec'] ?? $f['duration_sec'];
        $recup = $p['recup_sec'] ?? $f['rest_sec'];
        $distance = isset($p['distance_m']) ? (int) $p['distance_m'] : null;
        $cible = self::cibleEtape($p['cible'] ?? null, $distance);
        $cibleRecup = self::cibleEtape($p['recup_cible'] ?? null);

        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'label' => $n > 1 ? sprintf('Effort %d/%d', $i, $n) : 'Effort',
                'type' => 'effort',
                'duree_sec' => $effort !== null ? (int) $effort : null,
                'cible' => $cible,
            ];
            if ($i < $n && $recup !== null && (int) $recup > 0) {
                $out[] = [
                    'label' => sprintf('Récup %d/%d', $i, $n - 1),
                    'type' => 'recup',
                    'duree_sec' => (int) $recup,
                    'cible' => $cibleRecup,
                ];
            }
        }

        return $out;
    }

    /**
     * Séries de force, de gainage ou de mobilité : travail (minuté ou à valider) puis repos.
     *
     * @param  array<string, mixed>|null  $p
     * @param  array<string, mixed>  $f
     * @return list<array{label: string, type: string, duree_sec: ?int, cible: ?string}>
     */
    private static function etapesSeries(?array $p, array $f): array
    {
        $sets = max(1, (int) ($f['sets'] ?? 1));
        $duree = $f['duration_sec'] ?? ($p['effort_sec'] ?? null);

        $parts = [];
        if ($f['reps'] !== null) {
            $parts[] = sprintf('%d répétition%s', $f['reps'], $f['reps'] > 1 ? 's' : '');
        }
        if (! empty($p['tempo'])) {
            $parts[] = 'tempo '.$p['tempo'];
        }
        if (isset($p['rir']) && $p['rir'] !== null) {
            $parts[] = sprintf('%d en réserve', $p['rir']);
        }
        $cible = $parts === [] ? null : implode(' · ', $parts);

        $out = [];
        for ($i = 1; $i <= $sets; $i++) {
            $out[] = [
                'label' => $sets > 1 ? sprintf('Série %d/%d', $i, $sets) : ($f['name'] !== '' ? $f['name'] : 'Série'),
                'type' => 'travail',
                'duree_sec' => $f['reps'] === null && $duree !== null ? (int) $duree : null,
                'cible' => $cible,
            ];
            if ($i < $sets && $f['rest_sec'] !== null && $f['rest_sec'] > 0) {
                $out[] = ['label' => 'Repos', 'type' => 'repos', 'duree_sec' => (int) $f['rest_sec'], 'cible' => null];
            }
        }

        return $out;
    }

    /**
     * Durée totale des étapes (secondes) ; une série à valider compte ses répétitions à 3 s.
     *
     * @param  array<string, mixed>|WorkoutExercise  $ex
     */
    public static function dureeTotale(array|WorkoutExercise $ex, ?string $category = null): int
    {
        $f = self::fields($ex, $category);
        $total = 0;
        foreach (self::etapes($ex, $category) as $etape) {
            $total += $etape['duree_sec'] ?? max(1, (int) ($f['reps'] ?? 10)) * CaloriesEstimator::SECONDS_PER_REP;
        }

        return $total;
    }

    // ------------------------------------------------------------------------------------
    // Nombres et durées en français
    // ------------------------------------------------------------------------------------

    /**
     * 30 → « 30 s », 90 → « 1 min 30 », 480 → « 8 min », 4500 → « 1 h 15 ».
     */
    public static function duree(int $sec): string
    {
        $sec = max(0, $sec);
        if ($sec < 60) {
            return $sec.' s';
        }
        if ($sec >= 3600) {
            $h = intdiv($sec, 3600);
            $m = intdiv($sec % 3600, 60);

            return $m === 0 ? $h.' h' : sprintf('%d h %02d', $h, $m);
        }

        $m = intdiv($sec, 60);
        $s = $sec % 60;

        return $s === 0 ? $m.' min' : sprintf('%d min %02d', $m, $s);
    }

    private static function metres(int $m): string
    {
        return $m >= 1000 && $m % 100 === 0
            ? self::nombre($m / 1000).' km'
            : $m.' m';
    }

    /**
     * [100, 110] → « 100–110 », [3, 3] → « 3 », [5.5, 6] → « 5,5–6 ».
     *
     * @param  array<int, int|float>  $r
     */
    public static function plage(array $r): string
    {
        $a = $r[0] ?? null;
        $b = $r[1] ?? $a;
        if ($a === null) {
            return '';
        }

        return (float) $a === (float) $b ? self::nombre($a) : self::nombre($a).'–'.self::nombre($b);
    }

    private static function plageOuNull(mixed $r): ?string
    {
        return is_array($r) && $r !== [] && ($r[0] ?? null) !== null ? self::plage($r) : null;
    }

    /**
     * Allure en minutes décimales → « 5:30–6:00 ».
     *
     * @param  array<int, int|float>  $r
     */
    private static function plageAllure(array $r): string
    {
        $fmt = function (float $min): string {
            $total = (int) round($min * 60);

            return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
        };
        $a = (float) $r[0];
        $b = (float) ($r[1] ?? $a);

        return $a === $b ? $fmt($a) : $fmt($a).'–'.$fmt($b);
    }

    /**
     * Secondes → « 2:05–2:10 ».
     *
     * @param  array<int, int|float>  $r
     */
    private static function plageChrono(array $r): string
    {
        $fmt = fn (float $s): string => sprintf('%d:%02d', intdiv((int) round($s), 60), ((int) round($s)) % 60);
        $a = (float) $r[0];
        $b = (float) ($r[1] ?? $a);

        return $a === $b ? $fmt($a) : $fmt($a).'–'.$fmt($b);
    }

    private static function nombre(int|float $v): string
    {
        if ((float) $v === floor((float) $v)) {
            return (string) (int) $v;
        }

        return str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));
    }
}
