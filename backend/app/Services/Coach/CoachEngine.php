<?php

namespace App\Services\Coach;

use App\Enums\MealType;
use App\Models\Food;
use App\Models\Meal;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\Recommendation;
use App\Models\StockItem;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Diets\DietEvaluator;
use App\Services\MealBudget;
use App\Services\MealCalculator;
use App\Support\OwnerScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Moteur de recommandations du coach (brief §8). Construit au plus 6 lignes par jour,
 * les enregistre par upsert (statut préservé) et retire les lignes « new » devenues obsolètes.
 *
 * Priorités : 1 sécurité, 2 objectif du jour, 3 confort.
 */
class CoachEngine
{
    public const MAX_PAR_JOUR = 6;
    public const LOCK_SECONDS = 60;

    public const SEUIL_HEURE_SOIR = 20;
    public const SEUIL_HEURE_PROTEINES = 15;
    public const PROTEINES_MANQUE_MIN_G = 25;
    public const PROTEINES_ALIMENT_MIN_100G = 12.0;
    public const GLUCIDES_ALIMENT_MIN_100G = 15.0;
    public const BUDGET_RESTANT_PCT = 0.40;
    public const DEPASSEMENT_P1_PCT = 0.25;
    public const DDM_TOLERANCE_JOURS = 30;
    public const SUGGESTION_MIN = 0.6;
    public const SUGGESTION_MAX = 1.1;
    public const PORTION_MIN = 0.5;
    public const PORTION_MAX = 2.0;
    public const SPORT_PRE_DUREE_MIN = 30;
    public const SPORT_PRE_FENETRE_H = [3, 1];
    public const SPORT_POST_PROTEINES_MIN_G = 20;
    public const SPORT_POST_FENETRE_H = 2;
    public const SPORT_POST_DELAI_MAX_H = 3;

    /** Ordre des règles pour départager une même priorité. */
    public const ORDRE_TYPES = [
        'profil_incomplet', 'sous_plancher', 'produit_perime', 'alerte_budget', 'budget_restant', 'manque_proteines',
        'anti_gaspillage', 'suggestion_repas', 'ajustement_portions', 'ddm_depassee', 'sport_pre', 'sport_post',
        'courses', 'hydratation',
    ];

    /** Allergènes du profil (français, pliés) → tags Open Food Facts. */
    public const ALLERGENES_TAGS = [
        'gluten' => 'en:gluten', 'ble' => 'en:gluten', 'lait' => 'en:milk', 'lactose' => 'en:milk', 'oeuf' => 'en:eggs',
        'oeufs' => 'en:eggs', 'arachide' => 'en:peanuts', 'arachides' => 'en:peanuts', 'cacahuete' => 'en:peanuts',
        'soja' => 'en:soybeans', 'fruits a coque' => 'en:nuts', 'noix' => 'en:nuts', 'amande' => 'en:nuts',
        'noisette' => 'en:nuts', 'poisson' => 'en:fish', 'crustaces' => 'en:crustaceans', 'crustace' => 'en:crustaceans',
        'crevette' => 'en:crustaceans', 'mollusques' => 'en:molluscs', 'sesame' => 'en:sesame-seeds', 'celeri' => 'en:celery',
        'moutarde' => 'en:mustard', 'lupin' => 'en:lupin', 'sulfites' => 'en:sulphur-dioxide-and-sulphites',
    ];

    private ?EloquentCollection $recipes = null;

