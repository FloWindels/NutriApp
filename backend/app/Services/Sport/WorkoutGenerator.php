<?php

namespace App\Services\Sport;

use App\Enums\Equipment;
use App\Enums\ExerciseLevel;
use App\Enums\Lieu;
use App\Models\Exercise;
use App\Models\Sport;
use Illuminate\Support\Collection;

/**
 * Générateur de séances par règles (brief §13.3 + addendum §C.4), repli du coach IA.
 *
 * - niveau : débutant 2 × 10–12 (repos 90 s) · intermédiaire 3 × 8–12 (60 s) · avancé 4 × 6–10 (60–90 s) ;
 * - fractionné cardio : 20/40 s · 30/30 s · 40/20 s selon le niveau ;
 * - objectif (bloc principal) : perte_de_gras 50 % force / 50 % cardio · prise_de_muscle 80/20 ·
 *   endurance 30/70 · forme 60/40 + 1 mobilité · force 100 % force en 4–6 répétitions (avancé seulement) ;
 * - structure : échauffement 5–8 min, circuit principal, retour au calme 5 min ;
 * - n_exercices = clamp(floor((durée − 12) / (séries × (0,75 + repos/60))), 3, 8) ;
 * - matériel strict (« aucun » toujours permis), focus → groupes musculaires, jamais deux exercices
 *   consécutifs sur le même groupe, restrictions débutant, zones à éviter exclues (contraindications) ;
 * - types cardio (course, vélo, natation, marche, rameur) : bloc principal en fractionné ou continu ;
 * - déterministe pour des entrées + une graine identiques.
 */
class WorkoutGenerator
{
    public const SAFETY_SENTENCE = 'Arrête l’exercice en cas de douleur ou de malaise. Programme indicatif, ne remplace pas un coach ni un avis médical.';

    public const FORBIDDEN_FOR_BEGINNERS = ['squat-barre', 'souleve-de-terre-barre', 'developpe-couche-barre'];

    public const BLOCK_NAMES = [
        'echauffement' => 'Échauffement',
        'principal' => 'Circuit principal',
        'retour_au_calme' => 'Retour au calme',
    ];

    private const LEVEL_PARAMS = [
        'debutant' => ['sets' => 2, 'reps' => [10, 12], 'rest' => 90, 'effort' => 20, 'recovery' => 40],
        'intermediaire' => ['sets' => 3, 'reps' => [8, 12], 'rest' => 60, 'effort' => 30, 'recovery' => 30],
        'avance' => ['sets' => 4, 'reps' => [6, 10], 'rest' => 75, 'effort' => 40, 'recovery' => 20],
    ];

    private const GOAL_CARDIO_SHARE = [
        'perte_de_gras' => 0.5,
        'prise_de_muscle' => 0.2,
        'endurance' => 0.7,
        'forme' => 0.4,
        'force' => 0.0,
    ];

    /** Vitesses indicatives (km/h) par intensité pour estimer une distance. */
    private const SPEEDS = [
        'course_a_pied' => ['faible' => 6.0, 'moderee' => 10.0, 'elevee' => 12.5],
        'velo' => ['faible' => 15.0, 'moderee' => 20.0, 'elevee' => 26.0],
        'natation' => ['faible' => 1.5, 'moderee' => 2.0, 'elevee' => 2.6],
        'marche' => ['faible' => 4.0, 'moderee' => 5.0, 'elevee' => 6.0],
    ];

    public function __construct(private CaloriesEstimator $calories)
    {
    }

