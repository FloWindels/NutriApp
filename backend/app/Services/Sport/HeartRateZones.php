<?php

namespace App\Services\Sport;

/**
 * Zones cardiaques personnalisées (Sport v2 §3).
 *
 * FC max estimée par la formule de Tanaka (208 − 0,7 × âge), plus juste que « 220 − âge » après
 * 40 ans. Zones en pourcentage de la FC max : Z1 50–60 %, Z2 60–70 %, Z3 70–80 %, Z4 80–90 %,
 * Z5 90–100 %. Sans âge plausible, pas de battements : la zone et le ressenti (RPE) suffisent,
 * mieux vaut aucune valeur qu'une valeur fausse.
 */
final class HeartRateZones
{
    /** @var array<int, array{0: int, 1: int}> */
    public const ZONES_PCT = [
        1 => [50, 60],
        2 => [60, 70],
        3 => [70, 80],
        4 => [80, 90],
        5 => [90, 100],
    ];

    /** Libellés courts des zones, pour les explications. */
    public const NOMS = [
        1 => 'récupération',
        2 => 'endurance fondamentale',
        3 => 'tempo',
        4 => 'seuil',
        5 => 'VO2max',
    ];

    public const AGE_MIN = 10;

    public const AGE_MAX = 100;

    public static function fcMax(?int $age): ?int
    {
        if ($age === null || $age < self::AGE_MIN || $age > self::AGE_MAX) {
            return null;
        }

        return (int) round(208 - 0.7 * $age);
    }

    /**
     * Battements par minute d'une zone, ou null sans âge connu.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function bpm(?int $zone, ?int $age): ?array
    {
        $max = self::fcMax($age);
        if ($max === null || $zone === null || ! isset(self::ZONES_PCT[$zone])) {
            return null;
        }

        [$bas, $haut] = self::ZONES_PCT[$zone];

        return [(int) round($max * $bas / 100), (int) round($max * $haut / 100)];
    }
}