    public function __construct(
        private readonly MealCalculator $calculator,
        private readonly MealBudget $budget,
        private readonly DietEvaluator $diets,
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Points d'entrée
    // ------------------------------------------------------------------------------------

    /**
     * Régénère au plus une fois par minute par (utilisateur, date). Retourne vrai si générée.
     */
    public function generateIfDue(User $user, string $date, ?CoachContext $context = null): bool
    {
        $lock = Cache::lock(sprintf('reco:%d:%s', $user->id, $date), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        // Le verrou n'est volontairement pas libéré : il expire seul après 60 s.
        $this->forDay($user, $date, $context);

        return true;
    }

    /**
     * Construit, enregistre et retourne les recommandations du jour (statut préservé).
     *
     * @return EloquentCollection<int, Recommendation>
     */
    public function forDay(User $user, string $date, ?CoachContext $context = null): EloquentCollection
    {
        $context ??= $this->context($user, $date);
        $rows = $this->rows($context);

        $now = Carbon::now();
        $payload = [];
        $keys = [];
        foreach ($rows as $row) {
            $key = Recommendation::dedupeKey($row['type'], $row['title']);
            $keys[] = $key;
            $payload[] = [
                'user_id' => $user->id,
                'date' => $date,
                'type' => $row['type'],
                'dedupe_key' => $key,
                'title' => $row['title'],
                'message' => $row['message'],
                'factors' => json_encode($row['factors'], JSON_UNESCAPED_UNICODE),
                'actions' => json_encode($row['actions'], JSON_UNESCAPED_UNICODE),
                'priority' => $row['priority'],
                'is_estimate' => $row['is_estimate'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload !== []) {
            Recommendation::query()->upsert(
                $payload,
                ['user_id', 'date', 'dedupe_key'],
                ['message', 'factors', 'actions', 'priority', 'is_estimate', 'updated_at']
            );
        }

        // Lignes « new » qui ne sont plus d'actualité : retirées. Les acceptées/ignorées restent.
        Recommendation::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('status', 'new')
            ->when($keys !== [], fn ($q) => $q->whereNotIn('dedupe_key', $keys))
            ->delete();

        return $this->listForDay($user, $date, true);
    }

    /**
     * Recommandations enregistrées du jour, par priorité (statut ≠ ignoree sauf $all).
     *
     * @return EloquentCollection<int, Recommendation>
     */
    public function listForDay(User $user, string $date, bool $all = false, ?int $limit = null): EloquentCollection
    {
        return Recommendation::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->when(! $all, fn ($q) => $q->where('status', '!=', 'ignoree'))
            ->orderBy('priority')
            ->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();
    }

    /**
     * Contexte d'évaluation (le tableau de bord passe ses collections déjà chargées).
     *
     * @param  array<string, mixed>  $preloaded
     */
    public function context(User $user, string $date, array $preloaded = []): CoachContext
    {
        return CoachContext::build($user, $date, $this->calculator, $preloaded);
    }

    /**
     * Lignes candidates triées et plafonnées (sans écriture).
     *
     * @return list<array{type: string, title: string, message: string, factors: list<array<string, mixed>>, actions: list<array<string, mixed>>, priority: int, is_estimate: bool}>
     */
    public function rows(CoachContext $ctx): array
    {
        $this->recipes = null;
        $rows = [];

        $push = function (?array $row) use (&$rows) {
            if ($row !== null) {
                $rows[] = $row;
            }
        };
        $pushAll = function (array $list) use (&$rows) {
            foreach ($list as $row) {
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        };

        $push($this->profilIncomplet($ctx));
        $push($this->sousPlancher($ctx));
        $pushAll($this->produitsPerimes($ctx));
        $push($this->alerteBudget($ctx));
        $push($this->budgetRestant($ctx));
        $push($this->manqueProteines($ctx));
        $pushAll($this->antiGaspillage($ctx));
        $push($this->suggestionRepas($ctx));
        $push($this->ajustementPortions($ctx));
        $pushAll($this->sportPre($ctx));
        $push($this->sportPost($ctx));
        $push($this->courses($ctx));
        $push($this->hydratation($ctx));

        // Dédoublonnage défensif sur (type, titre).
        $seen = [];
        $rows = array_values(array_filter($rows, function (array $row) use (&$seen) {
            $key = $row['type'].'|'.$row['title'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));

        $order = array_flip(self::ORDRE_TYPES);
        usort($rows, fn ($a, $b) => [$a['priority'], $order[$a['type']] ?? 99] <=> [$b['priority'], $order[$b['type']] ?? 99]);

        return array_slice($rows, 0, self::MAX_PAR_JOUR);
    }

    // ------------------------------------------------------------------------------------
    // Règles — sécurité (p1)
    // ------------------------------------------------------------------------------------

    private function profilIncomplet(CoachContext $ctx): ?array
    {
        if ($ctx->hasProfile) {
            return null;
        }

        [$title, $message] = RecommendationCopy::profilIncomplet();

        return $this->row('profil_incomplet', $title, $message, [], [], 1, false);
    }

    private function sousPlancher(CoachContext $ctx): ?array
    {
        if (! $ctx->hasProfile || $ctx->hour < self::SEUIL_HEURE_SOIR) {
            return null;
        }
        $plancher = (int) ($ctx->summary['plancher_kcal'] ?? 0);
        $consumed = (float) ($ctx->summary['totals']['calories'] ?? 0);
        $loggedCount = count($ctx->summary['logged_types']);

        if ($plancher <= 0 || $loggedCount < 2 || $consumed >= $plancher) {
            return null;
        }

        $food = $this->stockFoods($ctx, fn (Food $f) => (float) $f->calories >= 50)->first();
        $exemple = $food?->name ?? RecommendationCopy::EXEMPLE_COLLATION;

        [$title, $message] = RecommendationCopy::sousPlancher((int) round($consumed), $plancher, $exemple);

        $actions = [];
        if ($food !== null) {
            $actions[] = $this->actionAjouterAliment($food, 100.0, 'collation');
        }

        return $this->row('sous_plancher', $title, $message, [
            $this->factor('calories_consommees', round($consumed), 'kcal'),
            $this->factor('plancher_kcal', $plancher, 'kcal'),
            $this->factor('repas_enregistres', $loggedCount),
            $this->factor('heure', $ctx->hour, 'h'),
        ], $actions, 1, true);
    }

    /** @return list<array<string, mixed>> */
    private function produitsPerimes(CoachContext $ctx): array
    {
        $rows = [];

        foreach ($ctx->availableStock() as $item) {
            $days = $ctx->daysLeft($item);
            if ($days === null || $days >= 0) {
                continue;
            }
            $label = $this->stockLabel($item);
            $date = $item->expires_at->format('Y-m-d');
            $factors = [
                $this->factor('expires_at', $date),
                $this->factor('jours_depasses', abs($days), 'j'),
                $this->factor('expiry_kind', (string) $item->expiry_kind),
            ];

            if ($item->expiry_kind === 'ddm' && abs($days) <= self::DDM_TOLERANCE_JOURS) {
                [$title, $message] = RecommendationCopy::ddmDepassee($label, $date);
                $rows[] = $this->row('ddm_depassee', $title, $message, $factors, [
                    $this->action('ouvrir_stock', ['stock_item_ids' => [$item->id]], RecommendationCopy::actionOuvrirStock()),
                ], 3, true);

                continue;
            }

            [$title, $message] = RecommendationCopy::produitPerime($label, $date);
            $rows[] = $this->row('produit_perime', $title, $message, $factors, [
                $this->action('supprimer_stock', ['stock_item_id' => $item->id], RecommendationCopy::actionSupprimerStock($label)),
            ], 1, false);
        }

        return $rows;
    }

    private function alerteBudget(CoachContext $ctx): ?array
    {
        if (! $ctx->budgetRulesAllowed()) {
            return null;
        }
        $targets = (float) ($ctx->summary['targets']['calories'] ?? 0);
        if ($targets <= 0) {
            return null;
        }
        $bonus = (float) ($ctx->summary['sport']['calories_bonus'] ?? 0);
        $consumed = (float) ($ctx->summary['totals']['calories'] ?? 0);
        $tolerance = max(100.0, 0.10 * $targets);

        if ($consumed <= $targets + $bonus + $tolerance) {
            return null;
        }

        $depassement = (int) round($consumed - $targets - $bonus);
        $factors = [
            $this->factor('calories_consommees', round($consumed), 'kcal'),
            $this->factor('calories_cibles', round($targets), 'kcal'),
            $this->factor('calories_bonus_sport', round($bonus), 'kcal'),
            $this->factor('tolerance', round($tolerance), 'kcal'),
            $this->factor('depassement', $depassement, 'kcal'),
        ];

        if ($ctx->objectif() === 'prendre') {
            [$title, $message] = RecommendationCopy::depassementPrise($depassement);

            return $this->row('alerte_budget', $title, $message, $factors, [], 3, true);
        }

        $priority = $depassement > self::DEPASSEMENT_P1_PCT * $targets ? 1 : 2;
        [$title, $message] = RecommendationCopy::alerteBudget($depassement);

        return $this->row('alerte_budget', $title, $message, $factors, [
            $this->action('generer_seance', [], RecommendationCopy::actionGenererSeance()),
        ], $priority, true);
    }

    // ------------------------------------------------------------------------------------
    // Règles — objectif du jour (p2)
    // ------------------------------------------------------------------------------------

    private function budgetRestant(CoachContext $ctx): ?array
    {
        if (! $ctx->hasProfile || $ctx->hour < self::SEUIL_HEURE_SOIR) {
            return null;
        }
        $targets = (float) ($ctx->summary['targets']['calories'] ?? 0);
        $remaining = (float) ($ctx->summary['remaining']['calories'] ?? 0);
        $consumed = (float) ($ctx->summary['totals']['calories'] ?? 0);
        $plancher = (int) ($ctx->summary['plancher_kcal'] ?? 0);

        if ($targets <= 0 || $remaining <= self::BUDGET_RESTANT_PCT * $targets || $consumed < $plancher) {
            return null;
        }

        $window = $ctx->fastingWindow();
        if ($window !== null) {
            $heure = $ctx->now->format('H:i');
            if ($heure < $window[0] || $heure > $window[1]) {
                return null;
            }
        }

        $food = $this->stockFoods($ctx, fn (Food $f) => (float) $f->calories > 0 && (float) $f->calories <= 250)->first();
        $exemple = $food?->name ?? RecommendationCopy::EXEMPLE_COLLATION;
        [$title, $message] = RecommendationCopy::budgetRestant((int) round($remaining), $exemple);

        $actions = [];
        if ($food !== null) {
            $actions[] = $this->actionAjouterAliment($food, 100.0, 'collation');
        }

        return $this->row('budget_restant', $title, $message, [
            $this->factor('calories_restantes', round($remaining), 'kcal'),
            $this->factor('calories_cibles', round($targets), 'kcal'),
            $this->factor('part_restante', round($remaining / $targets * 100), '%'),
            $this->factor('heure', $ctx->hour, 'h'),
        ], $actions, 2, true);
    }

    private function manqueProteines(CoachContext $ctx): ?array
    {
        if (! $ctx->hasProfile || $ctx->hour < self::SEUIL_HEURE_PROTEINES) {
            return null;
        }
        $missing = (float) ($ctx->summary['remaining']['proteins'] ?? 0);
        if ($missing <= self::PROTEINES_MANQUE_MIN_G) {
            return null;
        }

        $mealType = (string) $ctx->summary['next_meal_type'];
        $foods = $this->proteinFoods($ctx, 3);
        $actions = [];
        $names = [];
        foreach ($foods as $food) {
            $per100 = (float) $food->proteins;
            $grams = $per100 > 0 ? $missing / $per100 * 100 : 100;
            $grams = (float) max(30, min(200, round($grams / 10) * 10));
            $actions[] = $this->actionAjouterAliment($food, $grams, $mealType);
            $names[] = $food->name;
        }

        [$title, $message] = RecommendationCopy::manqueProteines((int) round($missing), $names);

        return $this->row('manque_proteines', $title, $message, [
            $this->factor('proteines_manquantes', round($missing), 'g'),
            $this->factor('proteines_cibles', round((float) ($ctx->summary['targets']['proteins'] ?? 0)), 'g'),
            $this->factor('proteines_consommees', round((float) ($ctx->summary['totals']['proteins'] ?? 0)), 'g'),
            $this->factor('heure', $ctx->hour, 'h'),
        ], $actions, 2, true);
    }

    /** @return list<array<string, mixed>> */
    private function antiGaspillage(CoachContext $ctx): array
    {
        $rows = [];
        $expiring = $this->expiringItems($ctx);

        foreach ($expiring->take(3) as $item) {
            $days = (int) $ctx->daysLeft($item);
            $label = $this->stockLabel($item);
            $factors = [
                $this->factor('expires_at', $item->expires_at->format('Y-m-d')),
                $this->factor('jours_restants', $days, 'j'),
                $this->factor('jours_alerte_peremption', $ctx->joursAlerte, 'j'),
            ];

            $recipe = $this->recipes($ctx)
                ->filter(fn (Recipe $r) => $this->recipeUsesItem($r, $item) && $this->recipeCompatible($r, $ctx))
                ->first();

            if ($recipe !== null) {
                [$title, $message] = RecommendationCopy::antiGaspillageRecette($label, $days, $recipe->title);
                $rows[] = $this->row('anti_gaspillage', $title, $message, $factors, [
                    $this->action('ouvrir_recette', ['recipe_id' => $recipe->id], RecommendationCopy::actionOuvrirRecette($recipe->title)),
                    $this->action('ouvrir_stock', ['stock_item_ids' => [$item->id]], RecommendationCopy::actionOuvrirStock()),
                ], 2, false);

                continue;
            }

            [$title, $message] = RecommendationCopy::antiGaspillageConsomme($label, $item->expires_at->format('Y-m-d'));
            $rows[] = $this->row('anti_gaspillage', $title, $message, $factors, [
                $this->action('ouvrir_stock', ['stock_item_ids' => [$item->id]], RecommendationCopy::actionOuvrirStock()),
            ], 2, false);
        }

        return $rows;
    }

    private function suggestionRepas(CoachContext $ctx): ?array
    {
        if (! $ctx->hasProfile) {
            return null;
        }
        $type = (string) $ctx->summary['next_meal_type'];
        $budget = $this->mealBudget($ctx, $type);
        if ($budget <= 0) {
            return null;
        }

        $expiring = $this->expiringItems($ctx);
        $candidates = $this->recipesForType($ctx, $type)
            ->map(function (Recipe $recipe) use ($budget, $expiring) {
                $kcal = (float) $recipe->perServing()['calories'];
                $uses = $expiring->filter(fn (StockItem $i) => $this->recipeUsesItem($recipe, $i))->values();

                return ['recipe' => $recipe, 'kcal' => $kcal, 'uses' => $uses, 'ecart' => abs($kcal - $budget)];
            })
            ->filter(fn (array $c) => $c['kcal'] > 0
                && $c['kcal'] >= self::SUGGESTION_MIN * $budget
                && $c['kcal'] <= self::SUGGESTION_MAX * $budget
                && $this->portionFactor($budget, $c['kcal']) !== null)
            ->sortBy(fn (array $c) => [$c['uses']->isEmpty() ? 1 : 0, $c['ecart']])
            ->values()
            ->take(3);

        $factors = [
            $this->factor('meal_type', $type),
            $this->factor('budget_repas', round($budget), 'kcal'),
            $this->factor('fenetre_kcal_min', round(self::SUGGESTION_MIN * $budget), 'kcal'),
            $this->factor('fenetre_kcal_max', round(self::SUGGESTION_MAX * $budget), 'kcal'),
            $this->factor('calories_restantes', round((float) ($ctx->summary['remaining']['calories'] ?? 0)), 'kcal'),
        ];

        if ($candidates->isNotEmpty()) {
            $best = $candidates->first();
            /** @var Recipe $recipe */
            $recipe = $best['recipe'];
            $facteur = (float) $this->portionFactor($budget, $best['kcal']);
            $itemLabel = $best['uses']->isNotEmpty() ? $this->stockLabel($best['uses']->first()) : null;

            $actions = [];
            foreach ($candidates as $c) {
                $f = (float) $this->portionFactor($budget, $c['kcal']);
                $actions[] = $this->action('ajouter_au_repas', [
                    'recipe_id' => $c['recipe']->id,
                    'quantity' => $f,
                    'unit' => 'portion',
                    'meal_type' => $type,
                ], RecommendationCopy::actionAjouterRecette($c['recipe']->title, RecommendationCopy::facteur($f)));
            }
            $actions[] = $this->action('ouvrir_recette', ['recipe_id' => $recipe->id], RecommendationCopy::actionOuvrirRecette($recipe->title));
            if ($best['uses']->isNotEmpty()) {
                $actions[] = $this->action('ouvrir_stock', [
                    'stock_item_ids' => $best['uses']->pluck('id')->map(fn ($id) => (int) $id)->all(),
                ], RecommendationCopy::actionOuvrirStock());
            }

            $factors[] = $this->factor('kcal_par_portion', round($best['kcal']), 'kcal');
            $factors[] = $this->factor('facteur_portion', $facteur);
            if ($itemLabel !== null) {
                $factors[] = $this->factor('utilise_stock_expirant', $itemLabel);
            }

            [$title, $message] = RecommendationCopy::suggestionRepas($type, (int) round($budget), $recipe->title, (int) round($best['kcal']), RecommendationCopy::facteur($facteur), $itemLabel);

            return $this->row('suggestion_repas', $title, $message, $factors, $actions, 2, true);
        }

        // Repli : idées simples du planificateur (config/meal_ideas.php), sans action.
        $idea = collect((array) config('meal_ideas', []))
            ->filter(fn (array $idea) => in_array($type, (array) ($idea['meal_types'] ?? []), true)
                && (float) $idea['calories'] >= self::SUGGESTION_MIN * $budget
                && (float) $idea['calories'] <= self::SUGGESTION_MAX * $budget
                && $this->ideaCompatible($idea, $ctx))
            ->sortBy(fn (array $idea) => abs((float) $idea['calories'] - $budget))
            ->first();

        if ($idea === null) {
            return null;
        }

        $factors[] = $this->factor('kcal_idee', (int) $idea['calories'], 'kcal');
        [$title, $message] = RecommendationCopy::suggestionIdee($type, (int) round($budget), (string) $idea['title'], (int) $idea['calories']);

        return $this->row('suggestion_repas', $title, $message, $factors, [], 2, true);
    }

    // ------------------------------------------------------------------------------------
    // Règles — confort (p3)
    // ------------------------------------------------------------------------------------

    private function ajustementPortions(CoachContext $ctx): ?array
    {
        if (! $ctx->budgetRulesAllowed()) {
            return null;
        }
        $type = (string) $ctx->summary['next_meal_type'];
        $budget = $this->mealBudget($ctx, $type);
        if ($budget <= 0) {
            return null;
        }

        $best = $this->recipesForType($ctx, $type)
            ->map(fn (Recipe $r) => ['recipe' => $r, 'kcal' => (float) $r->perServing()['calories']])
            ->filter(fn (array $c) => $c['kcal'] > self::SUGGESTION_MAX * $budget)
            ->sortBy('kcal')
            ->first();

        if ($best === null) {
            return null;
        }

        $facteur = $this->portionFactor($budget, $best['kcal']);
        if ($facteur === null) {
            return null;
        }

        /** @var Recipe $recipe */
        $recipe = $best['recipe'];
        [$title, $message] = RecommendationCopy::ajustementPortions(RecommendationCopy::facteur($facteur), $recipe->title);

        return $this->row('ajustement_portions', $title, $message, [
            $this->factor('meal_type', $type),
            $this->factor('budget_repas', round($budget), 'kcal'),
            $this->factor('kcal_par_portion', round($best['kcal']), 'kcal'),
            $this->factor('facteur_portion', $facteur),
        ], [
            $this->action('ajouter_au_repas', [
                'recipe_id' => $recipe->id,
                'quantity' => $facteur,
                'unit' => 'portion',
                'meal_type' => $type,
            ], RecommendationCopy::actionAjouterRecette($recipe->title, RecommendationCopy::facteur($facteur))),
        ], 3, true);
    }

    /** @return list<array<string, mixed>> */
    private function sportPre(CoachContext $ctx): array
    {
        if (! $ctx->isToday) {
            return [];
        }
        $rows = [];

        foreach ($ctx->sessions as $session) {
            if ($session->status !== 'prevue' || $session->planned_at === null || (int) $session->duration_min < self::SPORT_PRE_DUREE_MIN) {
                continue;
            }
            $planned = $this->plannedAt($ctx, $session);
            if ($planned === null) {
                continue;
            }
            $start = $planned->copy()->subHours(self::SPORT_PRE_FENETRE_H[0]);
            $end = $planned->copy()->subHours(self::SPORT_PRE_FENETRE_H[1]);
            if ($ctx->now->lt($start) || $ctx->now->gt($end)) {
                continue;
            }

            $heure = $planned->format('H:i');
            $title = (string) $session->title;
            $factors = [
                $this->factor('session_id', $session->id),
                $this->factor('planned_at', $heure),
                $this->factor('duration_min', (int) $session->duration_min, 'min'),
                $this->factor('poids_kg', $ctx->poids, 'kg'),
            ];

            $window = $ctx->fastingWindow();
            if ($window !== null && ($heure < $window[0] || $heure > $window[1])) {
                [$t, $m] = RecommendationCopy::sportPreJeune($title, $heure);
                $rows[] = $this->row('sport_pre', $t, $m, $factors, [], 3, true);

                continue;
            }

            if (in_array($ctx->regime, ['keto', 'low_carb'], true)) {
                [$t, $m] = RecommendationCopy::sportPreKeto($title, $heure);
                $rows[] = $this->row('sport_pre', $t, $m, $factors, [], 3, true);

                continue;
            }

            $gkg = (int) $session->duration_min < 45 ? 0.5 : 1.0;
            $g = (int) (round($gkg * $ctx->poids / 5) * 5);
            $food = $this->stockFoods($ctx, fn (Food $f) => (float) $f->carbs >= self::GLUCIDES_ALIMENT_MIN_100G)->first();
            $aliment = $food?->name ?? RecommendationCopy::EXEMPLE_GLUCIDES;
            $factors[] = $this->factor('glucides_g_par_kg', $gkg, 'g/kg');
            $factors[] = $this->factor('glucides_g', $g, 'g');

            $actions = [];
            if ($food !== null) {
                $carbs = (float) $food->carbs;
                $grams = $carbs > 0 ? (float) max(30, min(200, round($g / $carbs * 100 / 10) * 10)) : 100.0;
                $actions[] = $this->actionAjouterAliment($food, $grams, 'collation');
            }

            [$t, $m] = RecommendationCopy::sportPre($title, $heure, $g, $gkg, $aliment);
            $rows[] = $this->row('sport_pre', $t, $m, $factors, $actions, 3, true);
        }

        return $rows;
    }

    private function sportPost(CoachContext $ctx): ?array
    {
        if (! $ctx->isToday) {
            return null;
        }

        /** @var WorkoutSession|null $session */
        $session = $ctx->sessions
            ->filter(fn (WorkoutSession $s) => $s->status === 'terminee' && $s->completed_at !== null)
            ->sortByDesc(fn (WorkoutSession $s) => $s->completed_at->getTimestamp())
            ->first();

        if ($session === null) {
            return null;
        }

        $completed = $session->completed_at->copy()->setTimezone($ctx->timezone);
        if ($ctx->now->gt($completed->copy()->addHours(self::SPORT_POST_DELAI_MAX_H))) {
            return null;
        }

        $windowEnd = $completed->copy()->addHours(self::SPORT_POST_FENETRE_H);
        $proteins = 0.0;
        foreach ($ctx->meals as $meal) {
            $time = Carbon::parse($ctx->date.' '.$ctx->mealTime($meal), $ctx->timezone);
            if ($time->gt($completed) && $time->lte($windowEnd)) {
                foreach ($meal->items as $item) {
                    $proteins += (float) $item->proteins;
                }
            }
        }

        if ($proteins >= self::SPORT_POST_PROTEINES_MIN_G) {
            return null;
        }

        $g = (int) max(20, min(40, round(0.3 * $ctx->poids / 5) * 5));
        $food = $this->proteinFoods($ctx, 1)->first();
        $aliment = $food?->name ?? RecommendationCopy::EXEMPLE_PROTEINES;

        $actions = [];
        if ($food !== null) {
            $per100 = (float) $food->proteins;
            $grams = $per100 > 0 ? (float) max(30, min(200, round($g / $per100 * 100 / 10) * 10)) : 100.0;
            $actions[] = $this->actionAjouterAliment($food, $grams, (string) $ctx->summary['next_meal_type']);
        }

        [$title, $message] = RecommendationCopy::sportPost($g, $aliment);

        return $this->row('sport_post', $title, $message, [
            $this->factor('session_id', $session->id),
            $this->factor('completed_at', $completed->format('H:i')),
            $this->factor('proteines_apres_seance', round($proteins), 'g'),
            $this->factor('poids_kg', $ctx->poids, 'kg'),
            $this->factor('proteines_g', $g, 'g'),
        ], $actions, 3, true);
    }

    private function courses(CoachContext $ctx): ?array
    {
        $labels = [];
        $actions = [];

        foreach ($ctx->stockItems as $item) {
            if (! $item->isLow()) {
                continue;
            }
            $label = $this->stockLabel($item);
            if (in_array(mb_strtolower($label), array_map('mb_strtolower', $labels), true)) {
                continue;
            }
            $labels[] = $label;
            $actions[] = $this->action('ajouter_courses', array_filter([
                'label' => $label,
                'quantity' => $item->min_quantity !== null ? (float) $item->min_quantity : null,
                'unit' => $item->unit,
                'food_id' => $item->food_id,
            ], fn ($v) => $v !== null), RecommendationCopy::actionAjouterCourses($label));
        }

        // Ingrédients des recettes planifiées du jour absents du stock.
        $plans = OwnerScope::apply(MealPlan::query(), $ctx->user)
            ->where('date', $ctx->date)
            ->where('status', 'prevu')
            ->whereNotNull('recipe_id')
            ->with('recipe')
            ->get();

        foreach ($plans as $plan) {
            $recipe = $plan->recipe;
            if (! $recipe instanceof Recipe) {
                continue;
            }
            foreach ((array) $recipe->ingredients as $ingredient) {
                $name = is_array($ingredient) ? trim((string) ($ingredient['name'] ?? '')) : '';
                if ($name === '' || count($actions) >= 6) {
                    continue;
                }
                $inStock = $ctx->availableStock()->contains(fn (StockItem $i) => $this->labelsOverlap($this->stockLabel($i), $name)
                    || ($i->food_barcode && ! empty($ingredient['ean']) && (string) $i->food_barcode === (string) $ingredient['ean']));
                if ($inStock || in_array(mb_strtolower($name), array_map('mb_strtolower', $labels), true)) {
                    continue;
                }
                $labels[] = $name;
                $actions[] = $this->action('ajouter_courses', array_filter([
                    'label' => $name,
                    'quantity' => isset($ingredient['amount']) && is_numeric($ingredient['amount']) ? (float) $ingredient['amount'] : null,
                    'unit' => $ingredient['unit'] ?? null,
                ], fn ($v) => $v !== null), RecommendationCopy::actionAjouterCourses($name));
            }
        }

        if ($labels === []) {
            return null;
        }

        [$title, $message] = RecommendationCopy::courses(array_slice($labels, 0, 5));

        return $this->row('courses', $title, $message, [
            $this->factor('produits', count($labels)),
        ], array_slice($actions, 0, 6), 3, false);
    }

    private function hydratation(CoachContext $ctx): ?array
    {
        $hasSession = $ctx->sessions->contains(fn (WorkoutSession $s) => $s->status !== 'annulee');
        if (! $hasSession) {
            return null;
        }

        [$title, $message] = RecommendationCopy::hydratation();

        return $this->row('hydratation', $title, $message, [
            $this->factor('seances_du_jour', $ctx->sessions->count()),
        ], [], 3, false);
    }

    // ------------------------------------------------------------------------------------
    // Compatibilité (régime, allergènes, exclusions, mineurs)
    // ------------------------------------------------------------------------------------

    /**
     * Recette compatible avec le régime (exclusions §9), les allergènes et aliments exclus du profil ;
     * jamais keto/low_carb pour un mineur.
     */
    public function recipeCompatible(Recipe $recipe, CoachContext $ctx): bool
    {
        $tags = array_map('strval', (array) ($recipe->tags ?? []));
        if ($ctx->isMinor && array_intersect($tags, ['keto', 'low_carb']) !== []) {
            return false;
        }
        if ($ctx->regime && $this->diets->itemViolatesExclusions($ctx->regime, $recipe)) {
            return false;
        }

        $labels = [(string) $recipe->title];
        foreach ((array) $recipe->ingredients as $ingredient) {
            $name = is_array($ingredient) ? ($ingredient['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $labels[] = $name;
            }
        }

        return ! $this->hitsUserExclusions($labels, [], $ctx);
    }

    /**
     * Aliment compatible avec le régime, les allergènes (mots-clés + tags OFF) et les aliments exclus.
     */
    public function foodCompatible(Food $food, CoachContext $ctx): bool
    {
        if ($ctx->regime && $this->diets->itemViolatesExclusions($ctx->regime, $food)) {
            return false;
        }

        return ! $this->hitsUserExclusions([(string) $food->name], (array) ($food->allergens ?? []), $ctx);
    }

    /**
     * @param  list<string>  $labels
     * @param  array<int, string>  $tags  tags allergènes portés par l'aliment
     */
    private function hitsUserExclusions(array $labels, array $tags, CoachContext $ctx): bool
    {
        $allergenes = array_values(array_filter(array_map('strval', (array) ($ctx->profile?->allergenes ?? []))));
        $exclus = array_values(array_filter(array_map('strval', (array) ($ctx->profile?->aliments_exclus ?? []))));
        $keywords = array_merge($allergenes, $exclus);

        foreach ($labels as $label) {
            if ($keywords !== [] && DietEvaluator::matchesAny($label, $keywords) !== null) {
                return true;
            }
        }

        if ($tags !== [] && $allergenes !== []) {
            $ownedTags = array_map(fn ($t) => mb_strtolower((string) $t), $tags);
            foreach ($allergenes as $allergene) {
                $tag = self::ALLERGENES_TAGS[DietEvaluator::fold($allergene)] ?? null;
                if ($tag !== null && in_array($tag, $ownedTags, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $idea */
    private function ideaCompatible(array $idea, CoachContext $ctx): bool
    {
        $tags = array_map('strval', (array) ($idea['tags'] ?? []));
        if ($ctx->isMinor && array_intersect($tags, ['keto', 'low_carb']) !== []) {
            return false;
        }
        if ($ctx->regime && in_array($ctx->regime, ['vegan', 'vegetarien', 'sans_gluten', 'sans_lactose', 'halal', 'keto', 'low_carb'], true)
            && ! in_array($ctx->regime, $tags, true)) {
            return false;
        }
        if ($ctx->regime && $this->diets->matchKeyword($ctx->regime, (string) $idea['title']) !== null) {
            return false;
        }

        return ! $this->hitsUserExclusions([(string) $idea['title']], [], $ctx);
    }

    // ------------------------------------------------------------------------------------
    // Sélections
    // ------------------------------------------------------------------------------------

    /**
     * Recettes visibles (publiques + de l'utilisateur), chargées une fois par évaluation.
     *
     * @return EloquentCollection<int, Recipe>
     */
    private function recipes(CoachContext $ctx): EloquentCollection
    {
        return $this->recipes ??= Recipe::query()
            ->where(fn ($q) => $q->where('is_public', true)->orWhere('created_by_user_id', $ctx->user->id))
            ->orderByRaw('CASE WHEN created_by_user_id = ? THEN 0 ELSE 1 END', [$ctx->user->id])
            ->orderBy('id')
            ->get();
    }

    /**
     * Recettes compatibles proposables pour un type de repas.
     *
     * @return Collection<int, Recipe>
     */
    private function recipesForType(CoachContext $ctx, string $type): Collection
    {
        return $this->recipes($ctx)
            ->filter(function (Recipe $r) use ($type, $ctx) {
                $types = array_map('strval', (array) ($r->meal_types ?? []));
                if ($types !== [] && ! in_array($type, $types, true)) {
                    return false;
                }

                return (float) $r->calories > 0 && $this->recipeCompatible($r, $ctx);
            })
            ->values();
    }

    /**
     * Articles du stock qui expirent dans la fenêtre d'alerte (0 ≤ jours ≤ jours_alerte). Jamais les périmés.
     *
     * @return Collection<int, StockItem>
     */
    private function expiringItems(CoachContext $ctx): Collection
    {
        return $ctx->availableStock()
            ->filter(function (StockItem $item) use ($ctx) {
                $days = $ctx->daysLeft($item);

                return $days !== null && $days >= 0 && $days <= $ctx->joursAlerte;
            })
            ->sortBy(fn (StockItem $item) => [$ctx->daysLeft($item), $item->id])
            ->values();
    }

    /**
     * Aliments du stock (relation food chargée), non périmés, compatibles, filtrés par $filter.
     *
     * @return Collection<int, Food>
     */
    private function stockFoods(CoachContext $ctx, ?callable $filter = null): Collection
    {
        return $ctx->availableStock()
            ->filter(function (StockItem $item) use ($ctx) {
                $days = $ctx->daysLeft($item);

                return $item->relationLoaded('food') && $item->getRelation('food') instanceof Food && ($days === null || $days >= 0);
            })
            ->sortBy(fn (StockItem $item) => [$ctx->daysLeft($item) ?? 9999, $item->id])
            ->map(fn (StockItem $item) => $item->getRelation('food'))
            ->unique('id')
            ->filter(fn (Food $food) => $this->foodCompatible($food, $ctx) && ($filter === null || $filter($food)))
            ->values();
    }

    /**
     * Aliments riches en protéines (≥ 12 g/100 g) : stock d'abord, puis catalogue.
     *
     * @return Collection<int, Food>
     */
    private function proteinFoods(CoachContext $ctx, int $limit): Collection
    {
        $foods = $this->stockFoods($ctx, fn (Food $f) => (float) $f->proteins >= self::PROTEINES_ALIMENT_MIN_100G)->take($limit);

        if ($foods->count() < $limit) {
            $catalog = Food::query()
                ->where('proteins', '>=', self::PROTEINES_ALIMENT_MIN_100G)
                ->whereNotIn('id', $foods->pluck('id')->all() ?: [0])
                ->orderByDesc('is_verified')
                ->orderByDesc('proteins')
                ->limit(40)
                ->get()
                ->filter(fn (Food $f) => $this->foodCompatible($f, $ctx))
                ->take($limit - $foods->count());

            $foods = $foods->concat($catalog);
        }

        return $foods->values();
    }

    private function mealBudget(CoachContext $ctx, string $type): float
    {
        return $this->budget->budgetFromSummary(
            (float) ($ctx->summary['remaining']['calories'] ?? 0.0),
            $type,
            $ctx->summary['logged_types'],
            $ctx->regime
        );
    }

    /**
     * Facteur de portion round(budget / kcal, 1) borné [0,5 ; 2,0] — null sous 0,5 (recette écartée).
     */
    public function portionFactor(float $budget, float $kcalPortion): ?float
    {
        if ($kcalPortion <= 0) {
            return null;
        }
        $factor = round($budget / $kcalPortion, 1);
        if ($factor < self::PORTION_MIN) {
            return null;
        }

        return min(self::PORTION_MAX, $factor);
    }

    private function recipeUsesItem(Recipe $recipe, StockItem $item): bool
    {
        $label = $this->stockLabel($item);
        foreach ((array) $recipe->ingredients as $ingredient) {
            if (! is_array($ingredient)) {
                continue;
            }
            $ean = (string) ($ingredient['ean'] ?? '');
            if ($ean !== '' && $item->food_barcode && $ean === (string) $item->food_barcode) {
                return true;
            }
            $name = (string) ($ingredient['name'] ?? '');
            if ($name !== '' && $this->labelsOverlap($label, $name)) {
                return true;
            }
        }

        return false;
    }

    private function labelsOverlap(string $a, string $b): bool
    {
        $fa = DietEvaluator::fold($a);
        $fb = DietEvaluator::fold($b);
        if ($fa === '' || $fb === '' || mb_strlen($fa) < 3 || mb_strlen($fb) < 3) {
            return false;
        }

        return str_contains($fa, $fb) || str_contains($fb, $fa) || $this->rootOf($fa) === $this->rootOf($fb);
    }

    /**
     * Premier mot significatif, sans pluriel (« brocolis frais » → « brocoli »).
     */
    private function rootOf(string $folded): string
    {
        foreach (explode(' ', $folded) as $word) {
            if (mb_strlen($word) >= 4 && ! in_array($word, ['pour', 'avec', 'sans', 'frais', 'fraiche', 'petit', 'petite', 'grand', 'grande'], true)) {
                return rtrim($word, 'sx');
            }
        }

        return rtrim($folded, 'sx');
    }

    private function plannedAt(CoachContext $ctx, WorkoutSession $session): ?Carbon
    {
        $time = trim((string) $session->planned_at);
        if ($time === '' || ! preg_match('/^\d{1,2}:\d{2}/', $time)) {
            return null;
        }

        return Carbon::parse($ctx->date.' '.substr($time, 0, 5), $ctx->timezone);
    }

    private function stockLabel(StockItem $item): string
    {
        $label = trim((string) $item->food_name);
        if ($label === '' && $item->relationLoaded('food') && $item->getRelation('food') instanceof Food) {
            $label = (string) $item->getRelation('food')->name;
        }

        return $label !== '' ? $label : 'Article';
    }

    // ------------------------------------------------------------------------------------
    // Constructeurs de lignes
    // ------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $factors
     * @param  list<array<string, mixed>>  $actions
     * @return array{type: string, title: string, message: string, factors: list<array<string, mixed>>, actions: list<array<string, mixed>>, priority: int, is_estimate: bool}
     */
    private function row(string $type, string $title, string $message, array $factors, array $actions, int $priority, bool $isEstimate): array
    {
        return [
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'message' => $message,
            'factors' => $factors,
            'actions' => $actions,
            'priority' => $priority,
            'is_estimate' => $isEstimate,
        ];
    }

    /** @return array{label: string, value: mixed, unit?: string} */
    private function factor(string $label, mixed $value, ?string $unit = null): array
    {
        $factor = ['label' => $label, 'value' => $value];
        if ($unit !== null) {
            $factor['unit'] = $unit;
        }

        return $factor;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function action(string $kind, array $payload, string $label): array
    {
        return ['kind' => $kind, 'label' => $label] + $payload;
    }

    /** @return array<string, mixed> */
    private function actionAjouterAliment(Food $food, float $grams, string $mealType): array
    {
        return $this->action('ajouter_au_repas', [
            'food_id' => $food->id,
            'quantity' => $grams,
            'unit' => 'g',
            'meal_type' => $mealType,
        ], RecommendationCopy::actionAjouterAliment((string) $food->name, $grams, 'g'));
    }
}