    /**
     * @param  array<string, mixed>  $request  Demande résolue (goal, level, duration_min, sport_type, lieu, equipment, focus, zones_a_eviter, notes, seed)
     * @param  array<string, mixed>  $context  Contexte WorkoutContextBuilder::build (profil.profil_mineur / situation_particuliere) — optionnel
     * @param  Collection<int, Exercise>|null  $catalog  Exercices candidats (défaut : catalogue public)
     * @return array<string, mixed>  Proposition (addendum §C.4)
     */
    public function generate(array $request, float $weightKg = 70.0, array $context = [], ?Collection $catalog = null): array
    {
        $goal = in_array($request['goal'] ?? null, array_keys(SportVocab::OBJECTIFS), true) ? $request['goal'] : 'forme';
        $level = in_array($request['level'] ?? null, ExerciseLevel::values(), true) ? $request['level'] : 'debutant';
        $duration = max(10, min(180, (int) ($request['duration_min'] ?? 30)));
        $sportType = in_array($request['sport_type'] ?? null, array_keys(SportVocab::SPORT_TYPES), true) ? $request['sport_type'] : 'musculation';
        $lieu = in_array($request['lieu'] ?? null, Lieu::values(), true) ? $request['lieu'] : 'maison';
        $equipment = $this->normaliseEquipment($request['equipment'] ?? []);
        $focus = array_values(array_intersect((array) ($request['focus'] ?? []), SportVocab::focusValues()));
        $zones = array_values(array_intersect((array) ($request['zones_a_eviter'] ?? []), array_keys(SportVocab::ZONES)));
        $notes = isset($request['notes']) && trim((string) $request['notes']) !== '' ? trim((string) $request['notes']) : null;
        $seed = (int) ($request['seed'] ?? 0);

        $gentle = (bool) ($context['profil']['profil_mineur'] ?? false)
            || (($context['profil']['situation_particuliere'] ?? 'aucune') !== 'aucune');

        $rng = new SeededRandom(crc32(json_encode([$goal, $level, $duration, $sportType, $lieu, $equipment, $focus, $zones])) + $seed);

        $base = [
            'sport_type' => $sportType,
            'lieu' => $lieu,
            'goal' => $goal,
            'level' => $level,
            'duration_min' => $duration,
            'equipment' => $equipment,
            'focus' => $focus,
            'zones_a_eviter' => $zones,
            'notes' => $notes,
            'gentle' => $gentle,
            'seed' => $seed,
        ];

        $proposal = SportVocab::isCardioType($sportType)
            ? $this->cardioSession($base, $rng)
            : $this->strengthSession($base, $rng, $catalog ?? Exercise::query()->public()->orderBy('id')->get());

        $proposal['calories_estimate'] = $this->calories->forProposal($proposal['blocks'], $level, $duration, $weightKg);
        $proposal['is_estimate'] = true;
        $proposal['generated_by'] = 'regles';
        $proposal['llm_model'] = null;

        if ($notes !== null) {
            $proposal['explication'][] = sprintf('Tes notes (« %s ») ne sont interprétées que par le coach IA : ici, ce sont les règles Mavi’oh qui s’appliquent.', $notes);
        }
        if ($gentle) {
            $proposal['warnings'][] = 'Intensité douce recommandée dans ta situation : demande l’avis de ton médecin avant de reprendre ou d’intensifier une activité.';
        }
        $proposal['explication'][] = self::SAFETY_SENTENCE;

        return $proposal;
    }

    // ------------------------------------------------------------------------------------
    // Séance de renforcement / circuit (musculation, HIIT, yoga, autre)
    // ------------------------------------------------------------------------------------

