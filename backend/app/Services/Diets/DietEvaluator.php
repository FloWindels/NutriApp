<?php

namespace App\Services\Diets;

use App\Enums\MealType;
use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealCalculator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reconnaissance indicative d'un régime à partir des repas enregistrés (brief §9).
 *
 * Pondérations : exclusions (par item) 1,0 > fenêtre alimentaire (par repas) 1,0 >
 * fréquence (par semaine) 1,0 > sel / sucres / fibres (par jour) 0,5 > % macros (par jour) 0,5.
 * Score = Σ(poids × réussite) / Σ(poids) × 100. L'heure d'un repas est `consumed_at`
 * ou l'heure par défaut du type (estimation).
 */
class DietEvaluator
{
    public const MIN_JOURS_COMPLETS = 3;
    public const MIN_REPAS_PAR_JOUR = 2;
    public const SEUIL_CONFORME = 85;
    public const SEUIL_PARTIEL = 60;
    public const SEUIL_REGIME_PROCHE = 60;
    public const MAX_REGIMES_PROCHES = 3;

    public const POIDS_EXCLUSION = 1.0;
    public const POIDS_FENETRE = 1.0;
    public const POIDS_FREQUENCE = 1.0;
    public const POIDS_JOUR = 0.5;
    public const POIDS_MACROS = 0.5;

    public const MENTION = 'Reconnaissance indicative à partir de tes repas enregistrés — ce n’est pas un diagnostic.';
    public const MSG_DONNEES_INSUFFISANTES = 'Enregistre au moins 3 journées complètes pour une évaluation.';
    public const RAISON_AUCUNE_EXCLUSION = 'aucun ingrédient exclu détecté';

    /** Régimes jamais proposés comme « proches » (en plus du régime choisi). */
    public const REGIMES_EXCLUS_DES_PROCHES = ['omnivore', 'halal', 'autre'];

    /** Ordre de précédence des groupes de règles (pour trier les écarts). */
    private const ORDRE_GROUPES = ['exclusion' => 0, 'fenetre' => 1, 'frequence' => 2, 'jour' => 3, 'macros' => 4];

    /** Mots-clés par catégorie de fréquence (pliés). */
    public const CATEGORIES_FREQUENCE = [
        'viande' => ['viande', 'poulet', 'boeuf', 'porc', 'veau', 'agneau', 'mouton', 'dinde', 'canard', 'lapin', 'jambon',
            'lardon', 'lardons', 'bacon', 'saucisse', 'saucisson', 'chorizo', 'charcuterie', 'steak', 'escalope', 'merguez',
            'kebab', 'entrecote', 'bavette', 'roti', 'cotelette', 'filet mignon', 'blanc de poulet'],
        'viande_rouge' => ['boeuf', 'steak', 'agneau', 'mouton', 'veau', 'porc', 'entrecote', 'bavette', 'cotelette',
            'viande rouge', 'steak hache', 'merguez', 'roti de boeuf'],
        'poisson' => ['poisson', 'saumon', 'thon', 'cabillaud', 'morue', 'sardine', 'maquereau', 'truite', 'dorade', 'colin',
            'merlu', 'sole', 'hareng', 'anchois', 'crevette', 'crevettes', 'moule', 'moules', 'huitre', 'crabe', 'homard',
            'calamar', 'poulpe', 'fruits de mer', 'surimi'],
    ];

    /** @var array<string, string|null> cache des expressions régulières par régime */
    private array $patterns = [];

    // ------------------------------------------------------------------------------------
    // API publique
    // ------------------------------------------------------------------------------------

    /**
     * Clés des régimes connus (config/diets.php).
     *
     * @return list<string>
     */
    public static function regimes(): array
    {
        return array_keys((array) config('diets', []));
    }

    /**
     * Règles d'un régime (tableau vide si régime inconnu ou sans règles).
     *
     * @return array<string, mixed>
     */
    public function rules(string $regime): array
    {
        return (array) config("diets.$regime.regles", []);
    }

