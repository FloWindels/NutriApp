<?php

namespace App\Services\Sport;

/**
 * Modèles d'entraînement cardio (Sport v2 §4) : la séance d'endurance se construit depuis des
 * formats reconnus, choisis selon objectif × niveau × durée, avec des cibles chiffrées.
 *
 * - Vélo (home trainer ou route) : échauffement 85–90 tr/min résistance 3–4 ; endurance Z2
 *   85–95 tr/min ; seuil (ex. 3 × 8 min Z4 90–95 tr/min, récup 4 min Z1) ; fractionné court
 *   (ex. 10 × 30 s Z5 100–110 tr/min résistance 7–8, récup 1 min 30 à 80–85 tr/min résistance 3) ;
 *   force-vélo (5 × 3 min 60–70 tr/min résistance 8). Sur route, pas de résistance : braquet.
 * - Course : endurance Z2 (débutant : alternance course / marche), seuil Z4, 30/30 Z5, côtes ;
 *   zones + ressenti + test de la parole dehors (le profil ne donne pas d'allure de référence),
 *   vitesse en km/h sur tapis de course.
 * - Marche : vitesse en km/h, et pente sur tapis de course (marche inclinée).
 * - Rameur : 22–24 coups/min en endurance, 28–32 en fractionné, temps au 500 m par niveau.
 * - Elliptique : cadence et résistance chiffrées.
 * - Natation : distances (ex. 8 × 50 m), récupération en secondes, ressenti (pas de zone :
 *   un cardiofréquencemètre se lit mal dans l'eau).
 * - Corde à sauter : fractionné court au ressenti.
 *
 * Durée : échauffement + bloc principal + retour au calme = durée demandée (le reliquat d'un
 * fractionné devient un bloc d'endurance s'il dépasse 2 min, sinon il allonge le retour au calme).
 * Déterministe : même demande + même graine → même séance ; la graine fait tourner les variantes.
 */
final class CardioTemplates
{
    /** Activité → [matériel de l'exercice, motif d'illustration, slug du catalogue des sports]. */
    public const ACTIVITES = [
        'velo_interieur' => ['velo', 'velo', 'velo'],
        'velo_route' => ['velo_route', 'velo', 'velo'],
        'course' => ['aucun', 'course', 'course-a-pied'],
        'course_tapis' => ['tapis_course', 'course', 'course-a-pied'],
        'marche' => ['aucun', 'marche', 'marche'],
        'marche_tapis' => ['tapis_course', 'marche', 'marche'],
        'rameur' => ['rameur', 'rameur', 'rameur'],
        'elliptique' => ['elliptique', 'velo', 'elliptique'],
        'natation' => ['piscine', 'nage', 'natation'],
        'corde' => ['corde_a_sauter', 'saut', 'corde-a-sauter'],
    ];

    public const LIBELLES = [
        'velo_interieur' => 'vélo d’intérieur',
        'velo_route' => 'vélo',
        'course' => 'course à pied',
        'course_tapis' => 'course sur tapis',
        'marche' => 'marche',
        'marche_tapis' => 'marche sur tapis',
        'rameur' => 'rameur',
        'elliptique' => 'elliptique',
        'natation' => 'natation',
        'corde' => 'corde à sauter',
    ];

    /** Variantes possibles par activité. */
    private const VARIANTES = [
        'velo_interieur' => ['endurance', 'seuil', 'fractionne', 'force'],
        'velo_route' => ['endurance', 'seuil', 'fractionne', 'force'],
        'course' => ['endurance', 'seuil', 'fractionne', 'force'],
        'course_tapis' => ['endurance', 'seuil', 'fractionne', 'force'],
        'marche' => ['endurance', 'fractionne'],
        'marche_tapis' => ['endurance', 'fractionne', 'force'],
        'rameur' => ['endurance', 'seuil', 'fractionne'],
        'elliptique' => ['endurance', 'seuil', 'fractionne', 'force'],
        'natation' => ['endurance', 'fractionne'],
        'corde' => ['fractionne'],
    ];

    /** Ordre de préférence des variantes : objectif → niveau → liste. */
    private const PREFERENCES = [
        'endurance' => ['debutant' => ['endurance'], 'intermediaire' => ['endurance', 'seuil'], 'avance' => ['seuil', 'endurance', 'fractionne']],
        'perte_de_gras' => ['debutant' => ['endurance', 'fractionne'], 'intermediaire' => ['fractionne', 'seuil', 'endurance'], 'avance' => ['fractionne', 'seuil']],
        'forme' => ['debutant' => ['endurance', 'fractionne'], 'intermediaire' => ['endurance', 'fractionne', 'seuil'], 'avance' => ['seuil', 'fractionne', 'endurance']],
        'prise_de_muscle' => ['debutant' => ['endurance'], 'intermediaire' => ['force', 'fractionne'], 'avance' => ['force', 'fractionne', 'seuil']],
        'force' => ['debutant' => ['endurance'], 'intermediaire' => ['force', 'fractionne'], 'avance' => ['force', 'fractionne', 'seuil']],
    ];

    private const NOMS_VARIANTES = [
        'endurance' => 'endurance fondamentale',
        'seuil' => 'travail au seuil',
        'fractionne' => 'fractionné court',
        'force' => 'force',
    ];

    /** Vitesses de course (km/h) par niveau et zone, pour le tapis et les distances. */
    private const VITESSE_COURSE = [
        'debutant' => [1 => [6, 7], 2 => [7.5, 8.5], 3 => [8.5, 9.5], 4 => [9, 10], 5 => [10, 11]],
        'intermediaire' => [1 => [7, 8], 2 => [9, 10], 3 => [10, 11], 4 => [11, 12], 5 => [12.5, 14]],
        'avance' => [1 => [8, 9], 2 => [10.5, 11.5], 3 => [12, 13], 4 => [13, 14], 5 => [15, 16.5]],
    ];