    /**
     * @param  Collection<int, Exercise>  $catalog
     */
    private function strengthSession(array $b, SeededRandom $rng, Collection $catalog): array
    {
        $level = $b['level'];
        $goal = $b['goal'];
        $duration = $b['duration_min'];
        $p = self::LEVEL_PARAMS[$level];
        $sets = $p['sets'];
        $repsRange = $p['reps'];
        $rest = $p['rest'];
        $explication = [];
        $warnings = [];

        $cardioShare = self::GOAL_CARDIO_SHARE[$goal];
        if ($goal === 'force') {
            if ($level === 'avance') {
                $repsRange = [4, 6];
                $rest = 90;
            } else {
                $cardioShare = self::GOAL_CARDIO_SHARE['prise_de_muscle'];
                $explication[] = 'L’objectif force pur (4–6 répétitions lourdes) est réservé au niveau avancé : ta séance suit une répartition prise de muscle pour construire la base.';
            }
        }
        if ($b['sport_type'] === 'hiit') {
            $cardioShare = $b['gentle'] ? 0.4 : 0.7;
        }
        if ($b['sport_type'] === 'yoga') {
            return $this->mobilitySession($b, $rng, $catalog);
        }

        $userRank = ExerciseLevel::from($level)->rank();
        $pool = $catalog->filter(function (Exercise $e) use ($userRank, $b) {
            if (ExerciseLevel::tryFrom((string) $e->level)?->rank() > $userRank) {
                return false;
            }
            if (! in_array($e->equipment, $b['equipment'], true)) {
                return false;
            }
            if ($e->hitsZones($b['zones_a_eviter'])) {
                return false;
            }
            if ($b['level'] === 'debutant' && in_array($e->slug, self::FORBIDDEN_FOR_BEGINNERS, true)) {
                return false;
            }
            if ($b['gentle'] && (float) $e->met >= 8.0) {
                return false;
            }

            return true;
        })->values();

        $n = (int) max(3, min(8, floor(($duration - 12) / ($sets * (0.75 + $rest / 60)))));
        $nCardio = (int) round($n * $cardioShare);
        $nForce = $n - $nCardio;

        $wantsCore = array_intersect($b['focus'], ['gainage', 'abdos']) !== [];
        $forcePool = $pool->filter(fn (Exercise $e) => $e->category === 'force' || ($wantsCore && $e->category === 'gainage'))->values();
        $muscles = SportVocab::musclesForFocus($b['focus']);
        if ($muscles !== []) {
            $focused = $forcePool->filter(fn (Exercise $e) => in_array($e->muscle_group, $muscles, true) || $e->muscle_group === 'corps_entier')->values();
            if ($focused->count() >= max(2, $nForce)) {
                $forcePool = $focused;
            } else {
                $explication[] = 'Trop peu d’exercices disponibles pour ton focus avec ce matériel : la sélection a été élargie au corps entier.';
            }
        }
        $cardioPool = $pool->filter(fn (Exercise $e) => $e->category === 'cardio')->values();

        if ($cardioPool->isEmpty()) {
            $nForce = $n;
            $nCardio = 0;
        }
        if ($forcePool->isEmpty()) {
            $nCardio = $n;
            $nForce = 0;
        }

        $force = $this->pickDistinctGroups($forcePool, $nForce, $rng);
        $cardio = $this->pickDistinctGroups($cardioPool, $nCardio, $rng);
        $sequence = $this->interleave($force, $cardio);
        $sequence = $this->repairConsecutive($sequence, $pool, $rng);

        $mainIntensity = $b['gentle'] ? 'faible' : (($level === 'avance' || $b['sport_type'] === 'hiit') ? 'elevee' : 'moderee');
        $main = [];
        foreach ($sequence as $e) {
            $main[] = match ($e->category) {
                'cardio' => $this->item($e, [
                    'sets' => $sets,
                    'duration_sec' => $p['effort'],
                    'rest_sec' => $p['recovery'],
                    'intensity' => $mainIntensity,
                ]),
                'gainage' => $this->item($e, [
                    'sets' => $sets,
                    'duration_sec' => $e->default_duration_sec ?? 30,
                    'rest_sec' => $rest,
                    'intensity' => $mainIntensity,
                ]),
                default => $this->item($e, [
                    'sets' => $sets,
                    'reps' => $rng->int($repsRange[0], $repsRange[1]),
                    'rest_sec' => $rest,
                    'intensity' => $mainIntensity,
                ]),
            };
        }

        $usedIds = array_map(fn (Exercise $e) => $e->id, $sequence);
        if ($goal === 'forme') {
            $mob = $this->pickFrom($pool->filter(fn (Exercise $e) => $e->category === 'mobilite' && ! in_array($e->id, $usedIds, true))->values(), 1, $rng);
            foreach ($mob as $e) {
                $main[] = $this->item($e, ['sets' => 1, 'duration_sec' => 45, 'rest_sec' => 0, 'intensity' => 'faible']);
                $usedIds[] = $e->id;
            }
        }

        [$warmup, $usedIds] = $this->warmup($pool, $duration, $usedIds, $rng);
        $cooldown = $this->cooldown($pool, 300, $usedIds, $rng);

        $explication[] = sprintf(
            'Objectif %s : %d exercice%s de renforcement et %d de cardio dans le circuit principal.',
            mb_strtolower(SportVocab::OBJECTIFS[$goal]),
            count($force),
            count($force) > 1 ? 's' : '',
            count($cardio)
        );
        $explication[] = sprintf(
            'Niveau %s : %d séries de %d à %d répétitions, %d s de repos ; fractionné cardio %d s d’effort / %d s de récupération.',
            mb_strtolower(ExerciseLevel::from($level)->label()),
            $sets,
            $repsRange[0],
            $repsRange[1],
            $rest,
            $p['effort'],
            $p['recovery']
        );
        $explication[] = $this->equipmentSentence($b['equipment']);
        if ($b['focus'] !== []) {
            $explication[] = sprintf('Focus %s : les exercices ciblent en priorité %s.', $this->focusLabels($b['focus']), $muscles !== [] ? implode(', ', $muscles) : 'le corps entier');
        }
        if ($b['zones_a_eviter'] !== []) {
            $explication[] = sprintf('Zones à éviter (%s) : les exercices qui les sollicitent ont été écartés.', $this->zoneLabels($b['zones_a_eviter']));
        }
        if ($level === 'debutant') {
            $explication[] = 'Débutant : pas d’exercice avancé ni de barre lourde (squat, soulevé de terre, développé couché) ; privilégie la technique.';
        }
        $explication[] = sprintf('Séance pensée pour : %s.', mb_strtolower(Lieu::from($b['lieu'])->label()));

        return [
            'title' => $this->title($b),
            'sport_type' => $b['sport_type'],
            'lieu' => $b['lieu'],
            'goal' => $goal,
            'level' => $level,
            'duration_min' => $duration,
            'equipment' => $b['equipment'],
            'focus' => $b['focus'],
            'zones_a_eviter' => $b['zones_a_eviter'],
            'intensity' => $mainIntensity,
            'explication' => $explication,
            'warnings' => $warnings,
            'blocks' => [
                ['key' => 'echauffement', 'name' => self::BLOCK_NAMES['echauffement'], 'exercises' => $warmup],
                ['key' => 'principal', 'name' => self::BLOCK_NAMES['principal'], 'exercises' => $main],
                ['key' => 'retour_au_calme', 'name' => self::BLOCK_NAMES['retour_au_calme'], 'exercises' => $cooldown],
            ],
        ];
    }