    /**
     * Vrai si le régime possède au moins une règle d'exclusion (mots-clés ou tags).
     */
    public function hasExclusions(string $regime): bool
    {
        $r = $this->rules($regime);

        return ! empty($r['exclure_mots_cles']) || ! empty($r['exclure_allergenes_tags'])
            || ! empty($r['exclure_categories_tags']) || ! empty($r['exclure_categories']);
    }

    /**
     * Évaluation complète sur la période [from, to] (dates Y-m-d dans le fuseau de l'utilisateur).
     *
     * @return array<string, mixed>
     */
    public function evaluate(User $user, string $regime, string $from, string $to): array
    {
        $tz = Clock::timezone($user);

        $meals = Meal::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->with(['items.food', 'items.recipe'])
            ->orderBy('date')
            ->get()
            ->filter(fn (Meal $meal) => $meal->items->isNotEmpty())
            ->values();

        $byDate = $meals->groupBy(fn (Meal $meal) => $meal->date->format('Y-m-d'));

        $completeDays = $byDate->filter(fn (Collection $dayMeals) => $dayMeals->count() >= self::MIN_REPAS_PAR_JOUR)->keys()->values();

        $base = [
            'regime' => $regime,
            'nom' => (string) config("diets.$regime.nom", $regime),
            'periode' => [
                'from' => $from,
                'to' => $to,
                'jours_evalues' => $completeDays->count(),
                'repas_evalues' => $meals->count(),
            ],
            'mention' => self::MENTION,
        ];

        if ($completeDays->count() < self::MIN_JOURS_COMPLETS) {
            return $base + [
                'statut' => 'donnees_insuffisantes',
                'score_pct' => null,
                'message' => self::MSG_DONNEES_INSUFFISANTES,
                'ecarts' => [],
                'conseils' => array_values((array) config("diets.$regime.conseils", [])),
                'regimes_proches' => [],
                'is_estimate' => true,
            ];
        }

        $result = $this->score($regime, $byDate, $completeDays->all(), $tz);

        $proches = [];
        foreach (self::regimes() as $candidate) {
            if ($candidate === $regime || in_array($candidate, self::REGIMES_EXCLUS_DES_PROCHES, true)) {
                continue;
            }
            $r = $this->score($candidate, $byDate, $completeDays->all(), $tz);
            if ($r['score_pct'] >= self::SEUIL_REGIME_PROCHE) {
                $proches[] = [
                    'key' => $candidate,
                    'nom' => (string) config("diets.$candidate.nom", $candidate),
                    'score_pct' => $r['score_pct'],
                    'raisons' => array_slice($r['raisons'], 0, 3),
                ];
            }
        }
        usort($proches, fn ($a, $b) => [$b['score_pct'], $a['key']] <=> [$a['score_pct'], $b['key']]);

        $conseils = array_values((array) config("diets.$regime.conseils", []));
        if ($result['ecarts'] !== []) {
            $conseils[] = 'Les écarts listés viennent de tes repas enregistrés : corrige un libellé ou une heure si l’un d’eux te semble faux.';
        }

        return $base + [
            'score_pct' => $result['score_pct'],
            'statut' => $this->statut($result['score_pct']),
            'ecarts' => $result['ecarts'],
            'conseils' => $conseils,
            'regimes_proches' => array_slice($proches, 0, self::MAX_REGIMES_PROCHES),
            'is_estimate' => $result['is_estimate'],
        ];
    }

    /**
     * Statut à partir du score : ≥ 85 conforme | 60–84 partiel | < 60 non_conforme.
     */
    public function statut(int $score): string
    {
        if ($score >= self::SEUIL_CONFORME) {
            return 'conforme';
        }

        return $score >= self::SEUIL_PARTIEL ? 'partiel' : 'non_conforme';
    }