    private const VITESSE_MARCHE = [
        'debutant' => [1 => [4, 4.5], 2 => [5, 5.5], 3 => [5.5, 6]],
        'intermediaire' => [1 => [4.5, 5], 2 => [5.5, 6], 3 => [6, 6.5]],
        'avance' => [1 => [5, 5.5], 2 => [6, 6.5], 3 => [6.5, 7]],
    ];

    /** Rameur : temps au 500 m (s) par niveau et intensité. */
    private const SPLIT_RAMEUR = [
        'debutant' => ['recup' => [180, 195], 'endurance' => [165, 180], 'seuil' => [150, 160], 'fractionne' => [140, 150]],
        'intermediaire' => ['recup' => [155, 170], 'endurance' => [140, 150], 'seuil' => [125, 132], 'fractionne' => [115, 122]],
        'avance' => ['recup' => [140, 150], 'endurance' => [120, 130], 'seuil' => [110, 116], 'fractionne' => [100, 108]],
    ];

    /** Natation : secondes au 100 m en endurance, et pour un 50 m rapide. */
    private const NAGE = [
        'debutant' => ['p100' => 150, 'p50_rapide' => 70, 'recup' => 30, 'longueur' => 50],
        'intermediaire' => ['p100' => 120, 'p50_rapide' => 55, 'recup' => 20, 'longueur' => 100],
        'avance' => ['p100' => 95, 'p50_rapide' => 45, 'recup' => 15, 'longueur' => 100],
    ];

    /** Vélo de route : vitesse moyenne (km/h) par zone, multipliée selon le niveau. */
    private const VITESSE_VELO = [1 => 18, 2 => 24, 3 => 27, 4 => 30, 5 => 33];

    private const FACTEUR_NIVEAU = ['debutant' => 0.85, 'intermediaire' => 1.0, 'avance' => 1.12];

    /** Reliquat (s) au-delà duquel le temps restant devient un bloc d'endurance. */
    private const RELIQUAT_MIN = 120;

    /**
     * Variantes possibles pour cette demande, dans l'ordre de préférence.
     *
     * @param  array<string, mixed>  $b  goal, level, duration_min, gentle, zones_a_eviter
     * @return list<string>
     */
    public static function variantesPour(string $activite, array $b): array
    {
        $level = (string) $b['level'];
        $possibles = self::VARIANTES[$activite] ?? ['endurance'];

        if ($level === 'debutant' || in_array('genoux', (array) ($b['zones_a_eviter'] ?? []), true)) {
            $possibles = array_values(array_diff($possibles, ['force']));
        }
        // Moins de 20 min : pas la place pour des blocs de seuil ou de force de plusieurs minutes.
        if ((int) $b['duration_min'] < 20 && count(array_diff($possibles, ['seuil', 'force'])) > 0) {
            $possibles = array_values(array_diff($possibles, ['seuil', 'force']));
        }

        $prefs = ($b['gentle'] ?? false)
            ? ['endurance']
            : (self::PREFERENCES[$b['goal']] ?? self::PREFERENCES['forme'])[$level] ?? ['endurance'];

        if ((int) $b['duration_min'] <= 20 && $level !== 'debutant' && ! ($b['gentle'] ?? false)) {
            $prefs = array_values(array_unique(array_merge(['fractionne'], $prefs)));
        }

        $out = array_values(array_intersect($prefs, $possibles));

        return $out !== [] ? $out : [$possibles[0]];
    }