    /**
     * Séance yoga / mobilité : uniquement mobilité et gainage doux.
     *
     * @param  Collection<int, Exercise>  $catalog
     */
    private function mobilitySession(array $b, SeededRandom $rng, Collection $catalog): array
    {
        $duration = $b['duration_min'];
        $pool = $catalog->filter(fn (Exercise $e) => in_array($e->category, ['mobilite', 'gainage'], true)
            && $e->equipment === 'aucun'
            && ! $e->hitsZones($b['zones_a_eviter'])
            && ExerciseLevel::tryFrom((string) $e->level)?->rank() <= ExerciseLevel::from($b['level'])->rank())->values();

        $n = (int) max(3, min(8, floor(($duration - 10) / 3)));
        $picked = $this->pickDistinctGroups($pool->filter(fn (Exercise $e) => $e->category === 'mobilite')->values(), max(2, $n - 2), $rng);
        $core = $this->pickDistinctGroups($pool->filter(fn (Exercise $e) => $e->category === 'gainage')->values(), 2, $rng);
        $sequence = $this->repairConsecutive($this->interleave($picked, $core), $pool, $rng);

        $perItem = max(45, (int) floor((($duration - 10) * 60) / max(1, count($sequence))));
        $main = array_map(fn (Exercise $e) => $this->item($e, [
            'sets' => $e->category === 'gainage' ? 2 : 1,
            'duration_sec' => $e->category === 'gainage' ? (int) max(20, $perItem / 2) : $perItem,
            'rest_sec' => $e->category === 'gainage' ? 30 : 0,
            'intensity' => 'faible',
        ]), $sequence);

        $usedIds = array_map(fn (Exercise $e) => $e->id, $sequence);
        [$warmup, $usedIds] = $this->warmup($pool, min($duration, 30), $usedIds, $rng);
        $cooldown = $this->cooldown($pool, 300, $usedIds, $rng);

        return [
            'title' => $this->title($b),
            'sport_type' => 'yoga',
            'lieu' => $b['lieu'],
            'goal' => $b['goal'],
            'level' => $b['level'],
            'duration_min' => $duration,
            'equipment' => $b['equipment'],
            'focus' => $b['focus'],
            'zones_a_eviter' => $b['zones_a_eviter'],
            'intensity' => 'faible',
            'explication' => [
                sprintf('Séance mobilité douce de %d min : %d postures et exercices de gainage tenus longuement, en respirant calmement.', $duration, count($sequence)),
                'Ne force jamais une amplitude : la mobilité progresse avec la régularité, pas avec la douleur.',
                $b['zones_a_eviter'] !== [] ? sprintf('Zones à éviter (%s) : les exercices qui les sollicitent ont été écartés.', $this->zoneLabels($b['zones_a_eviter'])) : 'Aucune zone à éviter signalée : toutes les postures douces sont possibles.',
            ],
            'warnings' => [],
            'blocks' => [
                ['key' => 'echauffement', 'name' => self::BLOCK_NAMES['echauffement'], 'exercises' => $warmup],
                ['key' => 'principal', 'name' => self::BLOCK_NAMES['principal'], 'exercises' => $main],
                ['key' => 'retour_au_calme', 'name' => self::BLOCK_NAMES['retour_au_calme'], 'exercises' => $cooldown],
            ],
        ];
    }

    // ------------------------------------------------------------------------------------
    // Séance cardio pure (course, vélo, natation, marche, rameur)
    // ------------------------------------------------------------------------------------

