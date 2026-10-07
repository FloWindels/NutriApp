<?php

namespace App\Services\Sport;

/**
 * Prescription structurée d'un exercice (Sport v2 §3) : nettoyage d'une prescription reçue
 * (proposition IA, client) et calcul des fréquences cardiaques.
 *
 * Tout ce qui entre est filtré par liste blanche : clés connues, nombres bornés, plages
 * remises dans l'ordre, textes raccourcis et sans balise. `fc_bpm` n'est JAMAIS repris du
 * client : il est recalculé depuis `zone_fc` et l'âge du profil (HeartRateZones).
 *
 * Forme : {type, repetitions, effort_sec, recup_sec, distance_m, cible, recup_cible, rir,
 * tempo, charge_conseil, consigne}. `distance_m` (natation : longueur d'une répétition, ou de
 * la nage continue) complète le contrat du § 3, toutes les autres clés en viennent.
 */
final class Prescription
{
    public const TYPES = ['force', 'cardio_continu', 'intervalles', 'mobilite'];

    /**
     * Clés de cible : [min, max, décimales]. Une plage [a, b] ou un nombre seul (→ [a, a]).
     *
     * @var array<string, array{0: float, 1: float, 2: int}>
     */
    public const CIBLE_PLAGES = [
        'cadence_rpm' => [20, 200, 0],
        'resistance' => [1, 10, 0],
        'puissance_w' => [10, 2000, 0],
        'vitesse_kmh' => [0.5, 40, 1],
        'allure_min_km' => [2, 20, 2],
        'inclinaison_pct' => [-5, 25, 1],
        'cadence_coups_min' => [10, 60, 0],
        'allure_500m_sec' => [60, 400, 0],
        'rpe' => [1, 10, 0],
    ];

    /** Ordre canonique des clés de cible (affichage et JSON). */
    public const CIBLE_CLES = [
        'cadence_rpm', 'resistance', 'puissance_w', 'vitesse_kmh', 'allure_min_km', 'inclinaison_pct',
        'cadence_coups_min', 'allure_500m_sec', 'zone_fc', 'fc_bpm', 'rpe',
    ];

    /**
     * Nettoie une prescription ; null si elle est absente ou de type inconnu.
     * Les fréquences cardiaques sont recalculées pour `$age` (null : aucune).
     *
     * @return array<string, mixed>|null
     */
    public static function sanitize(mixed $raw, ?int $age = null): ?array
    {
        if (! is_array($raw) || ! in_array($raw['type'] ?? null, self::TYPES, true)) {
            return null;
        }

        $out = [
            'type' => $raw['type'],
            'repetitions' => self::int($raw['repetitions'] ?? null, 1, 30),
            'effort_sec' => self::int($raw['effort_sec'] ?? null, 1, 10800),
            'recup_sec' => self::int($raw['recup_sec'] ?? null, 0, 1800),
            'distance_m' => self::int($raw['distance_m'] ?? null, 1, 20000),
            'cible' => self::cible($raw['cible'] ?? null, $age),
            'recup_cible' => self::cible($raw['recup_cible'] ?? null, $age),
            'rir' => self::int($raw['rir'] ?? null, 0, 5),
            'tempo' => self::tempo($raw['tempo'] ?? null),
            'charge_conseil' => self::text($raw['charge_conseil'] ?? null, 200),
            'consigne' => self::text($raw['consigne'] ?? null, 300),
        ];

        return $out;
    }

    /**
     * Recalcule `fc_bpm` d'une prescription déjà propre (âge du profil au moment voulu).
     *
     * @param  array<string, mixed>|null  $prescription
     * @return array<string, mixed>|null
     */
    public static function withHeartRate(?array $prescription, ?int $age): ?array
    {
        if ($prescription === null) {
            return null;
        }

        foreach (['cible', 'recup_cible'] as $key) {
            if (is_array($prescription[$key] ?? null)) {
                $zone = $prescription[$key]['zone_fc'] ?? null;
                $prescription[$key]['fc_bpm'] = HeartRateZones::bpm(is_int($zone) ? $zone : null, $age);
            }
        }

        return $prescription;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function cible(mixed $raw, ?int $age): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $out = [];
        foreach (self::CIBLE_CLES as $key) {
            $out[$key] = null;
        }

        foreach (self::CIBLE_PLAGES as $key => [$min, $max, $decimals]) {
            $out[$key] = self::range($raw[$key] ?? null, $min, $max, $decimals);
        }
        $out['zone_fc'] = self::int($raw['zone_fc'] ?? null, 1, 5);
        $out['fc_bpm'] = HeartRateZones::bpm($out['zone_fc'], $age);

        if (array_filter($out, fn ($v) => $v !== null) === []) {
            return null;
        }

        return $out;
    }

    /**
     * @return array{0: int|float, 1: int|float}|null
     */
    private static function range(mixed $value, float $min, float $max, int $decimals): ?array
    {
        if (is_numeric($value)) {
            $value = [$value, $value];
        }
        if (! is_array($value)) {
            return null;
        }

        $values = array_values(array_filter(array_values($value), 'is_numeric'));
        if ($values === []) {
            return null;
        }

        $a = (float) $values[0];
        $b = (float) ($values[1] ?? $values[0]);
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        $a = max($min, min($max, $a));
        $b = max($min, min($max, $b));

        $cast = fn (float $v): int|float => $decimals === 0 ? (int) round($v) : round($v, $decimals);

        return [$cast($a), $cast($b)];
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) max($min, min($max, (int) round((float) $value)));
    }

    private static function tempo(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{1,2}(-\d{1,2}){2,3}$/', $value) === 1 ? $value : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