    /**
     * @param  array<string, mixed>  $b  goal, level, duration_min, gentle, zones_a_eviter, seed
     * @return array{blocks: list<array<string, mixed>>, explication: list<string>, intensity: string, variante: string, activite: string, duree_sec: int}
     */
    public function build(string $activite, array $b, ?int $age): array
    {
        $activite = isset(self::ACTIVITES[$activite]) ? $activite : 'course';
        $level = in_array($b['level'] ?? null, ['debutant', 'intermediaire', 'avance'], true) ? $b['level'] : 'debutant';
        $b['level'] = $level;
        $duration = max(10, min(180, (int) ($b['duration_min'] ?? 30)));
        $b['duration_min'] = $duration;

        $variantes = self::variantesPour($activite, $b);
        $variante = $variantes[((int) ($b['seed'] ?? 0)) % count($variantes)];

        $warm = match (true) {
            $duration < 20 => 180,
            $duration < 30 => 300,
            $duration < 45 => 480,
            default => 600,
        };
        $cool = $duration < 20 ? 120 : ($duration < 30 ? 180 : 300);
        $main = max(240, $duration * 60 - $warm - $cool);

        $phases = $this->principal($activite, $variante, $level, $main, (bool) ($b['gentle'] ?? false));

        // Garde-fou : un bloc qui ne tient pas dans le temps principal perd des répétitions.
        foreach ($phases as &$phase) {
            while ($phase['kind'] === 'intervalles' && $phase['n'] > 1 && $this->dureePhase($phase) > $main) {
                $phase['n']--;
            }
            if ($phase['kind'] === 'intervalles' && $this->dureePhase($phase) > $main && $phase['distance_m'] === null) {
                $phase['effort'] = $main;
            }
        }
        unset($phase);

        // Le temps non couvert par le fractionné : bloc d'endurance s'il compte, sinon retour au calme.
        $reliquat = $main - array_sum(array_map(fn (array $p) => $this->dureePhase($p), $phases));
        if ($reliquat >= self::RELIQUAT_MIN && $variante !== 'endurance') {
            array_unshift($phases, $this->endurance($activite, $level, $reliquat, false, 'Bloc d’endurance'));
        } elseif ($reliquat > 0) {
            $cool += $reliquat;
        }

        $echauffement = $this->echauffement($activite, $level, $warm);
        $retour = $this->retourAuCalme($activite, $level, $cool);

        $meta = self::ACTIVITES[$activite];
        $triplet = SportVocab::metTripletForSlug($meta[2]) ?? ['faible' => 4.0, 'moderee' => 6.0, 'elevee' => 8.0];
        $toItem = fn (array $p) => $this->item($activite, $level, $p, $triplet, $age);

        $blocks = [
            ['key' => 'echauffement', 'name' => WorkoutGenerator::BLOCK_NAMES['echauffement'], 'exercises' => [$toItem($echauffement)]],
            ['key' => 'principal', 'name' => WorkoutGenerator::BLOCK_NAMES['principal'], 'exercises' => array_map($toItem, $phases)],
            ['key' => 'retour_au_calme', 'name' => WorkoutGenerator::BLOCK_NAMES['retour_au_calme'], 'exercises' => [$toItem($retour)]],
        ];

        $zoneMax = 1;
        foreach ($phases as $p) {
            $zoneMax = max($zoneMax, (int) ($p['zone'] ?? 2));
        }

        $total = 0;
        foreach ($blocks as $block) {
            foreach ($block['exercises'] as $ex) {
                $total += PrescriptionFormatter::dureeTotale($ex);
            }
        }

        return [
            'blocks' => $blocks,
            'explication' => $this->explication($activite, $variante, $level, $phases, $warm, $cool, $main, $age),
            'intensity' => $zoneMax >= 4 ? 'elevee' : ($zoneMax === 3 ? 'moderee' : 'faible'),
            'variante' => $variante,
            'activite' => $activite,
            'duree_sec' => $total,
        ];
    }

    // ------------------------------------------------------------------------------------
    // Bloc principal par variante
    // ------------------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function principal(string $a, string $variante, string $level, int $main, bool $gentle): array
    {
        if ($a === 'corde') {
            return [$this->corde($level, $main, $gentle)];
        }
        if ($a === 'natation') {
            return $variante === 'fractionne' ? [$this->nageFractionne($level, $main)] : $this->nageEndurance($level, $main);
        }

        return match ($variante) {
            'seuil' => [$this->seuil($a, $level, $main)],
            'fractionne' => [$this->fractionne($a, $level, $main)],
            'force' => [$this->force($a, $level, $main)],
            default => $this->enduranceVariante($a, $level, $main, $gentle),
        };
    }

    /**
     * Endurance ; pour un débutant en course, alternance course / marche.
     *
     * @return list<array<string, mixed>>
     */
    private function enduranceVariante(string $a, string $level, int $main, bool $gentle): array
    {
        if (in_array($a, ['course', 'course_tapis'], true) && ($level === 'debutant' || $gentle)) {
            $e = 180;
            $r = 90;
            $n = $this->repetitions($main, $e, $r, 2, 15);

            return [$this->intervalles(
                'Course / marche alternées',
                $n, $e, $r,
                $this->cibleCourse($a, $level, 2, [4, 5]),
                $this->cibleMarche($a === 'course_tapis' ? 'marche_tapis' : 'marche', $level, 1, [2, 3]),
                'Trottine à allure de conversation, puis marche d’un bon pas pour récupérer. Tu dois pouvoir parler en phrases complètes.',
                2,
                1,
            )];
        }

        return [$this->endurance($a, $level, $main, $gentle)];
    }

    /**
     * @return array<string, mixed>
     */
    private function endurance(string $a, string $level, int $sec, bool $gentle, ?string $nom = null): array
    {
        $rpe = $gentle ? [3, 4] : [4, 5];
        $nom ??= 'Endurance fondamentale';

        return match ($a) {
            'velo_interieur', 'velo_route' => $this->continu($nom, $sec, $this->cibleVelo($a, [85, 95], ['debutant' => [3, 4], 'intermediaire' => [4, 5], 'avance' => [5, 6]][$level], 2, $rpe),
                'Pédalage rond et régulier, buste relâché : tu dois pouvoir tenir une conversation.', 2),
            'course', 'course_tapis' => $this->continu($nom, $sec, $this->cibleCourse($a, $level, 2, $rpe),
                'Allure de conversation : si tu ne peux plus parler en phrases complètes, ralentis.', 2),
            'marche', 'marche_tapis' => $this->continu($nom, $sec, $this->cibleMarche($a, $level, 2, $rpe),
                'Marche d’un bon pas, bras qui balancent, sans te tenir aux poignées.', 2),
            'rameur' => $this->continu($nom, $sec, [
                'cadence_coups_min' => [22, 24],
                'allure_500m_sec' => self::SPLIT_RAMEUR[$level]['endurance'],
                'resistance' => [4, 5],
                'zone_fc' => 2,
                'rpe' => $rpe,
            ], 'Jambes, dos puis bras à la poussée ; bras, dos puis jambes au retour, deux fois plus lent que la poussée.', 2),
            'elliptique' => $this->continu($nom, $sec, [
                'cadence_rpm' => [60, 70],
                'resistance' => ['debutant' => [3, 4], 'intermediaire' => [4, 5], 'avance' => [5, 6]][$level],
                'zone_fc' => 2,
                'rpe' => $rpe,
            ], 'Pousse dans les talons, buste droit, bras actifs sur les poignées mobiles.', 2),
            'natation' => $this->nageContinue($level, $sec, $nom),
            default => $this->continu($nom, $sec, ['zone_fc' => 2, 'rpe' => $rpe], 'Effort régulier et confortable.', 2),
        };
    }