    private function cardioSession(array $b, SeededRandom $rng): array
    {
        $type = $b['sport_type'];
        $level = $b['level'];
        $duration = $b['duration_min'];
        $met = $this->metTriplet($type);
        $labels = $this->cardioLabels($type);
        $equipment = $this->cardioEquipment($type, $b['lieu']);
        $warnings = [];
        $explication = [];

        $warmSec = 300;
        $coolSec = 300;
        $mainSec = max(300, $duration * 60 - $warmSec - $coolSec);

        $main = [];
        if ($b['gentle']) {
            $main[] = $this->cardioItem($labels['steady'], $type, $equipment, $mainSec, null, 1, 'faible', $met['faible'],
                'Allure très confortable : tu dois pouvoir parler sans t’essouffler.');
            $explication[] = sprintf('Séance continue de %d min à allure douce : dans ta situation, pas de fractionné intense.', (int) round($mainSec / 60));
        } elseif ($level === 'debutant') {
            $rounds = (int) max(3, min(12, floor($mainSec / 180)));
            $leftover = $mainSec - $rounds * 180;
            $coolSec += max(0, $leftover);
            $main[] = $this->cardioItem(
                sprintf('%s 2 min / %s 1 min', $labels['effort'], mb_strtolower($labels['recovery'])),
                $type,
                $equipment,
                120,
                60,
                $rounds,
                'moderee',
                $met['moderee'],
                sprintf('%d répétitions : 2 min de %s à allure confortable, puis 1 min de %s pour récupérer.', $rounds, mb_strtolower($labels['effort']), mb_strtolower($labels['recovery']))
            );
            $explication[] = sprintf('Débutant : fractionné doux %d × (2 min d’effort / 1 min de récupération) pour progresser sans t’épuiser.', $rounds);
        } elseif ($level === 'intermediaire') {
            $intervalSec = $mainSec >= 900 ? 480 : 0;
            $steadySec = $mainSec - $intervalSec;
            $main[] = $this->cardioItem($labels['steady'], $type, $equipment, $steadySec, null, 1, 'moderee', $met['moderee'],
                'Allure régulière et confortable : respiration contrôlée, tu peux tenir une conversation courte.');
            if ($intervalSec > 0) {
                $main[] = $this->cardioItem(
                    sprintf('%s rapide 1 min / %s 1 min', $labels['effort'], mb_strtolower($labels['recovery'])),
                    $type,
                    $equipment,
                    60,
                    60,
                    4,
                    'elevee',
                    $met['elevee'],
                    '4 accélérations d’une minute suivies d’une minute de récupération active.'
                );
            }
            $explication[] = sprintf('Intermédiaire : %d min en continu%s.', (int) round($steadySec / 60), $intervalSec > 0 ? ' puis 4 accélérations d’une minute' : '');
        } else {
            $rounds = (int) max(2, min(8, floor($mainSec * 0.4 / 180)));
            $steadySec = $mainSec - $rounds * 180;
            $main[] = $this->cardioItem($labels['tempo'], $type, $equipment, $steadySec, null, 1, 'moderee', $met['moderee'],
                'Allure soutenue mais contrôlée (tempo), en gardant une marge pour le fractionné qui suit.');
            $main[] = $this->cardioItem(
                sprintf('%s intense 2 min / %s 1 min', $labels['effort'], mb_strtolower($labels['recovery'])),
                $type,
                $equipment,
                120,
                60,
                $rounds,
                'elevee',
                $met['elevee'],
                sprintf('%d répétitions de 2 min à haute intensité, 1 min de récupération active entre chaque.', $rounds)
            );
            $explication[] = sprintf('Avancé : %d min à allure tempo puis %d × (2 min intenses / 1 min de récupération).', (int) round($steadySec / 60), $rounds);
        }

        $warmup = [$this->cardioItem($labels['warmup'], $type, $equipment, $warmSec, null, 1, 'faible', $met['faible'],
            'Monte progressivement en intensité pour préparer le cœur et les articulations.')];
        $cooldown = [$this->cardioItem($labels['cooldown'], $type, $equipment, $coolSec, null, 1, 'faible', $met['faible'],
            'Ralentis progressivement, puis étire doucement mollets, cuisses et hanches.')];

        $explication[] = sprintf('Structure : 5 min d’échauffement, %d min de bloc principal, %d min de retour au calme.', (int) round($mainSec / 60), (int) round($coolSec / 60));
        $explication[] = sprintf('Séance %s pensée pour : %s.', mb_strtolower(SportVocab::SPORT_TYPES[$type]), mb_strtolower(Lieu::from($b['lieu'])->label()));
        if ($b['zones_a_eviter'] !== []) {
            $impact = array_intersect($b['zones_a_eviter'], ['genoux', 'chevilles', 'hanches']);
            if ($impact !== [] && in_array($type, ['course_a_pied', 'marche'], true)) {
                $warnings[] = sprintf('Tu signales %s : la %s sollicite ces zones, réduis l’allure ou préfère le vélo ou la natation en cas de gêne.', $this->zoneLabels($impact), mb_strtolower(SportVocab::SPORT_TYPES[$type]));
            }
            if (in_array('dos', $b['zones_a_eviter'], true) && $type === 'rameur') {
                $warnings[] = 'Tu signales le dos : au rameur, garde le dos droit et une résistance légère ; arrête en cas de douleur.';
            }
            if (in_array('epaules', $b['zones_a_eviter'], true) && $type === 'natation') {
                $warnings[] = 'Tu signales les épaules : en natation, évite le papillon et privilégie la brasse ou le dos crawlé souple.';
            }
        }

        return [
            'title' => $this->title($b),
            'sport_type' => $type,
            'lieu' => $b['lieu'],
            'goal' => $b['goal'],
            'level' => $level,
            'duration_min' => $duration,
            'equipment' => $b['equipment'],
            'focus' => $b['focus'],
            'zones_a_eviter' => $b['zones_a_eviter'],
            'intensity' => $b['gentle'] ? 'faible' : ($level === 'avance' ? 'elevee' : 'moderee'),
            'explication' => $explication,
            'warnings' => $warnings,
            'blocks' => [
                ['key' => 'echauffement', 'name' => self::BLOCK_NAMES['echauffement'], 'exercises' => $warmup],
                ['key' => 'principal', 'name' => self::BLOCK_NAMES['principal'], 'exercises' => $main],
                ['key' => 'retour_au_calme', 'name' => self::BLOCK_NAMES['retour_au_calme'], 'exercises' => $cooldown],
            ],
        ];
    }