    /**
     * Vrai si l'élément (item de repas, recette ou aliment) contient un ingrédient exclu par le régime.
     */
    public function itemViolatesExclusions(string $regime, MealItem|Recipe|Food $item): bool
    {
        return $this->exclusionViolation($regime, $item) !== null;
    }

    /**
     * Explication française de la violation d'exclusion, ou null si rien n'est détecté.
     * Les libellés sont pliés (minuscules, sans accent) ; les tags Open Food Facts stockés dans
     * food.allergens / food.category sont comparés aux tags exclus.
     */
    public function exclusionViolation(string $regime, MealItem|Recipe|Food $item): ?string
    {
        if (! $this->hasExclusions($regime)) {
            return null;
        }

        $rules = $this->rules($regime);
        $nom = (string) config("diets.$regime.nom", $regime);

        if ($item instanceof Recipe) {
            $tags = array_map('strval', (array) ($item->tags ?? []));
            if (in_array($regime, $tags, true)) {
                return null; // la recette est explicitement étiquetée pour ce régime
            }
            $labels = array_merge([(string) $item->title], $this->ingredientNames($item));
            $label = (string) $item->title;
        } elseif ($item instanceof Food) {
            $labels = [(string) $item->name];
            $label = (string) $item->name;
            if ($hit = $this->tagViolation($item, $rules)) {
                return sprintf('%s porte le tag « %s », exclu du régime %s.', $label, $hit, $nom);
            }
        } else {
            $label = (string) $item->label;
            $labels = [$label];
            $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;
            $recipe = $item->relationLoaded('recipe') ? $item->getRelation('recipe') : null;
            if ($food instanceof Food) {
                $labels[] = (string) $food->name;
                if ($hit = $this->tagViolation($food, $rules)) {
                    return sprintf('%s porte le tag « %s », exclu du régime %s.', $label, $hit, $nom);
                }
            }
            if ($recipe instanceof Recipe) {
                $tags = array_map('strval', (array) ($recipe->tags ?? []));
                if (in_array($regime, $tags, true)) {
                    return null;
                }
                $labels[] = (string) $recipe->title;
                $labels = array_merge($labels, $this->ingredientNames($recipe));
            }
        }

        foreach ($labels as $candidate) {
            $mot = $this->matchKeyword($regime, $candidate);
            if ($mot !== null) {
                return sprintf('%s contient « %s », exclu du régime %s.', $label, $mot, $nom);
            }
        }

        return null;
    }

    /**
     * Premier mot-clé exclu trouvé dans un libellé (après pliage et retrait des exceptions), ou null.
     */
    public function matchKeyword(string $regime, string $label): ?string
    {
        $pattern = $this->pattern($regime);
        if ($pattern === null) {
            return null;
        }

        $folded = $this->stripExceptions(self::fold($label), (array) ($this->rules($regime)['exceptions_mots_cles'] ?? []));
        if ($folded === '') {
            return null;
        }

        return preg_match($pattern, $folded, $m) ? $m[1] : null;
    }

    /**
     * Cherche des mots-clés libres (allergènes / aliments exclus du profil) dans un libellé.
     *
     * @param  array<int, string>  $keywords
     */
    public static function matchesAny(string $label, array $keywords): ?string
    {
        $folded = self::fold($label);
        if ($folded === '') {
            return null;
        }

        foreach ($keywords as $keyword) {
            $kw = self::fold((string) $keyword);
            if ($kw === '' || mb_strlen($kw) < 3) {
                continue;
            }
            if (preg_match('/(?<![a-z0-9])'.preg_quote($kw, '/').'(?:s|x|es)?(?![a-z0-9])/u', $folded)) {
                return (string) $keyword;
            }
        }

        return null;
    }

    /**
     * Catégories de fréquence auxquelles appartient un libellé (viande, viande_rouge, poisson…).
     *
     * @return list<string>
     */
    public function categoriesOf(string $label): array
    {
        $out = [];
        foreach (self::CATEGORIES_FREQUENCE as $categorie => $mots) {
            if (self::matchesAny($label, $mots) !== null) {
                $out[] = $categorie;
            }
        }

        return $out;
    }