    /**
     * Seuil : n blocs en zone 4 (zone 3 pour un débutant), récupération en zone 1.
     *
     * @return array<string, mixed>
     */
    private function seuil(string $a, string $level, int $main): array
    {
        [$e, $r] = ['debutant' => [300, 180], 'intermediaire' => [480, 240], 'avance' => [600, 180]][$level];
        if (in_array($a, ['course', 'course_tapis', 'rameur', 'elliptique'], true)) {
            [$e, $r] = ['debutant' => [300, 120], 'intermediaire' => [360, 120], 'avance' => [480, 120]][$level];
        }
        $zone = $level === 'debutant' ? 3 : 4;
        $rpe = $level === 'debutant' ? [6, 7] : [7, 8];

        // Un bloc principal court resserre la durée des blocs plutôt que d'en faire un seul.
        $n = $this->repetitions($main, $e, $r, 1, 5);
        if ($n < 2) {
            $e = max(120, (int) (floor(($main - $r) / 2 / 30) * 30));
            $n = $this->repetitions($main, $e, $r, 1, 5);
        }

        [$cible, $recup, $consigne] = match ($a) {
            'velo_interieur', 'velo_route' => [
                $this->cibleVelo($a, [90, 95], $level === 'avance' ? [7, 8] : [6, 7], $zone, $rpe),
                $this->cibleVelo($a, [85, 90], [3, 3], 1, [2, 3]),
                'Effort soutenu mais tenable jusqu’au bout du bloc : respiration forte, phrases courtes seulement.',
            ],
            'course', 'course_tapis' => [
                $this->cibleCourse($a, $level, $zone, $rpe),
                $this->cibleCourse($a, $level, 1, [2, 3]),
                'Allure « confortablement difficile » : tu ne peux dire que quelques mots. Récupère en trottinant.',
            ],
            'rameur' => [
                ['cadence_coups_min' => [24, 26], 'allure_500m_sec' => self::SPLIT_RAMEUR[$level]['seuil'], 'resistance' => [5, 6], 'zone_fc' => $zone, 'rpe' => $rpe],
                ['cadence_coups_min' => [18, 20], 'allure_500m_sec' => self::SPLIT_RAMEUR[$level]['recup'], 'resistance' => [4, 4], 'zone_fc' => 1],
                'Garde le même temps au 500 m sur tout le bloc ; la puissance vient des jambes.',
            ],
            default => [
                ['cadence_rpm' => [70, 75], 'resistance' => [6, 7], 'zone_fc' => $zone, 'rpe' => $rpe],
                ['cadence_rpm' => [60, 65], 'resistance' => [3, 3], 'zone_fc' => 1],
                'Effort soutenu et régulier, sans t’appuyer sur les poignées fixes.',
            ],
        };

        return $this->intervalles(sprintf('Seuil %d × %s', $n, PrescriptionFormatter::duree($e)), $n, $e, $r, $cible, $recup, $consigne, $zone, 1);
    }

    /**
     * Fractionné court : sprints en zone 5 (zone 4 pour un débutant), récupération active.
     *
     * @return array<string, mixed>
     */
    private function fractionne(string $a, string $level, int $main): array
    {
        $zone = $level === 'debutant' ? 4 : 5;

        switch ($a) {
            case 'velo_interieur':
            case 'velo_route':
                [$e, $r, $max] = ['debutant' => [30, 90, 8], 'intermediaire' => [30, 90, 10], 'avance' => [40, 80, 12]][$level];
                $cible = $this->cibleVelo($a, $level === 'debutant' ? [95, 105] : [100, 110], ['debutant' => [6, 7], 'intermediaire' => [7, 8], 'avance' => [8, 9]][$level], $zone, $level === 'debutant' ? [7, 8] : [8, 9]);
                $recup = $this->cibleVelo($a, [80, 85], [3, 3], 2, [3, 4]);
                $consigne = 'Accélère franchement dès le top, reste assis et gainé ; en récup, mouline sans résistance.';
                break;
            case 'course':
            case 'course_tapis':
                if ($level === 'debutant') {
                    [$e, $r, $max] = [60, 120, 8];
                    $consigne = 'Une minute rapide mais contrôlée, puis deux minutes de marche active pour retrouver ton souffle.';
                    $recup = $this->cibleMarche($a === 'course_tapis' ? 'marche_tapis' : 'marche', $level, 1, [2, 3]);
                } else {
                    [$e, $r, $max] = [30, 30, $level === 'avance' ? 20 : 16];
                    $consigne = '30/30 : 30 s vite (pas un sprint maximal), 30 s en trottinant. Garde la même vitesse sur toutes les répétitions.';
                    $recup = $this->cibleCourse($a, $level, 1, [3, 4]);
                }
                $cible = $this->cibleCourse($a, $level, $zone, $level === 'debutant' ? [7, 8] : [8, 9]);
                break;
            case 'marche':
            case 'marche_tapis':
                [$e, $r, $max] = [120, 120, 12];
                $cible = $this->cibleMarche($a, $level, 3, [6, 7]);
                $recup = $this->cibleMarche($a, $level, 2, [3, 4]);
                $consigne = 'Deux minutes de marche très rapide, bras actifs, puis deux minutes à allure normale.';
                $zone = 3;
                break;
            case 'rameur':
                [$e, $r, $max] = ['debutant' => [60, 90, 6], 'intermediaire' => [60, 60, 8], 'avance' => [60, 60, 10]][$level];
                $cible = ['cadence_coups_min' => [28, 32], 'allure_500m_sec' => self::SPLIT_RAMEUR[$level]['fractionne'], 'resistance' => [5, 6], 'zone_fc' => $zone, 'rpe' => [8, 9]];
                $recup = ['cadence_coups_min' => [18, 20], 'allure_500m_sec' => self::SPLIT_RAMEUR[$level]['recup'], 'resistance' => [4, 4], 'zone_fc' => 2];
                $consigne = 'Coups puissants et rapides, jambes d’abord ; en récup, rame lentement sans lâcher la poignée.';
                break;
            default: // elliptique
                [$e, $r, $max] = ['debutant' => [45, 90, 8], 'intermediaire' => [60, 90, 10], 'avance' => [60, 60, 12]][$level];
                $cible = ['cadence_rpm' => $level === 'debutant' ? [70, 80] : [75, 85], 'resistance' => $level === 'debutant' ? [6, 7] : [7, 8], 'zone_fc' => $zone, 'rpe' => [8, 9]];
                $recup = ['cadence_rpm' => [60, 65], 'resistance' => [3, 3], 'zone_fc' => 2];
                $consigne = 'Accélère et pousse fort dans les talons ; en récup, garde un mouvement souple.';
        }

        $n = $this->repetitions($main, $e, $r, 3, $max);

        return $this->intervalles(sprintf('Fractionné %d × %s', $n, PrescriptionFormatter::duree($e)), $n, $e, $r, $cible, $recup, $consigne, $zone, 2);
    }

