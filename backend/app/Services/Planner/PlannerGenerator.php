<?php

namespace App\Services\Planner;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Models\User;
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
 * Par créneau (date × type) libre :
 *  1. recettes visibles (les miennes + publiques) compatibles (CompatibilityFilter),
 *     dont le type de repas convient, dont per_serving.calories est à ±30 % du budget
 *     MealBudget::forPlanning(type), non planifiées à moins de 3 jours ;
 *     tri : mes recettes d'abord, puis celles qui utilisent un article de stock
 *     périmant sous 7 jours (non périmé), puis proximité calorique, puis id ;
 *  2. repli : idée de config/meal_ideas.php (plan « titre seul »), même règles.
 *
 * Le paramètre `$choix` de generate() laisse l'IA imposer une recette sur un créneau, mais
 * seulement parmi celles que ces règles retiendraient déjà (candidatesByType), et chaque recette
 * imposée repasse par le filtre régime / allergènes avant d'entrer au plan.
 */
class PlannerGenerator
{
    public const TOLERANCE = 0.30;

    public const NO_REPEAT_DAYS = 3;

    public const EXPIRING_DAYS = 7;

    public function __construct(private readonly MealBudget $budget)
    {
    }

    /**
     * @param  list<string>  $mealTypes
     * @param  array<string, int>|null  $choix  « {date}|{type} » → identifiant de recette imposé par l'IA
     * @return int nombre de plans créés
     */
    public function generate(User $user, string $weekStart, array $mealTypes, bool $replace = false, ?array $choix = null): int
    {
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

            // Plans existants (fenêtre élargie de 2 jours pour la règle « pas de répétition à 3 jours »).
            $existing = OwnerScope::apply(MealPlan::query(), $user)
                ->whereBetween('date', [$start->subDays(self::NO_REPEAT_DAYS - 1)->toDateString(), $end->addDays(self::NO_REPEAT_DAYS - 1)->toDateString()])
                ->where('status', '!=', PlanStatus::Annule->value)
                ->get(['id', 'date', 'meal_type', 'recipe_id', 'title']);

            $occupied = [];
            $usage = []; // clé « r:{id} » ou « t:{titre foldé} » → liste de dates
            foreach ($existing as $plan) {
                $date = $plan->date->format('Y-m-d');
                if ($date >= $start->toDateString() && $date <= $end->toDateString()) {
                    $occupied[$date.'|'.$plan->meal_type] = true;
                }
                $usage[$this->usageKey($plan->recipe_id, (string) $plan->title)][] = $date;
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

                    $impose = $choix[$date.'|'.$type] ?? null;

                    $recipe = $impose === null
                        ? null
                        : $this->imposedRecipe($recipes, $context, $type, (int) $impose);

                    $recipe ??= $this->pickRecipe($recipes, $context, $type, $budget, $date, $usage, $expiring, $user);
                    if ($recipe !== null) {
                        MealPlan::query()->forceCreate(OwnerScope::ownerAttributes($user) + [
                            'date' => $date,
                            'meal_type' => $type,
                            'recipe_id' => $recipe->id,
                            'food_id' => null,
                            'title' => mb_substr((string) $recipe->title, 0, 255),
                            'servings' => 1,
                            'notes' => null,
                            'status' => PlanStatus::Prevu->value,
                        ]);
                        $usage[$this->usageKey($recipe->id, (string) $recipe->title)][] = $date;
                        $occupied[$date.'|'.$type] = true;
                        $created++;

                        continue;
                    }

                    $idea = $this->pickIdea($context, $type, $budget, $date, $usage);
                    if ($idea !== null) {
                        MealPlan::query()->forceCreate(OwnerScope::ownerAttributes($user) + [
                            'date' => $date,
                            'meal_type' => $type,
                            'recipe_id' => null,
                            'food_id' => null,
                            'title' => mb_substr((string) $idea['title'], 0, 255),
                            'servings' => 1,
                            'notes' => null,
                            'status' => PlanStatus::Prevu->value,
                        ]);
                        $usage[$this->usageKey(null, (string) $idea['title'])][] = $date;
                        $occupied[$date.'|'.$type] = true;
                        $created++;
                    }
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
     * allergènes, type de repas et budget calorique y sont déjà appliqués.
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
     * @param  Collection<int, Recipe>  $recipes
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     * @param  array<string, list<string>>  $usage
     * @param  array{names: list<string>, eans: list<string>}  $expiring
     */
    private function pickRecipe(Collection $recipes, array $context, string $type, float $budget, string $date, array $usage, array $expiring, User $user): ?Recipe
    {
        foreach ($this->eligible($recipes, $context, $type, $budget, $expiring, $user) as $recipe) {
            if (! $this->recentlyUsed($usage[$this->usageKey($recipe->id, (string) $recipe->title)] ?? [], $date)) {
                return $recipe;
            }
        }

        return null;
    }

    /**
     * Recettes acceptables pour ce type de repas, les meilleures d'abord.
     *
     * L'ordre ne dépend pas de la date : mes recettes, puis celles qui consomment un stock qui
     * périme, puis la proximité calorique, puis l'identifiant. La règle « pas deux fois en trois
     * jours », elle, dépend de la date : pickRecipe l'applique sur cette liste.
     *
     * @param  Collection<int, Recipe>  $recipes
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     * @param  array{names: list<string>, eans: list<string>}  $expiring
     * @return list<Recipe>
     */
    private function eligible(Collection $recipes, array $context, string $type, float $budget, array $expiring, User $user): array
    {
        $scored = [];

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

            $scored[] = [
                'recipe' => $recipe,
                'mine' => (int) $recipe->created_by_user_id === (int) $user->id ? 1 : 0,
                'expiring' => $this->expiringMatches($recipe, $this->ingredientNames($recipe), $expiring),
                'distance' => $distance,
            ];
        }

        usort($scored, function (array $a, array $b) {
            return [$b['mine'], $b['expiring'], $a['distance'], $a['recipe']->id]
                <=> [$a['mine'], $a['expiring'], $b['distance'], $b['recipe']->id];
        });

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
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     * @param  array<string, list<string>>  $usage
     * @return array<string, mixed>|null
     */
    private function pickIdea(array $context, string $type, float $budget, string $date, array $usage): ?array
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

            if (! CompatibilityFilter::isCompatible($context, $title, is_array($idea['tags'] ?? null) ? $idea['tags'] : [])) {
                continue;
            }

            if ($this->recentlyUsed($usage[$this->usageKey(null, $title)] ?? [], $date)) {
                continue;
            }

            $calories = (float) ($idea['calories'] ?? 0);
            $distance = $budget > 0 ? abs($calories - $budget) : 0.0;

            $eligible[] = [
                'idea' => $idea,
                'within' => ($budget <= 0 || $distance <= self::TOLERANCE * $budget) ? 1 : 0,
                'distance' => $distance,
                'index' => (int) $index,
            ];
        }

        if ($eligible === []) {
            return null;
        }

        usort($eligible, function (array $a, array $b) {
            return [$b['within'], $a['distance'], $a['index']] <=> [$a['within'], $b['distance'], $b['index']];
        });

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
     * Vrai si une date d'utilisation est à moins de NO_REPEAT_DAYS jours de $date.
     *
     * @param  list<string>  $dates
     */
    private function recentlyUsed(array $dates, string $date): bool
    {
        $target = CarbonImmutable::parse($date);

        foreach ($dates as $used) {
            if (abs($target->diffInDays(CarbonImmutable::parse($used), false)) < self::NO_REPEAT_DAYS) {
                return true;
            }
        }

        return false;
    }

    private function usageKey(?int $recipeId, string $title): string
    {
        return $recipeId ? 'r:'.$recipeId : 't:'.CompatibilityFilter::fold($title);
    }
}
