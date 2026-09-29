<?php

namespace App\Services\Planner;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Magasins\PromotionsActives;
use App\Services\MealBudget;
use App\Support\Clock;
use App\Support\OwnerScope;
use App\Support\StockScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Génération d'une semaine de plans (brief §12) — déterministe.
 *
 * Par créneau (date × type) libre, on cherche d'abord un plat qui n'est pas encore au menu de la
 * semaine : une recette visible (les miennes + publiques) compatible et dans la fourchette
 * calorique, sinon une idée de config/meal_ideas.php. Les recettes passent avant les idées parce
 * qu'elles ont des ingrédients, donc une liste de courses. Quand le vivier est épuisé, on répète
 * plutôt que de laisser le créneau vide (dégradation douce), en reprenant le plat le plus ancien.
 *
 * Le classement garde les priorités qui ont du sens — mes recettes d'abord, puis celles qui
 * consomment un stock qui périme sous 7 jours, puis celles dont un ingrédient est en promotion
 * cette semaine dans le magasin choisi — et y ajoute de quoi éviter la semaine monotone :
 *  - un plat déjà prévu dans la fenêtre générée est écarté tant qu'il reste des candidats ;
 *  - un plat de la même famille que la veille ou le lendemain redescend (MealVariety) ;
 *  - un plat mangé dans le mois écoulé redescend aussi, ce qui écarte deux semaines
 *    d'affilée identiques ;
 *  - à égalité, l'ordre tourne selon le jour et la personne, sans tirage au sort : la génération
 *    reste reproductible à l'identique.
 *
 * La promotion vient délibérément APRÈS le stock qui périme : jeter un aliment déjà payé coûte
 * plus cher que de rater une remise. Et elle n'existe que si la personne a choisi un magasin ;
 * sans magasin préféré, ce critère vaut zéro partout et le classement est exactement celui d'avant.
 *
 * Le paramètre `$choix` de generate() laisse l'IA imposer une recette sur un créneau, mais
 * seulement parmi celles que ces règles retiendraient déjà (candidatesByType), et chaque recette
 * imposée repasse par le filtre régime / allergènes avant d'entrer au plan.
 */
class PlannerGenerator
{
    public const TOLERANCE = 0.30;

    public const EXPIRING_DAYS = 7;

    /** Créneaux qu'on n'a pu remplir qu'en répétant un plat déjà au menu de la semaine. */
    private int $repetitions = 0;

    /**
     * Combien de créneaux de la dernière génération ont dû répéter un plat, faute de mieux.
     * L'appelant s'en sert pour le dire honnêtement plutôt que de laisser croire à un défaut.
     */
    public function repetitionsForcees(): int
    {
        return $this->repetitions;
    }

    /**
     * Ce qu'un régime d'exclusion interdit, dit en allergènes tels que les idées les déclarent.
     * Exact par construction, là où une liste de mots-clés oublie toujours un plat.
     */
    private const REGIME_ALLERGENES = [
        'sans_gluten' => ['gluten'],
        'sans_lactose' => ['lait'],
        'vegan' => ['lait', 'œufs', 'poissons', 'crustacés', 'mollusques'],
    ];

    /**
     * Part du plafond de glucides quotidien qu'un seul repas peut occuper. Au-delà, il ne reste
     * plus de quoi manger le reste de la journée sans dépasser.
     */
    private const PART_GLUCIDES_REPAS = 0.5;

    /**
     * Mémoire de fraîcheur, en jours. Sans elle, deux semaines générées à la suite piochent les
     * mêmes premiers plats du classement et se ressemblent trait pour trait.
     */
    public const FRESHNESS_DAYS = 30;

    /**
     * En deçà, un plat est « de cette semaine-ci ». Une recette mangée il y a moins de sept jours
     * laisse donc passer un plat qu'on n'a encore jamais eu, au lieu de revenir au même jour.
     */
    public const RECENT_DAYS = 7;

    /**
     * Deux plats dont les calories estimées diffèrent de moins de 25 kcal sont traités comme
     * équivalents. Ces valeurs sont des estimations : les départager au kcal près n'a pas de
     * sens, et cela figeait l'ordre — le même plat prenait la tête tous les jours.
     */
    public const BUCKET_KCAL = 25.0;

    public function __construct(
        private readonly MealBudget $budget,
        private readonly PromotionsActives $promotions,
    ) {
    }