    /**
     * @return array{faible: float, moderee: float, elevee: float}
     */
    private function metTriplet(string $type): array
    {
        $slug = SportVocab::sportTypeSlug($type);
        $sport = $slug ? Sport::query()->where('slug', $slug)->where('is_public', true)->first() : null;

        if ($sport !== null) {
            return ['faible' => $sport->met_faible, 'moderee' => $sport->met_moderee, 'elevee' => $sport->met_elevee];
        }

        return SportVocab::metTripletForType($type);
    }

    /**
     * @return array{effort: string, recovery: string, steady: string, tempo: string, warmup: string, cooldown: string}
     */
    private function cardioLabels(string $type): array
    {
        return match ($type) {
            'course_a_pied' => ['effort' => 'Course', 'recovery' => 'Marche', 'steady' => 'Course continue', 'tempo' => 'Course tempo', 'warmup' => 'Marche rapide puis trot léger', 'cooldown' => 'Marche de récupération'],
            'velo' => ['effort' => 'Vélo', 'recovery' => 'Pédalage souple', 'steady' => 'Vélo en continu', 'tempo' => 'Vélo tempo', 'warmup' => 'Pédalage souple', 'cooldown' => 'Pédalage souple de récupération'],
            'natation' => ['effort' => 'Nage', 'recovery' => 'Nage lente', 'steady' => 'Nage continue', 'tempo' => 'Nage tempo', 'warmup' => 'Nage lente d’échauffement', 'cooldown' => 'Nage lente de récupération'],
            'marche' => ['effort' => 'Marche rapide', 'recovery' => 'Marche lente', 'steady' => 'Marche rapide continue', 'tempo' => 'Marche rapide soutenue', 'warmup' => 'Marche lente', 'cooldown' => 'Marche lente de récupération'],
            default => ['effort' => 'Rameur', 'recovery' => 'Rame souple', 'steady' => 'Rameur en continu', 'tempo' => 'Rameur tempo', 'warmup' => 'Rame souple d’échauffement', 'cooldown' => 'Rame souple de récupération'],
        };
    }

    private function cardioEquipment(string $type, string $lieu): string
    {
        $indoor = in_array($lieu, ['maison', 'salle_publique', 'salle_privee'], true);

        return match ($type) {
            'course_a_pied', 'marche' => $indoor && $lieu !== 'maison' ? 'tapis' : 'aucun',
            'velo' => $indoor ? 'velo' : 'aucun',
            'rameur' => 'machine',
            default => 'aucun',
        };
    }

    private function cardioItem(string $name, string $type, string $equipment, int $durationSec, ?int $restSec, int $sets, string $intensity, float $met, string $instructions): array
    {
        $distance = null;
        if (isset(self::SPEEDS[$type])) {
            $speedEffort = self::SPEEDS[$type][$intensity];
            $speedRecovery = self::SPEEDS[$type]['faible'];
            $distance = round($sets * ($durationSec * $speedEffort + ($restSec ?? 0) * $speedRecovery) / 3600, 1);
        }

        return [
            'exercise_id' => null,
            'name' => $name,
            'category' => 'cardio',
            'muscle_group' => 'cardio',
            'equipment' => $equipment,
            'sets' => $sets,
            'reps' => null,
            'duration_sec' => $durationSec,
            'rest_sec' => $restSec,
            'distance_km' => $distance,
            'intensity' => $intensity,
            'instructions' => $instructions,
            'met' => round($met, 1),
        ];
    }

