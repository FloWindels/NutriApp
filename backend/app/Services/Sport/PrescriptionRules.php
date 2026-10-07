<?php

namespace App\Services\Sport;

/**
 * Prescriptions par règles (Sport v2 §4, volet force) et complétion des exercices qui n'en ont
 * pas — ceux du circuit par règles comme ceux choisis par l'IA.
 *
 * - force : répétitions selon l'objectif (force 4–6, prise de muscle 8–12, endurance 15–20,
 *   perte de gras 12–15, forme 10–12), répétitions en réserve selon le niveau (débutant 3,
 *   intermédiaire 2, avancé 1 ; une de plus en situation particulière), tempo, repos, conseil
 *   de charge (« une charge que tu pourrais soulever 12 fois ; arrête-toi à 10 ») ;
 * - cardio d'un circuit : intervalles effort / récupération avec zone et ressenti ;
 * - mobilité : maintien calme, sans douleur.
 *
 * Ce que l'IA a fourni (rir, tempo, consigne) est gardé s'il est valide ; le reste est complété.
 */
final class PrescriptionRules
{
    /** Répétitions [min, max] par objectif. */
    public const REPS_BY_GOAL = [
        'force' => [4, 6],
        'prise_de_muscle' => [8, 12],
        'endurance' => [15, 20],
        'perte_de_gras' => [12, 15],
        'forme' => [10, 12],
    ];

    /** Repos entre séries (s) par objectif ; +15 s pour un débutant. */
    public const REST_BY_GOAL = [
        'force' => 150,
        'prise_de_muscle' => 90,
        'endurance' => 45,
        'perte_de_gras' => 45,
        'forme' => 60,
    ];

    /** Tempo descente-pause-montée (s) par objectif. */
    public const TEMPO_BY_GOAL = [
        'force' => '3-1-1',
        'prise_de_muscle' => '3-0-1',
        'endurance' => '2-0-1',
        'perte_de_gras' => '2-0-1',
        'forme' => '2-0-2',
    ];

    public const RIR_BY_LEVEL = ['debutant' => 3, 'intermediaire' => 2, 'avance' => 1];

    /** Matériel sans charge réglable : le conseil porte sur la variante, pas sur le poids. */
    private const SANS_CHARGE = ['aucun', 'tapis', 'elastiques', 'trx', 'swiss_ball', 'step'];

    private const CONSIGNE_BY_GOAL = [
        'force' => 'Charge lourde, technique parfaite : pousse fort sur la montée, contrôle la descente.',
        'prise_de_muscle' => 'Descente lente et contrôlée, le muscle reste sous tension tout du long.',
        'endurance' => 'Rythme régulier, respiration continue : enchaîne sans te crisper.',
        'perte_de_gras' => 'Rythme soutenu mais propre : la qualité du geste passe avant la vitesse.',
        'forme' => 'Mouvement ample et maîtrisé, respire sur l’effort.',
    ];

    /**
     * @return array{0: int, 1: int}
     */
    public static function repsFor(string $goal): array
    {
        return self::REPS_BY_GOAL[$goal] ?? self::REPS_BY_GOAL['forme'];
    }

    public static function restFor(string $goal, string $level): int
    {
        return (self::REST_BY_GOAL[$goal] ?? 60) + ($level === 'debutant' ? 15 : 0);
    }

    public static function rirFor(string $level, bool $gentle = false): int
    {
        return min(4, (self::RIR_BY_LEVEL[$level] ?? 2) + ($gentle ? 1 : 0));
    }