    /**
     * @param  list<string>  $mealTypes
     * @param  array<string, int>|null  $choix  « {date}|{type} » → identifiant de recette imposé par l'IA
     * @return int nombre de plans créés
     */
    public function generate(User $user, string $weekStart, array $mealTypes, bool $replace = false, ?array $choix = null): int
    {
        $this->repetitions = 0;

        $start = CarbonImmutable::parse($weekStart);
        $end = $start->addDays(6);
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $start->addDays($i)->toDateString();
        }

        $mealTypes = array_values(array_unique($mealTypes));

        return DB::transaction(function () use ($user, $start, $end, $dates, $mealTypes, $replace, $choix) {
            if ($replace) {
                OwnerScope::apply(MealPlan::query(), $user)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->whereIn('meal_type', $mealTypes)
                    ->where('status', PlanStatus::Prevu->value)
                    ->delete();
            }

            ['context' => $context, 'budgets' => $budgets] = $this->rulesContext($user, $mealTypes);

            $fenetre = [$start->toDateString(), $end->toDateString()];

            // Fenêtre élargie d'un mois de part et d'autre : ce qu'on a mangé avant la
            // semaine compte autant que ce qu'on y met, sinon chaque semaine repart du même plat.
            $existing = OwnerScope::apply(MealPlan::query(), $user)
                ->whereBetween('date', [$start->subDays(self::FRESHNESS_DAYS)->toDateString(), $end->addDays(self::FRESHNESS_DAYS)->toDateString()])
                ->where('status', '!=', PlanStatus::Annule->value)
                ->get(['id', 'date', 'meal_type', 'recipe_id', 'title']);

            $occupied = [];
            $usage = [];    // titre foldé du plat → dates où il est déjà au menu
            $familles = []; // date → familles déjà au menu ce jour-là
            foreach ($existing as $plan) {
                $date = $plan->date->format('Y-m-d');
                if ($date >= $fenetre[0] && $date <= $fenetre[1]) {
                    $occupied[$date.'|'.$plan->meal_type] = true;
                }
                $usage[$this->usageKey((string) $plan->title)][] = $date;
                $this->noteFamille($familles, $date, MealVariety::famille((string) $plan->title));
            }

            $recipes = $this->candidateRecipes($user);
            $expiring = $this->expiringStock($user);

            $created = 0;

            foreach ($dates as $date) {
                foreach ($mealTypes as $type) {
                    if (isset($occupied[$date.'|'.$type])) {
                        continue;
                    }

                    $budget = $budgets[$type] ?? 0.0;

                    $variete = [
                        'date' => $date,
                        'fenetre' => $fenetre,
                        'usage' => $usage,
                        'familles' => $familles,
                        'graine' => $this->varietySeed($date, $user),
                    ];

                    $impose = $choix[$date.'|'.$type] ?? null;

                    $recette = $impose === null
                        ? null
                        : $this->imposedRecipe($recipes, $context, $type, (int) $impose);

                    $idee = null;

                    if ($recette === null) {
                        $recette = $this->pickRecipe($recipes, $context, $type, $budget, $expiring, $user, $variete, true);
                        $idee = $this->pickIdea($context, $type, $budget, $variete, true);

                        // Une recette passe avant une idée : elle a des ingrédients, donc une
                        // liste de courses. Sauf si on l'a mangée dans les sept derniers jours
                        // alors qu'un plat encore jamais servi attend son tour — c'est ce cas-là
                        // qui ramenait le même petit-déjeuner tous les lundis.
                        if ($recette !== null && $idee !== null) {
                            $depuisRecette = $this->daysSinceUsed($usage[$this->usageKey((string) $recette->title)] ?? [], $date);
                            $depuisIdee = $this->daysSinceUsed($usage[$this->usageKey((string) $idee['title'])] ?? [], $date);

                            if ($depuisRecette !== null && $depuisRecette <= self::RECENT_DAYS && $depuisIdee === null) {
                                $recette = null;
                            } else {
                                $idee = null;
                            }
                        }

                        // Plus rien de neuf : on répète le plat le plus ancien plutôt que de
                        // rendre une semaine trouée. On le compte, pour pouvoir le dire : un
                        // régime contraint laisse parfois moins de plats que de créneaux, et
                        // mieux vaut l'annoncer que laisser croire à une panne de variété.
                        if ($recette === null && $idee === null) {
                            $recette = $this->pickRecipe($recipes, $context, $type, $budget, $expiring, $user, $variete, false);
                            if ($recette === null) {
                                $idee = $this->pickIdea($context, $type, $budget, $variete, false);
                            }
                            if ($recette !== null || $idee !== null) {
                                $this->repetitions++;
                            }
                        }
                    }

                    if ($recette === null && $idee === null) {
                        continue;
                    }

                    $titre = (string) ($recette?->title ?? $idee['title']);

                    MealPlan::query()->forceCreate(OwnerScope::ownerAttributes($user) + [
                        'date' => $date,
                        'meal_type' => $type,
                        'recipe_id' => $recette?->id,
                        'food_id' => null,
                        'title' => mb_substr($titre, 0, 255),
                        'servings' => 1,
                        'notes' => null,
                        'status' => PlanStatus::Prevu->value,
                    ]);

                    $usage[$this->usageKey($titre)][] = $date;
                    $this->noteFamille($familles, $date, $recette !== null
                        ? MealVariety::famille($titre, $this->ingredientNames($recette))
                        : MealVariety::famille($titre, $this->ideaIngredients($idee)));

                    $occupied[$date.'|'.$type] = true;
                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * Créneaux (date × type) que cette génération remplirait, dans l'ordre où elle les parcourt.
     *
     * Publié pour que l'IA sache quoi organiser sans refaire ce calcul à sa façon : les deux
     * chemins doivent voir exactement les mêmes créneaux libres, sinon le modèle propose des
     * repas sur des cases déjà prises.
     *
     * @param  list<string>  $mealTypes
     * @return list<array{date: string, meal_type: string}>
     */
    public function freeSlots(User $user, string $weekStart, array $mealTypes, bool $replace = false): array
    {
        $start = CarbonImmutable::parse($weekStart);
        $end = $start->addDays(6);
        $mealTypes = array_values(array_unique($mealTypes));

        $query = OwnerScope::apply(MealPlan::query(), $user)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', PlanStatus::Annule->value);

        // Avec remplacement, les plans « prévu » vont être effacés : leur créneau est donc libre.
        if ($replace) {
            $query->where('status', '!=', PlanStatus::Prevu->value);
        }

        $occupied = [];
        foreach ($query->get(['id', 'date', 'meal_type']) as $plan) {
            /** @var MealPlan $plan */
            $occupied[$plan->date->format('Y-m-d').'|'.$plan->meal_type] = true;
        }

        $slots = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->addDays($i)->toDateString();
            foreach ($mealTypes as $type) {
                if (! isset($occupied[$date.'|'.$type])) {
                    $slots[] = ['date' => $date, 'meal_type' => $type];
                }
            }
        }

        return $slots;
    }

    /**
     * Recettes que ces règles retiendraient pour chaque type de repas, les meilleures d'abord.
     *
     * C'est l'ensemble dans lequel l'IA a le droit de choisir, et rien d'autre : régime,
     * allergènes, type de repas et budget calorique y sont déjà appliqués. Le classement rendu
     * ici ne dépend d'aucun jour : c'est un catalogue, pas une semaine.
     *
     * @param  list<string>  $mealTypes
     * @return array<string, list<Recipe>>
     */
    public function candidatesByType(User $user, array $mealTypes): array
    {
        ['context' => $context, 'budgets' => $budgets] = $this->rulesContext($user, $mealTypes);

        $recipes = $this->candidateRecipes($user);
        $expiring = $this->expiringStock($user);

        $byType = [];
        foreach (array_values(array_unique($mealTypes)) as $type) {
            $byType[$type] = $this->eligible($recipes, $context, $type, $budgets[$type] ?? 0.0, $expiring, $user);
        }

        return $byType;
    }

    // ------------------------------------------------------------------------------------

    /**
     * Profil, contexte de compatibilité et budget calorique par type de repas.
     *
     * @param  list<string>  $mealTypes
     * @return array{context: array{regime: string|null, exclusions: list<string>, is_minor: bool}, budgets: array<string, float>}
     */
    private function rulesContext(User $user, array $mealTypes): array
    {
        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first();

        $budgets = [];
        foreach ($mealTypes as $type) {
            $budgets[$type] = $profile ? (float) $this->budget->forPlanning($user, $type) : 0.0;
        }

        return ['context' => CompatibilityFilter::contextFor($profile), 'budgets' => $budgets];
    }

    /**
     * Recettes visibles, ordre stable (id croissant).
     *
     * @return Collection<int, Recipe>
     */
    private function candidateRecipes(User $user): Collection
    {
        return Recipe::query()
            ->where(function ($q) use ($user) {
                $q->where('is_public', true)->orWhere('created_by_user_id', $user->id);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Articles de stock disponibles périmant sous 7 jours (non périmés) : libellés foldés + EAN.
     *
     * @return array{names: list<string>, eans: list<string>}
     */
    private function expiringStock(User $user): array
    {
        $today = Clock::today($user);
        $limit = CarbonImmutable::parse($today)->addDays(self::EXPIRING_DAYS)->toDateString();

        $items = StockScope::items($user)
            ->with('food:id,name,barcode')
            ->where('quantity', '>', 0)
            ->whereNull('depleted_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today, $limit])
            ->get();

        $names = [];
        $eans = [];
        foreach ($items as $item) {
            /** @var StockItem $item */
            $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;
            foreach ([$item->food_name, $food?->name] as $name) {
                $folded = CompatibilityFilter::fold((string) $name);
                if ($folded !== '') {
                    $names[] = $folded;
                }
            }
            foreach ([$item->food_barcode, $food?->barcode] as $ean) {
                $ean = trim((string) $ean);
                if ($ean !== '') {
                    $eans[] = $ean;
                }
            }
        }

        return ['names' => array_values(array_unique($names)), 'eans' => array_values(array_unique($eans))];
    }

    /**
     * Meilleure recette pour ce créneau, ou null.
     *
     * En mode strict on refuse un plat déjà au menu de la semaine générée ; l'appelant retentera
     * sans cette exigence quand plus rien d'autre n'est disponible.
     *
     * @param  Collection<int, Recipe>  $recipes
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     * @param  array{names: list<string>, eans: list<string>}  $expiring
     * @param  array{date: string, fenetre: array{0: string, 1: string}, usage: array<string, list<string>>, familles: array<string, array<string, true>>, graine: int}  $variete
     */
    private function pickRecipe(Collection $recipes, array $context, string $type, float $budget, array $expiring, User $user, array $variete, bool $strict): ?Recipe
    {
        foreach ($this->eligible($recipes, $context, $type, $budget, $expiring, $user, $variete) as $recipe) {
            $dates = $variete['usage'][$this->usageKey((string) $recipe->title)] ?? [];
            if (! $strict || ! $this->usedInWindow($dates, $variete['fenetre'])) {
                return $recipe;
            }
        }

        return null;
    }

    /**
     * Recettes acceptables pour ce type de repas, les meilleures d'abord.
     *
     * Sans `$variete`, l'ordre ne dépend pas du jour (catalogue pour l'IA). Avec, il tient compte
     * de ce qui est déjà au menu, de la famille des plats voisins et de ce qu'on a mangé
     * récemment — sans jamais passer devant mes recettes ni devant le stock qui périme.
     *
     * @param  Collection<int, Recipe>  $recipes
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     * @param  array{names: list<string>, eans: list<string>}  $expiring
     * @param  array{date: string, fenetre: array{0: string, 1: string}, usage: array<string, list<string>>, familles: array<string, array<string, true>>, graine: int}|null  $variete
     * @return list<Recipe>
     */
    private function eligible(Collection $recipes, array $context, string $type, float $budget, array $expiring, User $user, ?array $variete = null): array
    {
        $scored = [];
        $promotions = $this->promotions->libellesPour($user);

        foreach ($recipes as $recipe) {
            /** @var Recipe $recipe */
            if (! $this->suits($recipe, $context, $type)) {
                continue;
            }

            $calories = (float) $recipe->perServing()['calories'];
            $distance = $budget > 0 ? abs($calories - $budget) : 0.0;
            if ($budget > 0 && $distance > self::TOLERANCE * $budget) {
                continue;
            }

            $ingredients = $this->ingredientNames($recipe);
            $dates = $variete === null
                ? []
                : ($variete['usage'][$this->usageKey((string) $recipe->title)] ?? []);

            $scored[] = [
                'recipe' => $recipe,
                'repete' => $variete !== null && $this->usedInWindow($dates, $variete['fenetre']) ? 1 : 0,
                'mine' => (int) $recipe->created_by_user_id === (int) $user->id ? 1 : 0,
                'expiring' => $this->expiringMatches($recipe, $ingredients, $expiring),
                'promo' => PromotionsActives::compte($ingredients, $promotions),
                'famille' => $variete === null
                    ? 0
                    : $this->famillePenalty($variete, MealVariety::famille((string) $recipe->title, $ingredients)),
                'fraicheur' => $variete === null ? 0 : $this->freshness($dates, $variete['date']),
                'ecart' => $this->bucket($distance),
            ];
        }

        usort($scored, function (array $a, array $b) {
            return [$a['repete'], $b['mine'], $b['expiring'], $b['promo'], $a['famille'], $a['fraicheur'], $a['ecart'], $a['recipe']->id]
                <=> [$b['repete'], $a['mine'], $a['expiring'], $a['promo'], $b['famille'], $b['fraicheur'], $b['ecart'], $b['recipe']->id];
        });

        $scored = $this->rotate($scored, ['repete', 'mine', 'expiring', 'promo', 'famille', 'fraicheur', 'ecart'], $variete['graine'] ?? 0);

        return array_map(fn (array $entry) => $entry['recipe'], $scored);
    }

    /**
     * Recette imposée par l'IA : elle n'entre au plan qu'après re-vérification du type de repas,
     * du régime et des allergènes. Une consigne dans le prompt ne suffit pas à garantir cela, et
     * l'allergène est une contrainte de sécurité : le serveur reste seul juge.
     *
     * @param  Collection<int, Recipe>  $recipes
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     */
    private function imposedRecipe(Collection $recipes, array $context, string $type, int $recipeId): ?Recipe
    {
        $recipe = $recipes->first(fn (Recipe $candidate) => (int) $candidate->id === $recipeId);

        return $recipe !== null && $this->suits($recipe, $context, $type) ? $recipe : null;
    }

    /**
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     */
    private function suits(Recipe $recipe, array $context, string $type): bool
    {
        $mealTypes = is_array($recipe->meal_types) ? $recipe->meal_types : [];

        if ($mealTypes !== [] && ! in_array($type, $mealTypes, true)) {
            return false;
        }

        return CompatibilityFilter::isCompatible(
            $context,
            (string) $recipe->title,
            is_array($recipe->tags) ? $recipe->tags : [],
            $this->ingredientNames($recipe),
        );
    }

    /**
     * Meilleure idée de repas pour ce créneau (repli « titre seul »), ou null.
     *
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     * @param  array{date: string, fenetre: array{0: string, 1: string}, usage: array<string, list<string>>, familles: array<string, array<string, true>>, graine: int}  $variete
     * @return array<string, mixed>|null
     */
    private function pickIdea(array $context, string $type, float $budget, array $variete, bool $strict): ?array
    {
        $eligible = [];

        foreach (PlanCalories::ideas() as $index => $idea) {
            $mealTypes = is_array($idea['meal_types'] ?? null) ? $idea['meal_types'] : [];
            if ($mealTypes !== [] && ! in_array($type, $mealTypes, true)) {
                continue;
            }

            $title = (string) ($idea['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $ingredients = $this->ideaIngredients($idea);

            // Les ingrédients et allergènes annoncés entrent dans le filtre : un allergène du
            // profil doit être reconnu même quand le titre du plat ne le nomme pas.
            if (! CompatibilityFilter::isCompatible($context, $title, is_array($idea['tags'] ?? null) ? $idea['tags'] : [], $ingredients)) {
                continue;
            }

            // Le régime se décide sur ce que l'idée DÉCLARE, pas sur ce que son titre laisse
            // deviner. Une recherche de mots-clés ratait douze plats sur vingt-huit pour un
            // profil sans gluten : « porridge », « wrap » ou « couscous » ne contiennent pas le
            // mot, et annonçaient pourtant l'allergène noir sur blanc dans leur fiche.
            if (! $this->idealSuitsRegime($idea, $context)) {
                continue;
            }

            $dates = $variete['usage'][$this->usageKey($title)] ?? [];
            $repete = $this->usedInWindow($dates, $variete['fenetre']);
            if ($strict && $repete) {
                continue;
            }

            $calories = (float) ($idea['calories'] ?? 0);
            $distance = $budget > 0 ? abs($calories - $budget) : 0.0;

            $eligible[] = [
                'idea' => $idea,
                'repete' => $repete ? 1 : 0,
                'within' => ($budget <= 0 || $distance <= self::TOLERANCE * $budget) ? 1 : 0,
                'famille' => $this->famillePenalty($variete, MealVariety::famille($title, $ingredients)),
                'fraicheur' => $this->freshness($dates, $variete['date']),
                'ecart' => $this->bucket($distance),
                'index' => (int) $index,
            ];
        }

        if ($eligible === []) {
            return null;
        }

        usort($eligible, function (array $a, array $b) {
            return [$a['repete'], $b['within'], $a['famille'], $a['fraicheur'], $a['ecart'], $a['index']]
                <=> [$b['repete'], $a['within'], $b['famille'], $b['fraicheur'], $b['ecart'], $b['index']];
        });

        $eligible = $this->rotate($eligible, ['repete', 'within', 'famille', 'fraicheur', 'ecart'], $variete['graine']);

        return $eligible[0]['idea'];
    }

    /**
     * @return list<string>
     */
    private function ingredientNames(Recipe $recipe): array
    {
        $names = [];
        foreach (is_array($recipe->ingredients) ? $recipe->ingredients : [] as $ingredient) {
            $name = trim((string) (is_array($ingredient) ? ($ingredient['name'] ?? '') : $ingredient));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Ingrédients principaux et allergènes usuels d'une idée de repas.
     *
     * @param  array<string, mixed>  $idea
     * @return list<string>
     */
    /**
     * Un régime d'exclusion interdit un allergène, un régime pauvre en glucides interdit un
     * chiffre. Les deux sont écrits dans la fiche de l'idée : on les lit au lieu de les deviner.
     *
     * @param  array<string, mixed>  $idea
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     */
    private function idealSuitsRegime(array $idea, array $context): bool
    {
        $regime = $context['regime'] ?? null;

        if ($regime === null) {
            return true;
        }

        $declares = array_map(
            fn ($a) => CompatibilityFilter::fold((string) $a),
            is_array($idea['allergenes'] ?? null) ? $idea['allergenes'] : [],
        );

        foreach (self::REGIME_ALLERGENES[$regime] ?? [] as $interdit) {
            if (in_array(CompatibilityFilter::fold($interdit), $declares, true)) {
                return false;
            }
        }

        // Un seul plat à 70 g de glucides suffit à crever une journée cétogène plafonnée à 30 g :
        // la part du repas est une borne trop lâche, on refuse dès que l'idée dépasse le plafond
        // du jour à elle seule.
        $plafondJour = config('diets.'.$regime.'.regles.glucides_max_g');

        if ($plafondJour !== null && (float) ($idea['carbs'] ?? 0) > (float) $plafondJour * self::PART_GLUCIDES_REPAS) {
            return false;
        }

        return true;
    }

    private function ideaIngredients(array $idea): array
    {
        $names = [];
        foreach (['ingredients', 'allergenes'] as $key) {
            foreach (is_array($idea[$key] ?? null) ? $idea[$key] : [] as $entry) {
                $entry = trim((string) $entry);
                if ($entry !== '') {
                    $names[] = $entry;
                }
            }
        }

        return $names;
    }

    /**
     * Nombre d'ingrédients de la recette correspondant à un article de stock qui périme bientôt.
     *
     * @param  list<string>  $ingredientNames
     * @param  array{names: list<string>, eans: list<string>}  $expiring
     */
    private function expiringMatches(Recipe $recipe, array $ingredientNames, array $expiring): int
    {
        if ($expiring['names'] === [] && $expiring['eans'] === []) {
            return 0;
        }

        $matches = 0;

        foreach (is_array($recipe->ingredients) ? $recipe->ingredients : [] as $ingredient) {
            $ean = trim((string) (is_array($ingredient) ? ($ingredient['ean'] ?? '') : ''));
            if ($ean !== '' && in_array($ean, $expiring['eans'], true)) {
                $matches++;

                continue;
            }

            $name = CompatibilityFilter::fold((string) (is_array($ingredient) ? ($ingredient['name'] ?? '') : $ingredient));
            if ($name === '') {
                continue;
            }

            foreach ($expiring['names'] as $stockName) {
                if (str_contains($stockName, $name) || str_contains($name, $stockName)) {
                    $matches++;

                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Ce plat est-il déjà au menu de la semaine générée ?
     *
     * @param  list<string>  $dates
     * @param  array{0: string, 1: string}  $fenetre
     */
    private function usedInWindow(array $dates, array $fenetre): bool
    {
        foreach ($dates as $used) {
            if ($used >= $fenetre[0] && $used <= $fenetre[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jours écoulés depuis la fois la plus proche où ce plat était au menu, ou null s'il ne l'a
     * jamais été dans la fenêtre consultée.
     *
     * @param  list<string>  $dates
     */
    private function daysSinceUsed(array $dates, string $date): ?int
    {
        $cible = CarbonImmutable::parse($date);
        $ecartMin = null;

        foreach ($dates as $used) {
            $ecart = (int) abs($cible->diffInDays(CarbonImmutable::parse($used), false));
            $ecartMin = $ecartMin === null ? $ecart : min($ecartMin, $ecart);
        }

        return $ecartMin;
    }

    /**
     * Pénalité d'autant plus forte que le plat a été mangé récemment (0 s'il ne l'a jamais été).
     *
     * @param  list<string>  $dates
     */
    private function freshness(array $dates, string $date): int
    {
        $ecart = $this->daysSinceUsed($dates, $date);

        return $ecart === null ? 0 : max(0, self::FRESHNESS_DAYS - $ecart);
    }

    /**
     * Pénalité de ressemblance : la même famille le même jour pèse plus que la veille ou le
     * lendemain, mais aucune des deux n'interdit le plat — elle le fait seulement reculer.
     *
     * @param  array<string, mixed>  $variete
     */
    private function famillePenalty(array $variete, ?string $famille): int
    {
        if ($famille === null) {
            return 0;
        }

        $jour = CarbonImmutable::parse($variete['date']);
        $penalite = isset($variete['familles'][$jour->toDateString()][$famille]) ? 2 : 0;

        foreach ([-1, 1] as $decalage) {
            if (isset($variete['familles'][$jour->addDays($decalage)->toDateString()][$famille])) {
                $penalite++;
            }
        }

        return $penalite;
    }

    /**
     * Écart au budget, par paliers : voir BUCKET_KCAL.
     */
    private function bucket(float $distance): int
    {
        return (int) floor($distance / self::BUCKET_KCAL);
    }

    /**
     * Fait tourner l'ordre à l'intérieur de chaque groupe de candidats que le classement laisse
     * à égalité. Sans cela, le même plat prend la tête tous les jours ; avec un tirage au sort,
     * la génération ne serait plus reproductible.
     *
     * @param  list<array<string, mixed>>  $scored
     * @param  list<string>  $cles
     * @return list<array<string, mixed>>
     */
    private function rotate(array $scored, array $cles, int $graine): array
    {
        if ($graine <= 0 || count($scored) < 2) {
            return $scored;
        }

        $sortie = [];
        $groupe = [];
        $precedente = null;

        foreach ($scored as $entree) {
            $signature = implode('|', array_map(fn (string $cle) => (string) $entree[$cle], $cles));
            if ($precedente !== null && $signature !== $precedente) {
                $sortie = array_merge($sortie, $this->rotated($groupe, $graine));
                $groupe = [];
            }
            $precedente = $signature;
            $groupe[] = $entree;
        }

        return array_merge($sortie, $this->rotated($groupe, $graine));
    }

    /**
     * @param  list<array<string, mixed>>  $groupe
     * @return list<array<string, mixed>>
     */
    private function rotated(array $groupe, int $graine): array
    {
        $taille = count($groupe);
        if ($taille < 2) {
            return $groupe;
        }

        $decalage = $graine % $taille;

        return array_merge(array_slice($groupe, $decalage), array_slice($groupe, 0, $decalage));
    }

    /**
     * Décalage de rotation du jour : il ne dépend que de la date et de la personne, donc deux
     * générations de la même semaine par la même personne donnent exactement le même menu.
     */
    private function varietySeed(string $date, User $user): int
    {
        return (int) floor(CarbonImmutable::parse($date)->getTimestamp() / 86400) + (int) $user->id;
    }

    /**
     * @param  array<string, array<string, true>>  $familles
     */
    private function noteFamille(array &$familles, string $date, ?string $famille): void
    {
        if ($famille !== null) {
            $familles[$date][$famille] = true;
        }
    }

    /**
     * Ce qui compte pour « déjà mangé », c'est le plat, pas sa provenance : une recette et une
     * idée de repas qui portent le même titre sont le même dîner, et la semaine ne doit pas les
     * servir toutes les deux.
     */
    private function usageKey(string $title): string
    {
        return 't:'.CompatibilityFilter::fold($title);
    }
}