    // ------------------------------------------------------------------------------------
    // Sélection & agencement
    // ------------------------------------------------------------------------------------

    /**
     * @param  Collection<int, Exercise>  $pool
     * @return list<Exercise>
     */
    private function pickDistinctGroups(Collection $pool, int $count, SeededRandom $rng): array
    {
        if ($count <= 0 || $pool->isEmpty()) {
            return [];
        }

        $shuffled = $rng->shuffle($pool->all());
        $picked = [];
        $groups = [];
        $ids = [];

        foreach ($shuffled as $e) {
            if (count($picked) >= $count) {
                break;
            }
            if (isset($groups[$e->muscle_group])) {
                continue;
            }
            $picked[] = $e;
            $groups[$e->muscle_group] = true;
            $ids[$e->id] = true;
        }

        foreach ($shuffled as $e) {
            if (count($picked) >= $count) {
                break;
            }
            if (isset($ids[$e->id])) {
                continue;
            }
            $picked[] = $e;
            $ids[$e->id] = true;
        }

        return $picked;
    }

    /**
     * @param  Collection<int, Exercise>  $pool
     * @return list<Exercise>
     */
    private function pickFrom(Collection $pool, int $count, SeededRandom $rng): array
    {
        return array_slice($rng->shuffle($pool->all()), 0, max(0, $count));
    }

    /**
     * Répartit le cardio régulièrement entre les exercices de force.
     *
     * @param  list<Exercise>  $force
     * @param  list<Exercise>  $cardio
     * @return list<Exercise>
     */
    private function interleave(array $force, array $cardio): array
    {
        $n = count($force) + count($cardio);
        $out = [];
        $fi = 0;
        $ci = 0;

        for ($k = 0; $k < $n; $k++) {
            $target = (int) floor(($k + 1) * count($cardio) / max(1, $n));
            if ($ci < $target && $ci < count($cardio)) {
                $out[] = $cardio[$ci++];
            } elseif ($fi < count($force)) {
                $out[] = $force[$fi++];
            } else {
                $out[] = $cardio[$ci++];
            }
        }

        return $out;
    }

    /**
     * Jamais deux exercices consécutifs sur le même groupe musculaire : permutations puis remplacement.
     *
     * @param  list<Exercise>  $seq
     * @param  Collection<int, Exercise>  $pool
     * @return list<Exercise>
     */
    private function repairConsecutive(array $seq, Collection $pool, SeededRandom $rng): array
    {
        $conflicts = fn (array $s): int => collect($s)->reduce(fn ($c, $e, $i) => $c + ($i > 0 && $s[$i - 1]->muscle_group === $e->muscle_group ? 1 : 0), 0);

        for ($pass = 0; $pass < 3 && $conflicts($seq) > 0; $pass++) {
            for ($i = 1; $i < count($seq); $i++) {
                if ($seq[$i - 1]->muscle_group !== $seq[$i]->muscle_group) {
                    continue;
                }
                $before = $conflicts($seq);
                for ($j = $i + 1; $j < count($seq); $j++) {
                    $candidate = $seq;
                    [$candidate[$i], $candidate[$j]] = [$candidate[$j], $candidate[$i]];
                    if ($conflicts($candidate) < $before) {
                        $seq = $candidate;
                        break;
                    }
                }
            }
        }

        if ($conflicts($seq) > 0) {
            $usedIds = array_map(fn (Exercise $e) => $e->id, $seq);
            $spare = $rng->shuffle($pool->filter(fn (Exercise $e) => in_array($e->category, ['force', 'cardio', 'gainage'], true) && ! in_array($e->id, $usedIds, true))->all());

            for ($i = 1; $i < count($seq); $i++) {
                if ($seq[$i - 1]->muscle_group !== $seq[$i]->muscle_group) {
                    continue;
                }
                foreach ($spare as $k => $candidate) {
                    $nextOk = ! isset($seq[$i + 1]) || $seq[$i + 1]->muscle_group !== $candidate->muscle_group;
                    if ($candidate->muscle_group !== $seq[$i - 1]->muscle_group && $nextOk) {
                        $seq[$i] = $candidate;
                        unset($spare[$k]);
                        break;
                    }
                }
            }
        }

        return array_values($seq);
    }