    /**
     * Complète la prescription d'un exercice de proposition, puis la nettoie et calcule les
     * fréquences cardiaques pour l'âge donné.
     *
     * @param  array<string, mixed>  $item  Exercice de proposition (category, sets, reps, duration_sec, rest_sec, intensity, equipment…)
     * @param  array{goal?: string, level?: string, gentle?: bool, age?: int|null, block?: string}  $ctx
     * @return array<string, mixed>  L'exercice avec `prescription`
     */
    public static function complete(array $item, array $ctx): array
    {
        $existing = is_array($item['prescription'] ?? null) ? $item['prescription'] : null;
        $age = isset($ctx['age']) ? (int) $ctx['age'] : null;

        if ($existing === null || ! in_array($existing['type'] ?? null, Prescription::TYPES, true)) {
            $existing = self::build($item, $ctx, $existing ?? []);
        }

        $item['prescription'] = Prescription::sanitize($existing, $age);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $hints  rir / tempo / consigne déjà proposés (IA)
     * @return array<string, mixed>
     */
    private static function build(array $item, array $ctx, array $hints): array
    {
        $goal = (string) ($ctx['goal'] ?? 'forme');
        $level = (string) ($ctx['level'] ?? 'debutant');
        $gentle = (bool) ($ctx['gentle'] ?? false);
        $block = (string) ($ctx['block'] ?? 'principal');
        $category = (string) ($item['category'] ?? 'force');
        $sets = isset($item['sets']) ? (int) $item['sets'] : 1;
        $duration = isset($item['duration_sec']) ? (int) $item['duration_sec'] : null;
        $rest = isset($item['rest_sec']) ? (int) $item['rest_sec'] : null;
        $intensity = (string) ($item['intensity'] ?? 'moderee');
        $consigne = $hints['consigne'] ?? null;

        if ($category === 'mobilite') {
            return [
                'type' => 'mobilite',
                'effort_sec' => $duration,
                'consigne' => $consigne ?? 'Respire lentement et relâche ; l’amplitude vient sans forcer, jamais dans la douleur.',
            ];
        }

        if ($category === 'cardio') {
            $zone = match (true) {
                $block !== 'principal' || $intensity === 'faible' => 2,
                $intensity === 'elevee' => 4,
                default => 3,
            };
            $rpe = [2 => [3, 4], 3 => [5, 6], 4 => [7, 8]][$zone];

            if ($sets <= 1 || $duration === null) {
                return [
                    'type' => 'cardio_continu',
                    'effort_sec' => $duration,
                    'cible' => ['zone_fc' => $zone, 'rpe' => $rpe],
                    'consigne' => $consigne ?? 'Monte en intensité progressivement, respiration ample.',
                ];
            }

            return [
                'type' => 'intervalles',
                'repetitions' => $sets,
                'effort_sec' => $duration,
                'recup_sec' => $rest,
                'cible' => ['zone_fc' => $zone, 'rpe' => $rpe],
                'recup_cible' => ['zone_fc' => 1, 'rpe' => [2, 3]],
                'consigne' => $consigne ?? 'Effort franc sur toute la durée, mais garde un geste propre ; récupère en marchant ou sur place.',
            ];
        }

        // Force et gainage.
        $timed = ($item['reps'] ?? null) === null && $duration !== null;
        if ($timed || $category === 'gainage') {
            return [
                'type' => 'force',
                'effort_sec' => $duration,
                'rir' => null,
                'tempo' => null,
                'cible' => ['rpe' => $gentle ? [5, 6] : [6, 8]],
                'consigne' => $consigne ?? 'Corps aligné de la tête aux talons, respiration régulière : arrête la série si le bas du dos se creuse.',
            ];
        }

        $rir = isset($hints['rir']) && is_numeric($hints['rir']) ? (int) $hints['rir'] : self::rirFor($level, $gentle);
        $tempo = $hints['tempo'] ?? self::TEMPO_BY_GOAL[$goal] ?? '2-0-2';
        $reps = isset($item['reps']) ? (int) $item['reps'] : self::repsFor($goal)[1];

        return [
            'type' => 'force',
            'rir' => $rir,
            'tempo' => $tempo,
            'cible' => ['rpe' => [max(1, 9 - $rir), 10 - $rir]],
            'charge_conseil' => self::chargeConseil((string) ($item['equipment'] ?? 'aucun'), $reps, $rir),
            'consigne' => $consigne ?? self::CONSIGNE_BY_GOAL[$goal] ?? self::CONSIGNE_BY_GOAL['forme'],
        ];
    }

    public static function chargeConseil(string $equipment, int $reps, int $rir): string
    {
        if (in_array($equipment, self::SANS_CHARGE, true)) {
            return $rir <= 0
                ? sprintf('Au poids du corps : va jusqu’à la dernière répétition propre (%d visées).', $reps)
                : sprintf('Au poids du corps : si tu termines les %d répétitions avec plus de %d en réserve, ralentis la descente ou passe à une variante plus difficile.', $reps, $rir);
        }

        return sprintf(
            'Une charge que tu pourrais soulever %d fois ; arrête-toi à %d.',
            $reps + $rir,
            $reps
        );
    }
}