    /**
     * Pliage d'un libellé : minuscules, sans accent, ponctuation/apostrophes remplacées par des espaces.
     */
    public static function fold(string $value): string
    {
        $ascii = mb_strtolower(Str::ascii($value));
        $ascii = preg_replace('/[\'’`´"\-_,;:().!?\/\\\\|+*%°]+/u', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/\s+/u', ' ', $ascii) ?? $ascii;

        return trim($ascii);
    }

    /**
     * Heure d'un repas (HH:MM) : consumed_at dans le fuseau, sinon heure par défaut (estimation).
     *
     * @return array{0: string, 1: bool} [heure, is_estimate]
     */
    public function mealTime(Meal $meal, string $tz): array
    {
        if ($meal->consumed_at !== null) {
            return [$meal->consumed_at->copy()->setTimezone($tz)->format('H:i'), false];
        }

        $type = $meal->type instanceof MealType ? $meal->type->value : (string) $meal->type;

        return [MealCalculator::defaultHour($type), true];
    }

    // ------------------------------------------------------------------------------------
    // Cœur du scoring
    // ------------------------------------------------------------------------------------

    /**
     * @param  Collection<string, Collection<int, Meal>>  $byDate
     * @param  list<string>  $completeDays
     * @return array{score_pct: int, ecarts: list<array<string, mixed>>, raisons: list<string>, is_estimate: bool}
     */
    private function score(string $regime, Collection $byDate, array $completeDays, string $tz): array
    {
        $rules = $this->rules($regime);
        $nom = (string) config("diets.$regime.nom", $regime);
        $checks = [];
        $isEstimate = false;

        // Repas des jours complets uniquement (les jours incomplets ne pénalisent pas).
        $meals = collect($completeDays)->flatMap(fn (string $d) => $byDate->get($d, collect()))->values();

        // 1. Exclusions — par item.
        if ($this->hasExclusions($regime)) {
            foreach ($meals as $meal) {
                foreach ($meal->items as $item) {
                    if ($item->is_estimate) {
                        $isEstimate = true;
                    }
                    $violation = $this->exclusionViolation($regime, $item);
                    $checks[] = $this->check('exclusion', self::POIDS_EXCLUSION, $violation === null, $violation === null ? null : [
                        'date' => $meal->date->format('Y-m-d'),
                        'meal_type' => $this->type($meal),
                        'item_label' => (string) $item->label,
                        'regle' => 'exclusion',
                        'explication' => $violation,
                    ]);
                }
            }
        }

        // 2. Fenêtre alimentaire — par repas.
        if (! empty($rules['fenetre_alimentaire'])) {
            $debut = (string) ($rules['fenetre_alimentaire']['debut'] ?? '12:00');
            $fin = (string) ($rules['fenetre_alimentaire']['fin'] ?? '20:00');
            foreach ($meals as $meal) {
                [$heure, $estimee] = $this->mealTime($meal, $tz);
                if ($estimee) {
                    $isEstimate = true;
                }
                $ok = $heure >= $debut && $heure <= $fin;
                $checks[] = $this->check('fenetre', self::POIDS_FENETRE, $ok, $ok ? null : [
                    'date' => $meal->date->format('Y-m-d'),
                    'meal_type' => $this->type($meal),
                    'item_label' => null,
                    'regle' => 'fenetre_alimentaire',
                    'explication' => sprintf('Repas à %s%s, hors de la fenêtre %s–%s.', $heure, $estimee ? ' (heure estimée)' : '', $debut, $fin),
                    'is_estimate' => $estimee,
                ]);
            }
        }

        // 3. Fréquence — par semaine et catégorie.
        if (! empty($rules['frequence_max_par_semaine'])) {
            foreach ((array) $rules['frequence_max_par_semaine'] as $categorie => $max) {
                $max = (int) $max;
                $parSemaine = [];
                foreach ($meals as $meal) {
                    $itemHit = $this->firstItemOfCategory($meal, (string) $categorie);
                    if ($itemHit === null) {
                        continue;
                    }
                    $week = CarbonImmutable::parse($meal->date->format('Y-m-d'))->startOfWeek(CarbonInterface::MONDAY)->toDateString();
                    $parSemaine[$week][] = [$meal, $itemHit];
                }
                $weeks = collect($completeDays)
                    ->map(fn (string $d) => CarbonImmutable::parse($d)->startOfWeek(CarbonInterface::MONDAY)->toDateString())
                    ->unique();
                foreach ($weeks as $week) {
                    $hits = $parSemaine[$week] ?? [];
                    $ok = count($hits) <= $max;
                    $ecart = null;
                    if (! $ok) {
                        [$meal, $item] = $hits[$max];
                        $ecart = [
                            'date' => $meal->date->format('Y-m-d'),
                            'meal_type' => $this->type($meal),
                            'item_label' => (string) $item->label,
                            'regle' => 'frequence_'.$categorie,
                            'explication' => sprintf('%d repas avec %s dans la semaine du %s (maximum %d pour le régime %s).',
                                count($hits), $this->categorieLabel((string) $categorie), $this->dateFr($week), $max, $nom),
                        ];
                    }
                    $checks[] = $this->check('frequence', self::POIDS_FREQUENCE, $ok, $ecart);
                }
            }
        }

        // 4 & 5. Règles journalières (sel / sucres / fibres) et % macros — par jour complet.
        foreach ($completeDays as $date) {
            $dayMeals = $byDate->get($date, collect());
            $totals = $this->dayTotals($dayMeals);
            if ($totals['is_partial']) {
                $isEstimate = true;
            }
            $kcal = $totals['calories'];

            if (isset($rules['sel_max_g_jour']) && $totals['salt'] !== null) {
                $ok = $totals['salt'] <= (float) $rules['sel_max_g_jour'];
                $checks[] = $this->check('jour', self::POIDS_JOUR, $ok, $ok ? null : $this->dayEcart($date, 'sel_max_g_jour',
                    sprintf('%s g de sel sur la journée (maximum %s g).', $this->fr($totals['salt'], 1), $this->fr((float) $rules['sel_max_g_jour'], 2))));
            }
            if (isset($rules['sucres_pct_max']) && $totals['sugar'] !== null && $kcal > 0) {
                $pct = $totals['sugar'] * 4 / $kcal * 100;
                $ok = $pct <= (float) $rules['sucres_pct_max'];
                $checks[] = $this->check('jour', self::POIDS_JOUR, $ok, $ok ? null : $this->dayEcart($date, 'sucres_pct_max',
                    sprintf('%s %% des calories viennent des sucres (maximum %s %%).', $this->fr($pct), $this->fr((float) $rules['sucres_pct_max']))));
            }
            if (isset($rules['fibres_min_g_jour']) && $totals['fiber'] !== null) {
                $ok = $totals['fiber'] >= (float) $rules['fibres_min_g_jour'];
                $checks[] = $this->check('jour', self::POIDS_JOUR, $ok, $ok ? null : $this->dayEcart($date, 'fibres_min_g_jour',
                    sprintf('%s g de fibres sur la journée (minimum %s g).', $this->fr($totals['fiber'], 1), $this->fr((float) $rules['fibres_min_g_jour']))));
            }

            if ($kcal <= 0) {
                continue;
            }
            if (isset($rules['glucides_max_g'])) {
                $ok = $totals['carbs'] <= (float) $rules['glucides_max_g'];
                $checks[] = $this->check('macros', self::POIDS_MACROS, $ok, $ok ? null : $this->dayEcart($date, 'glucides_max_g',
                    sprintf('%s g de glucides sur la journée (maximum %s g).', $this->fr($totals['carbs']), $this->fr((float) $rules['glucides_max_g']))));
            }
            if (isset($rules['glucides_min_g'])) {
                $ok = $totals['carbs'] >= (float) $rules['glucides_min_g'];
                $checks[] = $this->check('macros', self::POIDS_MACROS, $ok, $ok ? null : $this->dayEcart($date, 'glucides_min_g',
                    sprintf('%s g de glucides sur la journée (minimum %s g).', $this->fr($totals['carbs']), $this->fr((float) $rules['glucides_min_g']))));
            }
            foreach (['glucides_pct' => ['carbs', 4, 'glucides'], 'lipides_pct' => ['fat', 9, 'lipides'], 'proteines_pct' => ['proteins', 4, 'protéines']] as $rule => [$macro, $factor, $libelle]) {
                if (! isset($rules[$rule]) || ! is_array($rules[$rule]) || count($rules[$rule]) !== 2) {
                    continue;
                }
                [$min, $max] = array_map('floatval', array_values($rules[$rule]));
                $pct = $totals[$macro] * $factor / $kcal * 100;
                $ok = $pct >= $min && $pct <= $max;
                $checks[] = $this->check('macros', self::POIDS_MACROS, $ok, $ok ? null : $this->dayEcart($date, $rule,
                    sprintf('%s %% des calories viennent des %s (attendu entre %s et %s %%).', $this->fr($pct), $libelle, $this->fr($min), $this->fr($max))));
            }
        }

        // Score pondéré.
        $total = 0.0;
        $gagne = 0.0;
        foreach ($checks as $c) {
            $total += $c['poids'];
            $gagne += $c['ok'] ? $c['poids'] : 0.0;
        }
        $score = $total > 0 ? (int) round($gagne / $total * 100) : 100;

        // Écarts triés par précédence puis date.
        $ecarts = array_values(array_filter(array_map(fn ($c) => $c['ecart'], $checks)));
        usort($ecarts, fn ($a, $b) => [self::ORDRE_GROUPES[$a['_groupe']] ?? 9, $a['date']] <=> [self::ORDRE_GROUPES[$b['_groupe']] ?? 9, $b['date']]);
        $ecarts = array_map(function (array $e) {
            unset($e['_groupe']);

            return $e;
        }, $ecarts);

        return [
            'score_pct' => max(0, min(100, $score)),
            'ecarts' => $ecarts,
            'raisons' => $this->raisons($regime, $checks, $rules),
            'is_estimate' => $isEstimate,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $ecart
     * @return array{groupe: string, poids: float, ok: bool, ecart: array<string, mixed>|null}
     */
    private function check(string $groupe, float $poids, bool $ok, ?array $ecart): array
    {
        if ($ecart !== null) {
            $ecart['_groupe'] = $groupe;
        }

        return ['groupe' => $groupe, 'poids' => $poids, 'ok' => $ok, 'ecart' => $ecart];
    }

    /** @return array<string, mixed> */
    private function dayEcart(string $date, string $regle, string $explication): array
    {
        return [
            'date' => $date,
            'meal_type' => null,
            'item_label' => null,
            'regle' => $regle,
            'explication' => $explication,
        ];
    }

    /**
     * Raisons positives (≤ 3) pour les régimes proches : groupes de règles entièrement respectés.
     *
     * @param  list<array{groupe: string, poids: float, ok: bool, ecart: array<string, mixed>|null}>  $checks
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    private function raisons(string $regime, array $checks, array $rules): array
    {
        $groupes = collect($checks)->groupBy('groupe');
        $raisons = [];

        $allOk = fn (string $g) => $groupes->has($g) && $groupes->get($g)->every(fn ($c) => $c['ok']);
        $ratio = function (string $g) use ($groupes): string {
            $items = $groupes->get($g, collect());
            $ok = $items->filter(fn ($c) => $c['ok'])->count();

            return sprintf('%d/%d', $ok, $items->count());
        };

        if ($this->hasExclusions($regime)) {
            $raisons[] = $allOk('exclusion') ? self::RAISON_AUCUNE_EXCLUSION : sprintf('%s items sans ingrédient exclu', $ratio('exclusion'));
        }
        if (! empty($rules['fenetre_alimentaire']) && $groupes->has('fenetre')) {
            $f = $rules['fenetre_alimentaire'];
            $raisons[] = $allOk('fenetre')
                ? sprintf('tous les repas dans la fenêtre %s–%s', $f['debut'] ?? '12:00', $f['fin'] ?? '20:00')
                : sprintf('%s repas dans la fenêtre %s–%s', $ratio('fenetre'), $f['debut'] ?? '12:00', $f['fin'] ?? '20:00');
        }
        if (! empty($rules['frequence_max_par_semaine']) && $groupes->has('frequence')) {
            $cats = implode(', ', array_map(fn ($c) => $this->categorieLabel((string) $c), array_keys((array) $rules['frequence_max_par_semaine'])));
            $raisons[] = $allOk('frequence') ? sprintf('fréquence respectée (%s)', $cats) : sprintf('%s semaines dans la fréquence (%s)', $ratio('frequence'), $cats);
        }
        if ($groupes->has('macros')) {
            $libelles = [];
            if (isset($rules['glucides_max_g'])) {
                $libelles[] = sprintf('glucides ≤ %s g/jour', $this->fr((float) $rules['glucides_max_g']));
            }
            if (isset($rules['glucides_pct'])) {
                $libelles[] = sprintf('glucides %s–%s %%', $this->fr((float) $rules['glucides_pct'][0]), $this->fr((float) $rules['glucides_pct'][1]));
            }
            if (isset($rules['lipides_pct'])) {
                $libelles[] = sprintf('lipides %s–%s %%', $this->fr((float) $rules['lipides_pct'][0]), $this->fr((float) $rules['lipides_pct'][1]));
            }
            $raisons[] = ($allOk('macros') ? 'répartition respectée : ' : $ratio('macros').' contrôles de répartition réussis : ').implode(', ', $libelles);
        }
        if ($groupes->has('jour')) {
            $raisons[] = $allOk('jour') ? 'repères sel / sucres / fibres respectés' : sprintf('%s repères sel / sucres / fibres respectés', $ratio('jour'));
        }

        return array_values(array_filter($raisons));
    }

    /**
     * Totaux d'une journée (items déjà chargés).
     *
     * @param  Collection<int, Meal>  $dayMeals
     * @return array{calories: float, proteins: float, carbs: float, fat: float, fiber: float|null, sugar: float|null, salt: float|null, is_partial: bool}
     */
    private function dayTotals(Collection $dayMeals): array
    {
        $totals = ['calories' => 0.0, 'proteins' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        $extras = ['fiber' => null, 'sugar' => null, 'salt' => null];
        $partial = false;

        foreach ($dayMeals as $meal) {
            foreach ($meal->items as $item) {
                foreach ($totals as $k => $v) {
                    $totals[$k] += (float) ($item->{$k} ?? 0);
                }
                foreach ($extras as $k => $v) {
                    if ($item->{$k} !== null) {
                        $extras[$k] = ($extras[$k] ?? 0.0) + (float) $item->{$k};
                    } else {
                        $partial = true;
                    }
                }
            }
        }

        return $totals + $extras + ['is_partial' => $partial];
    }

    private function firstItemOfCategory(Meal $meal, string $categorie): ?MealItem
    {
        $mots = self::CATEGORIES_FREQUENCE[$categorie] ?? [$categorie];

        foreach ($meal->items as $item) {
            $labels = [(string) $item->label];
            $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;
            $recipe = $item->relationLoaded('recipe') ? $item->getRelation('recipe') : null;
            if ($food instanceof Food) {
                $labels[] = (string) $food->name;
            }
            if ($recipe instanceof Recipe) {
                $labels = array_merge($labels, $this->ingredientNames($recipe));
            }
            foreach ($labels as $label) {
                if (self::matchesAny($label, $mots) !== null) {
                    return $item;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function ingredientNames(Recipe $recipe): array
    {
        $out = [];
        foreach ((array) ($recipe->ingredients ?? []) as $ingredient) {
            $name = is_array($ingredient) ? ($ingredient['name'] ?? null) : $ingredient;
            if (is_string($name) && trim($name) !== '') {
                $out[] = trim($name);
            }
        }

        return $out;
    }

    /**
     * Tag exclu porté par l'aliment (allergens / category), ou null.
     *
     * @param  array<string, mixed>  $rules
     */
    private function tagViolation(Food $food, array $rules): ?string
    {
        $tags = array_map(
            fn ($t) => $this->normalizeTag((string) $t),
            array_merge((array) ($rules['exclure_allergenes_tags'] ?? []), (array) ($rules['exclure_categories_tags'] ?? []))
        );
        $tags = array_values(array_unique(array_filter($tags)));

        $owned = [];
        foreach ((array) ($food->allergens ?? []) as $a) {
            $owned[] = $this->normalizeTag((string) $a);
        }
        if (is_string($food->category) && $food->category !== '') {
            $owned[] = $this->normalizeTag($food->category);
        }

        foreach ($owned as $tag) {
            if ($tag !== '' && in_array($tag, $tags, true)) {
                return 'en:'.$tag;
            }
        }

        $categories = (array) ($rules['exclure_categories'] ?? []);
        if ($categories !== [] && is_string($food->category) && $food->category !== '') {
            $hit = self::matchesAny($food->category, $categories);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    private function normalizeTag(string $tag): string
    {
        $tag = mb_strtolower(trim($tag));

        return preg_replace('/^[a-z]{2}:/', '', $tag) ?? $tag;
    }

    /**
     * Expression régulière des mots-clés exclus d'un régime (mots entiers, pluriel toléré), ou null.
     */
    private function pattern(string $regime): ?string
    {
        if (array_key_exists($regime, $this->patterns)) {
            return $this->patterns[$regime];
        }

        $mots = array_values(array_unique(array_filter(array_map(
            fn ($m) => self::fold((string) $m),
            (array) ($this->rules($regime)['exclure_mots_cles'] ?? [])
        ))));

        if ($mots === []) {
            return $this->patterns[$regime] = null;
        }

        usort($mots, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $alternatives = implode('|', array_map(fn ($m) => preg_quote($m, '/'), $mots));

        return $this->patterns[$regime] = '/(?<![a-z0-9])('.$alternatives.')(?:s|x|es)?(?![a-z0-9])/u';
    }

    /**
     * Retire du libellé plié les expressions d'exception (« lait de coco »…).
     *
     * @param  array<int, string>  $exceptions
     */
    private function stripExceptions(string $folded, array $exceptions): string
    {
        foreach ($exceptions as $exception) {
            $e = self::fold((string) $exception);
            if ($e === '') {
                continue;
            }
            $folded = preg_replace('/(?<![a-z0-9])'.preg_quote($e, '/').'(?:s|x|es)?(?![a-z0-9])/u', ' ', $folded) ?? $folded;
        }

        return trim(preg_replace('/\s+/u', ' ', $folded) ?? $folded);
    }

    private function type(Meal $meal): string
    {
        return $meal->type instanceof MealType ? $meal->type->value : (string) $meal->type;
    }

    private function categorieLabel(string $categorie): string
    {
        return match ($categorie) {
            'viande' => 'de la viande',
            'viande_rouge' => 'de la viande rouge',
            'poisson' => 'du poisson',
            default => str_replace('_', ' ', $categorie),
        };
    }

    private function dateFr(string $ymd): string
    {
        return CarbonImmutable::parse($ymd)->locale('fr')->translatedFormat('j F');
    }

    private function fr(float $value, int $decimals = 0): string
    {
        $formatted = number_format($value, $decimals, ',', ' ');
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