    /**
     * Échauffement 5–8 min : 2–3 exercices de mobilité / cardio léger.
     *
     * @param  Collection<int, Exercise>  $pool
     * @param  list<int>  $usedIds
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function warmup(Collection $pool, int $duration, array $usedIds, SeededRandom $rng): array
    {
        $count = $duration <= 20 ? 2 : 3;
        $totalSec = $duration <= 20 ? 300 : ($duration >= 60 ? 480 : 360);
        $each = (int) floor($totalSec / $count);

        $mobility = $rng->shuffle($pool->filter(fn (Exercise $e) => $e->category === 'mobilite' && ! in_array($e->id, $usedIds, true))->all());
        $lightCardio = $rng->shuffle($pool->filter(fn (Exercise $e) => $e->category === 'cardio' && (float) $e->met <= 6.0 && $e->level === 'debutant' && ! in_array($e->id, $usedIds, true))->all());

        $picked = [];
        $order = $count === 2 ? ['mobilite', 'cardio'] : ['mobilite', 'cardio', 'mobilite'];
        foreach ($order as $kind) {
            $source = $kind === 'mobilite' ? $mobility : $lightCardio;
            $alt = $kind === 'mobilite' ? $lightCardio : $mobility;
            $e = array_shift($source) ?? array_shift($alt);
            if ($kind === 'mobilite') {
                $mobility = $source;
                $lightCardio = $alt;
            } else {
                $lightCardio = $source;
                $mobility = $alt;
            }
            if ($e === null) {
                continue;
            }
            $picked[] = $this->item($e, ['sets' => 1, 'duration_sec' => $each, 'rest_sec' => 0, 'intensity' => 'faible']);
            $usedIds[] = $e->id;
        }

        return [$picked, $usedIds];
    }

    /**
     * Retour au calme 5 min : 2 exercices de mobilité (étirements de préférence).
     *
     * @param  Collection<int, Exercise>  $pool
     * @param  list<int>  $usedIds
     * @return list<array<string, mixed>>
     */
    private function cooldown(Collection $pool, int $totalSec, array $usedIds, SeededRandom $rng): array
    {
        $candidates = $pool->filter(fn (Exercise $e) => $e->category === 'mobilite' && ! in_array($e->id, $usedIds, true));
        $stretch = $candidates->filter(fn (Exercise $e) => str_starts_with((string) $e->slug, 'etirement') || str_starts_with((string) $e->slug, 'posture') || $e->slug === 'chat-vache');

        $picked = $this->pickFrom($stretch->values(), 2, $rng);
        if (count($picked) < 2) {
            $ids = array_map(fn (Exercise $e) => $e->id, $picked);
            $picked = array_merge($picked, $this->pickFrom($candidates->filter(fn (Exercise $e) => ! in_array($e->id, $ids, true))->values(), 2 - count($picked), $rng));
        }

        $each = (int) floor($totalSec / max(1, count($picked)));

        return array_map(fn (Exercise $e) => $this->item($e, ['sets' => 1, 'duration_sec' => $each, 'rest_sec' => 0, 'intensity' => 'faible']), $picked);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(Exercise $e, array $overrides = []): array
    {
        return array_replace([
            'exercise_id' => $e->id,
            'name' => $e->name,
            'category' => $e->category,
            'muscle_group' => $e->muscle_group,
            'equipment' => $e->equipment,
            'sets' => null,
            'reps' => null,
            'duration_sec' => null,
            'rest_sec' => null,
            'distance_km' => null,
            'intensity' => null,
            'instructions' => (string) $e->instructions,
            'met' => (float) $e->met,
        ], $overrides);
    }

    // ------------------------------------------------------------------------------------
    // Libellés
    // ------------------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function normaliseEquipment(mixed $equipment): array
    {
        $list = array_values(array_unique(array_intersect((array) $equipment, Equipment::values())));
        if (! in_array('aucun', $list, true)) {
            $list[] = 'aucun';
        }
        sort($list);

        return $list;
    }

    private function title(array $b): string
    {
        $label = match ($b['sport_type']) {
            'musculation', 'autre' => 'Séance '.mb_strtolower(SportVocab::OBJECTIFS[$b['goal']]),
            default => SportVocab::SPORT_TYPES[$b['sport_type']],
        };

        return sprintf('%s · %d min', $label, $b['duration_min']);
    }

    private function equipmentSentence(array $equipment): string
    {
        $labels = array_map(fn (string $e) => mb_strtolower(Equipment::from($e)->label()), array_values(array_diff($equipment, ['aucun'])));

        return $labels === []
            ? 'Sans matériel : uniquement des exercices au poids du corps.'
            : sprintf('Matériel pris en compte : %s (les exercices sans matériel restent possibles).', implode(', ', $labels));
    }

    private function focusLabels(array $focus): string
    {
        return implode(', ', array_map(fn (string $f) => mb_strtolower(SportVocab::FOCUS[$f] ?? str_replace('_', ' ', $f)), $focus));
    }

    private function zoneLabels(array $zones): string
    {
        return implode(', ', array_map(fn (string $z) => mb_strtolower(SportVocab::ZONES[$z] ?? $z), $zones));
    }
}