    /**
     * Force : vélo à basse cadence et forte résistance, côtes en course, marche inclinée,
     * elliptique en résistance haute.
     *
     * @return array<string, mixed>
     */
    private function force(string $a, string $level, int $main): array
    {
        switch ($a) {
            case 'velo_interieur':
            case 'velo_route':
                [$e, $r, $max] = $level === 'avance' ? [240, 120, 6] : [180, 120, 5];
                $cible = $this->cibleVelo($a, [60, 70], $level === 'avance' ? [8, 9] : [8, 8], 3, [7, 8]);
                $recup = $this->cibleVelo($a, [85, 90], [3, 3], 1, [2, 3]);
                $nom = 'Force-vélo';
                $consigne = $a === 'velo_route'
                    ? 'En côte ou sur un grand braquet, assis, en poussant fort sur chaque coup de pédale ; genoux dans l’axe.'
                    : 'Résistance haute, cadence basse : pousse fort et rond, assis, genoux dans l’axe. Arrête en cas de gêne au genou.';
                $zone = 3;
                break;
            case 'course':
            case 'course_tapis':
                [$e, $r, $max] = $level === 'avance' ? [60, 120, 10] : [45, 90, 8];
                $cible = $this->cibleCourse($a, $level, 5, [8, 9]);
                if ($a === 'course_tapis') {
                    $cible['inclinaison_pct'] = [6, 8];
                    $cible['vitesse_kmh'] = self::VITESSE_COURSE[$level][3];
                }
                $recup = $a === 'course_tapis' ? $this->cibleMarche('marche_tapis', $level, 1, [2, 3]) : ['zone_fc' => 1, 'rpe' => [2, 3]];
                $nom = 'Côtes';
                $consigne = 'Monte en petites foulées dynamiques, buste légèrement penché, bras actifs ; redescends en marchant ou en trottinant.';
                $zone = 5;
                break;
            case 'marche_tapis':
                [$e, $r, $max] = [240, 120, 8];
                $cible = $this->cibleMarche($a, $level, 3, [6, 7]) + [];
                $cible['inclinaison_pct'] = ['debutant' => [5, 7], 'intermediaire' => [8, 10], 'avance' => [10, 12]][$level];
                $recup = $this->cibleMarche($a, $level, 2, [3, 4]);
                $recup['inclinaison_pct'] = [1, 2];
                $nom = 'Marche inclinée';
                $consigne = 'Pente forte, pas réguliers, sans te tenir aux poignées : les fessiers et les mollets travaillent.';
                $zone = 3;
                break;
            default: // elliptique
                [$e, $r, $max] = [180, 120, 6];
                $cible = ['cadence_rpm' => [55, 60], 'resistance' => [8, 9], 'zone_fc' => 3, 'rpe' => [7, 8]];
                $recup = ['cadence_rpm' => [60, 65], 'resistance' => [3, 3], 'zone_fc' => 1];
                $nom = 'Résistance haute';
                $consigne = 'Résistance forte, mouvement lent et appuyé, poussée dans les talons.';
                $zone = 3;
        }

        $n = $this->repetitions($main, $e, $r, 3, $max);

        return $this->intervalles(sprintf('%s %d × %s', $nom, $n, PrescriptionFormatter::duree($e)), $n, $e, $r, $cible, $recup, $consigne, $zone, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function corde(string $level, int $main, bool $gentle): array
    {
        [$e, $r, $max, $zone, $rpe] = $gentle || $level === 'debutant'
            ? [30, 60, 12, 4, [6, 7]]
            : ($level === 'intermediaire' ? [45, 45, 16, 4, [7, 8]] : [60, 30, 20, 5, [8, 9]]);
        $n = $this->repetitions($main, $e, $r, 3, $max);

        return $this->intervalles(
            sprintf('Corde à sauter %d × %s', $n, PrescriptionFormatter::duree($e)),
            $n, $e, $r,
            ['zone_fc' => $zone, 'rpe' => $rpe],
            ['zone_fc' => 2, 'rpe' => [2, 3]],
            'Petits bonds sur l’avant des pieds, coudes près du corps ; en récup, marche sur place et relâche les épaules.',
            $zone,
            2,
        );
    }

    /**
     * Natation, endurance : répétitions de 50 m (débutant) ou 100 m, récupération courte.
     *
     * @return list<array<string, mixed>>
     */
    private function nageEndurance(string $level, int $main): array
    {
        $p = self::NAGE[$level];
        $longueur = $p['longueur'];
        $e = (int) round($p['p100'] * $longueur / 100);
        $n = $this->repetitions($main, $e, $p['recup'], 2, 30);

        return [$this->intervalles(
            sprintf('Nage %d × %d m', $n, $longueur),
            $n, $e, $p['recup'],
            ['rpe' => [5, 6]],
            null,
            'Nage souple et régulière (crawl ou brasse), expire dans l’eau ; garde le même temps sur chaque longueur.',
            2,
            1,
            $longueur,
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function nageFractionne(string $level, int $main): array
    {
        $p = self::NAGE[$level];
        $n = $this->repetitions($main, $p['p50_rapide'], $p['recup'], 3, 20);

        return $this->intervalles(
            sprintf('Nage %d × 50 m rapides', $n),
            $n, $p['p50_rapide'], $p['recup'],
            ['rpe' => [7, 8]],
            null,
            'Chaque 50 m vite mais avec une nage propre ; récupère au bord, debout dans l’eau.',
            4,
            1,
            50,
        );
    }

    /**
     * Nage continue d'une distance arrondie à 50 m (échauffement, bloc d'endurance, retour au calme).
     *
     * @return array<string, mixed>
     */
    private function nageContinue(string $level, int $sec, string $nom, array $rpe = [3, 4], string $consigne = 'Nage souple, en alternant crawl, brasse et dos si tu veux.'): array
    {
        $p100 = self::NAGE[$level]['p100'] * 1.1;
        $distance = max(50, (int) (round($sec / $p100 * 100 / 50) * 50));

        return $this->continu($nom, (int) round($distance * $p100 / 100), ['rpe' => $rpe], $consigne, 1, $distance);
    }

    // ------------------------------------------------------------------------------------
    // Échauffement et retour au calme
    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function echauffement(string $a, string $level, int $sec): array
    {
        $nom = 'Échauffement progressif';
        $consigne = 'Commence très facile et monte doucement vers la zone 2 sur les dernières minutes.';

        return match ($a) {
            'velo_interieur', 'velo_route' => $this->continu($nom, $sec, $this->cibleVelo($a, [85, 90], [3, 4], 1, [2, 3]), $consigne, 1),
            'course', 'course_tapis' => $this->continu($level === 'debutant' ? 'Marche rapide puis trot léger' : 'Footing très lent', $sec, $this->cibleCourse($a, $level, 1, [2, 3]),
                'Commence en marchant si besoin, puis trottine très lentement ; ajoute quelques montées de genoux à la fin.', 1),
            'marche', 'marche_tapis' => $this->continu('Marche tranquille', $sec, $this->cibleMarche($a, $level, 1, [2, 3]), $consigne, 1),
            'rameur' => $this->continu($nom, $sec, ['cadence_coups_min' => [18, 20], 'allure_500m_sec' => self::SPLIT_RAMEUR[$level]['recup'], 'resistance' => [3, 4], 'zone_fc' => 1, 'rpe' => [2, 3]],
                'Rame d’abord avec les bras seuls, puis bras et dos, puis le mouvement complet.', 1),
            'elliptique' => $this->continu($nom, $sec, ['cadence_rpm' => [55, 60], 'resistance' => [2, 3], 'zone_fc' => 1, 'rpe' => [2, 3]], $consigne, 1),
            'natation' => $this->nageContinue($level, $sec, 'Échauffement souple', [3, 4], 'Nage lente en variant les nages, puis quelques longueurs de jambes avec planche si tu en as une.'),
            default => $this->continu('Sautillements et mobilité des chevilles', $sec, ['zone_fc' => 1, 'rpe' => [3, 4]],
                'Corde lente, petits rebonds souples, puis cercles de chevilles et de poignets.', 1),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function retourAuCalme(string $a, string $level, int $sec): array
    {
        $nom = 'Retour au calme';
        $consigne = 'Ralentis progressivement jusqu’à respirer normalement, puis étire doucement mollets, cuisses et hanches.';

        return match ($a) {
            'velo_interieur', 'velo_route' => $this->continu($nom, $sec, $this->cibleVelo($a, [80, 90], [2, 3], 1, [1, 2]), $consigne, 1),
            'course', 'course_tapis' => $this->continu('Marche de récupération', $sec, $this->cibleMarche($a === 'course_tapis' ? 'marche_tapis' : 'marche', $level, 1, [1, 2]), $consigne, 1),
            'marche', 'marche_tapis' => $this->continu('Marche lente', $sec, $this->cibleMarche($a, $level, 1, [1, 2]), $consigne, 1),
            'rameur' => $this->continu($nom, $sec, ['cadence_coups_min' => [18, 20], 'resistance' => [3, 3], 'zone_fc' => 1, 'rpe' => [1, 2]], $consigne, 1),
            'elliptique' => $this->continu($nom, $sec, ['cadence_rpm' => [55, 60], 'resistance' => [1, 2], 'zone_fc' => 1, 'rpe' => [1, 2]], $consigne, 1),
            'natation' => $this->nageContinue($level, $sec, 'Nage de récupération', [2, 3], 'Nage très lente en dos ou en brasse, longues expirations.'),
            default => $this->continu('Marche et étirements des mollets', $sec, ['zone_fc' => 1, 'rpe' => [1, 2]], $consigne, 1),
        };
    }

    // ------------------------------------------------------------------------------------
    // Cibles
    // ------------------------------------------------------------------------------------

    /**
     * Vélo : sur route, pas de résistance (le braquet et le terrain la remplacent).
     *
     * @param  array{0: int, 1: int}  $cadence
     * @param  array{0: int, 1: int}  $resistance
     * @param  array{0: int, 1: int}  $rpe
     * @return array<string, mixed>
     */
    private function cibleVelo(string $a, array $cadence, array $resistance, int $zone, array $rpe): array
    {
        $cible = ['cadence_rpm' => $cadence, 'resistance' => $resistance, 'zone_fc' => $zone, 'rpe' => $rpe];
        if ($a === 'velo_route') {
            unset($cible['resistance']);
        }

        return $cible;
    }

    /**
     * Course : vitesse et pente de 1 % sur tapis ; dehors, zone et ressenti.
     *
     * @param  array{0: int, 1: int}  $rpe
     * @return array<string, mixed>
     */
    private function cibleCourse(string $a, string $level, int $zone, array $rpe): array
    {
        $cible = ['zone_fc' => $zone, 'rpe' => $rpe];
        if ($a === 'course_tapis') {
            $cible = ['vitesse_kmh' => self::VITESSE_COURSE[$level][$zone], 'inclinaison_pct' => [1, 1]] + $cible;
        }

        return $cible;
    }

    /**
     * @param  array{0: int, 1: int}  $rpe
     * @return array<string, mixed>
     */
    private function cibleMarche(string $a, string $level, int $zone, array $rpe): array
    {
        $cible = ['vitesse_kmh' => self::VITESSE_MARCHE[$level][min(3, $zone)], 'zone_fc' => $zone, 'rpe' => $rpe];
        if ($a === 'marche_tapis') {
            $cible = ['vitesse_kmh' => $cible['vitesse_kmh'], 'inclinaison_pct' => [1, 1]] + $cible;
        }

        return $cible;
    }

    // ------------------------------------------------------------------------------------
    // Phases → exercices de proposition
    // ------------------------------------------------------------------------------------

    /**
     * Nombre de répétitions qui tient dans le temps donné (n efforts, n − 1 récupérations).
     */
    private function repetitions(int $sec, int $effort, int $recup, int $min, int $max): int
    {
        return (int) max($min, min($max, floor(($sec + $recup) / max(1, $effort + $recup))));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function dureePhase(array $p): int
    {
        return $p['kind'] === 'continu'
            ? (int) $p['sec']
            : $p['n'] * $p['effort'] + ($p['n'] - 1) * $p['recup'];
    }

    /**
     * @param  array<string, mixed>  $cible
     * @return array<string, mixed>
     */
    private function continu(string $nom, int $sec, array $cible, string $consigne, int $zone, ?int $distance = null): array
    {
        return ['kind' => 'continu', 'name' => $nom, 'sec' => $sec, 'cible' => $cible, 'consigne' => $consigne, 'zone' => $zone, 'distance_m' => $distance];
    }

    /**
     * @param  array<string, mixed>  $cible
     * @param  array<string, mixed>|null  $recup
     * @return array<string, mixed>
     */
    private function intervalles(string $nom, int $n, int $effort, int $recupSec, array $cible, ?array $recup, string $consigne, int $zone, int $zoneRecup, ?int $distance = null): array
    {
        return [
            'kind' => 'intervalles', 'name' => $nom, 'n' => $n, 'effort' => $effort, 'recup' => $recupSec,
            'cible' => $cible, 'recup_cible' => $recup, 'consigne' => $consigne, 'zone' => $zone,
            'zone_recup' => $zoneRecup, 'distance_m' => $distance,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array{faible: float, moderee: float, elevee: float}  $triplet
     * @return array<string, mixed>
     */
    private function item(string $a, string $level, array $p, array $triplet, ?int $age): array
    {
        [$equipment, $movement] = self::ACTIVITES[$a];

        if ($p['kind'] === 'continu') {
            $prescription = [
                'type' => 'cardio_continu',
                'effort_sec' => $p['sec'],
                'distance_m' => $p['distance_m'],
                'cible' => $p['cible'],
                'consigne' => $p['consigne'],
            ];
            $sets = 1;
            $duration = (int) $p['sec'];
            $rest = null;
            $met = $this->metZone($triplet, $p['zone']);
            $km = $this->km($a, $level, $p['cible'], $p['zone'], $duration, $p['distance_m']);
        } else {
            $prescription = [
                'type' => 'intervalles',
                'repetitions' => $p['n'],
                'effort_sec' => $p['effort'],
                'recup_sec' => $p['recup'],
                'distance_m' => $p['distance_m'],
                'cible' => $p['cible'],
                'recup_cible' => $p['recup_cible'],
                'consigne' => $p['consigne'],
            ];
            $sets = $p['n'];
            $duration = (int) $p['effort'];
            $rest = (int) $p['recup'];
            $tEffort = $p['n'] * $p['effort'];
            $tRecup = ($p['n'] - 1) * $p['recup'];
            $met = ($tEffort * $this->metZone($triplet, $p['zone']) + $tRecup * $this->metZone($triplet, $p['zone_recup']))
                / max(1, $tEffort + $tRecup);
            $kmEffort = $this->km($a, $level, $p['cible'], $p['zone'], $tEffort, $p['distance_m'] !== null ? $p['distance_m'] * $p['n'] : null);
            $kmRecup = $p['distance_m'] !== null ? 0.0 : $this->km($a, $level, $p['recup_cible'] ?? [], $p['zone_recup'], $tRecup, null);
            $km = $kmEffort === null ? null : round($kmEffort + (float) $kmRecup, 1);
        }

        return [
            'exercise_id' => null,
            'name' => $p['name'],
            'category' => 'cardio',
            'muscle_group' => 'cardio',
            'equipment' => $equipment,
            'movement' => $movement,
            'sets' => $sets,
            'reps' => null,
            'duration_sec' => $duration,
            'rest_sec' => $rest,
            'distance_km' => $km !== null && $km > 0 ? $km : null,
            'intensity' => $p['zone'] >= 4 ? 'elevee' : ($p['zone'] === 3 ? 'moderee' : 'faible'),
            'instructions' => $p['consigne'],
            'met' => round($met, 1),
            'prescription' => Prescription::sanitize($prescription, $age),
        ];
    }

    /**
     * MET d'une zone depuis le triplet du sport : Z1 ≈ faible × 0,85, Z2 faible, Z3 modéré,
     * Z4 entre modéré et élevé, Z5 élevé.
     *
     * @param  array{faible: float, moderee: float, elevee: float}  $t
     */
    private function metZone(array $t, int $zone): float
    {
        return match (true) {
            $zone <= 1 => max(2.0, $t['faible'] * 0.85),
            $zone === 2 => $t['faible'],
            $zone === 3 => $t['moderee'],
            $zone === 4 => ($t['moderee'] + $t['elevee']) / 2,
            default => $t['elevee'],
        };
    }

    /**
     * Distance estimée (km) quand elle a un sens : vitesse cible, temps au 500 m, distance de
     * nage, ou vitesse moyenne par zone (route, course dehors). Null sur vélo d'intérieur,
     * elliptique et corde.
     *
     * @param  array<string, mixed>  $cible
     */
    private function km(string $a, string $level, array $cible, int $zone, int $sec, ?int $distanceM): ?float
    {
        if ($distanceM !== null) {
            return round($distanceM / 1000, 2);
        }
        if ($sec <= 0) {
            return 0.0;
        }

        $kmh = null;
        if (isset($cible['vitesse_kmh'])) {
            $kmh = array_sum($cible['vitesse_kmh']) / 2;
        } elseif (isset($cible['allure_500m_sec'])) {
            $kmh = 1800 / max(60, array_sum($cible['allure_500m_sec']) / 2);
        } elseif ($a === 'velo_route') {
            $kmh = self::VITESSE_VELO[max(1, min(5, $zone))] * self::FACTEUR_NIVEAU[$level];
        } elseif ($a === 'course') {
            $kmh = array_sum(self::VITESSE_COURSE[$level][max(1, min(5, $zone))]) / 2;
        } elseif ($a === 'marche') {
            $kmh = array_sum(self::VITESSE_MARCHE[$level][max(1, min(3, $zone))]) / 2;
        }

        return $kmh === null ? null : round($kmh * $sec / 3600, 1);
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @return list<string>
     */
    private function explication(string $a, string $variante, string $level, array $phases, int $warm, int $cool, int $main, ?int $age): array
    {
        $out = [];
        $principal = end($phases) ?: null;

        $out[] = match ($variante) {
            'fractionne' => sprintf('Fractionné court en %s : des efforts brefs et intenses entrecoupés de récupération active, le format le plus efficace pour progresser en peu de temps.', self::LIBELLES[$a]),
            'seuil' => sprintf('Travail au seuil en %s : des blocs « confortablement difficiles » qui repoussent le moment où tu t’essouffles.', self::LIBELLES[$a]),
            'force' => sprintf('Séance de force en %s : peu de vitesse, beaucoup de résistance, pour gagner en puissance musculaire.', self::LIBELLES[$a]),
            default => sprintf('Endurance fondamentale en %s : un effort régulier où tu peux parler, la base de toute progression.', self::LIBELLES[$a]),
        };

        if ($principal !== null && $principal['kind'] === 'intervalles') {
            $out[] = sprintf('Bloc principal : %s.', PrescriptionFormatter::resume([
                'prescription' => Prescription::sanitize([
                    'type' => 'intervalles', 'repetitions' => $principal['n'], 'effort_sec' => $principal['effort'],
                    'recup_sec' => $principal['recup'], 'distance_m' => $principal['distance_m'],
                    'cible' => $principal['cible'], 'recup_cible' => $principal['recup_cible'],
                ], $age),
            ]));
        }

        $out[] = sprintf(
            'Structure : %s d’échauffement, %s de bloc principal, %s de retour au calme.',
            PrescriptionFormatter::duree($warm),
            PrescriptionFormatter::duree($main),
            PrescriptionFormatter::duree($cool)
        );

        if ($a === 'natation') {
            $out[] = 'En natation, fie-toi au ressenti (RPE sur 10) : la fréquence cardiaque se lit mal dans l’eau.';
        } elseif (($max = HeartRateZones::fcMax($age)) !== null) {
            $out[] = sprintf('Zones cardiaques calculées pour tes %d ans (FC max estimée %d bpm, formule de Tanaka) : ajuste au ressenti.', $age, $max);
        } else {
            $out[] = 'Âge non renseigné : suis la zone et ton ressenti ; indique ton âge dans ton profil pour obtenir des fréquences cardiaques cibles.';
        }

        return $out;
    }
}
