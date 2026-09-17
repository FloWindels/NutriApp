<?php

namespace App\Services\Planner;

use App\Enums\PlanStatus;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MealCalculator;
use App\Services\MealService;
use App\Services\StockService;
use App\Support\Clock;
use App\Support\StockScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Réaliser » un plan (brief §12, POST /planner/{id}/log) : crée le repas et ses éléments via
 * MealService, marque le plan `realise` avec `meal_id`, décrémente le stock si demandé.
 *
 *  - recette     → élément recette, quantité = portions (unité portion) ;
 *  - aliment     → élément aliment, quantité = portions (unité portion, « 1 portion » par défaut) ;
 *  - titre seul  → élément personnalisé : calories de config/meal_ideas.php si le titre
 *                  correspond, sinon 0 kcal ; toujours `is_estimate = true`.
 */
class PlanLogger
{
    public const MSG_DEJA_REALISE = 'Ce repas est déjà enregistré.';

    public function __construct(
        private readonly MealService $meals,
        private readonly MealCalculator $calculator,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @return array{plan: MealPlan, meal: Meal, items: list<MealItem>, stock_decrements: list<array<string, mixed>>}
     */
    public function log(User $user, MealPlan $plan, bool $decrementStock = false): array
    {
        if ($plan->status === PlanStatus::Realise->value) {
            throw ValidationException::withMessages(['status' => [self::MSG_DEJA_REALISE]]);
        }

        return DB::transaction(function () use ($user, $plan, $decrementStock) {
            $date = $plan->date->format('Y-m-d');
            $servings = (float) $plan->servings > 0 ? (float) $plan->servings : 1.0;

            $meal = $this->meals->findOrCreate($user, $date, (string) $plan->meal_type);
            $meal->setRelation('user', $user);

            $decrements = [];
            $titleOnly = false;

            $recipe = $plan->relationLoaded('recipe') ? $plan->getRelation('recipe') : null;
            $food = $plan->relationLoaded('food') ? $plan->getRelation('food') : null;

            if ($plan->recipe_id && $recipe !== null) {
                $input = ['recipe_id' => $plan->recipe_id, 'quantity' => $servings, 'unit' => 'portion'];

                if ($decrementStock) {
                    $factor = (float) $recipe->servings > 0 ? $servings / (float) $recipe->servings : 1.0;
                    $decrements = $this->decrementIngredients($user, is_array($recipe->ingredients) ? $recipe->ingredients : [], $factor);
                }
            } elseif ($plan->food_id && $food !== null) {
                $input = ['food_id' => $plan->food_id, 'quantity' => $servings, 'unit' => 'portion'];

                if ($decrementStock) {
                    $stockItem = $this->availableItems($user)
                        ->where('food_id', $plan->food_id)
                        ->orderBy('expires_at')
                        ->orderBy('id')
                        ->first();

                    if ($stockItem !== null) {
                        $input['stock_item_id'] = $stockItem->id;
                        $input['decrement_stock'] = true;
                    }
                }
            } else {
                $titleOnly = true;
                $idea = PlanCalories::ideaForTitle((string) $plan->title);

                $input = [
                    'custom' => [
                        'label' => (string) $plan->title,
                        'per_100g' => false,
                        'calories' => $idea ? round((float) $idea['calories'] * $servings, 1) : 0,
                        'proteins' => $idea ? round((float) ($idea['proteins'] ?? 0) * $servings, 1) : 0,
                        'carbs' => $idea ? round((float) ($idea['carbs'] ?? 0) * $servings, 1) : 0,
                        'fat' => $idea ? round((float) ($idea['fat'] ?? 0) * $servings, 1) : 0,
                    ],
                    'quantity' => $servings,
                    'unit' => 'portion',
                ];
            }

            [$created, $mealDecrements] = $this->meals->addItems($meal, [$input], false);
            $decrements = array_merge($decrements, $mealDecrements);

            if ($titleOnly) {
                foreach ($created as $item) {
                    $item->forceFill(['is_estimate' => true])->save();
                }
            }

            $plan->forceFill([
                'status' => PlanStatus::Realise->value,
                'meal_id' => $meal->id,
            ])->save();

            return [
                'plan' => $plan,
                'meal' => $meal,
                'items' => array_values($created),
                'stock_decrements' => array_values($decrements),
            ];
        });
    }

    /**
     * Payload « journée » (même forme que GET /meals data) : résumé + repas de la date.
     *
     * @return array<string, mixed>
     */
    public function dayPayload(User $user, string $date): array
    {
        $summary = $this->calculator->daySummary($user, $date);

        $order = array_flip(array_keys(MealCalculator::DEFAULT_HOURS));

        $meals = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->with(['items.food:id,barcode,brand,image_url'])
            ->get()
            ->sortBy(fn (Meal $meal) => ($order[$meal->type->value] ?? 9).'-'.$meal->id)
            ->values();

        $resource = 'App\\Http\\Resources\\MealResource';
        $mealsPayload = class_exists($resource)
            ? $resource::collection($meals)->resolve()
            : $meals->map(fn (Meal $meal) => $this->mealFallback($meal))->all();

        return [
            'date' => $summary['date'],
            'meals' => $mealsPayload,
            'totals' => $summary['totals'],
            'targets' => $summary['targets'],
            'remaining' => $summary['remaining'],
            'sport' => $summary['sport'],
            'next_meal_type' => $summary['next_meal_type'],
            'plancher_kcal' => $summary['plancher_kcal'],
        ];
    }

    // ------------------------------------------------------------------------------------

    /**
     * Décrémente le stock pour chaque ingrédient trouvé (EAN puis nom), quantité × facteur.
     *
     * @param  list<array<string, mixed>>  $ingredients
     * @return list<array<string, mixed>>
     */
    private function decrementIngredients(User $user, array $ingredients, float $factor): array
    {
        $available = $this->availableItems($user)->with('food:id,name,barcode')->orderBy('expires_at')->orderBy('id')->get();
        if ($available->isEmpty()) {
            return [];
        }

        $decrements = [];
        $used = [];

        foreach ($ingredients as $ingredient) {
            if (! is_array($ingredient)) {
                continue;
            }

            $amount = $ingredient['amount'] ?? null;
            if ($amount === null || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $item = $this->matchStockItem($available, (string) ($ingredient['ean'] ?? ''), (string) ($ingredient['name'] ?? ''), $used);
            if ($item === null) {
                continue;
            }

            $used[] = $item->id;
            $unit = trim((string) ($ingredient['unit'] ?? '')) ?: (string) $item->unit;
            $decrements[] = $this->stock->decrement($item, (float) $amount * $factor, $unit);
        }

        return $decrements;
    }

    /**
     * @param  Collection<int, StockItem>  $items
     * @param  list<int>  $used
     */
    private function matchStockItem(Collection $items, string $ean, string $name, array $used): ?StockItem
    {
        $ean = trim($ean);
        $folded = CompatibilityFilter::fold($name);

        if ($ean !== '') {
            foreach ($items as $item) {
                if (in_array($item->id, $used, true)) {
                    continue;
                }
                $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;
                if ((string) $item->food_barcode === $ean || (string) ($food?->barcode ?? '') === $ean) {
                    return $item;
                }
            }
        }

        if ($folded === '') {
            return null;
        }

        foreach ($items as $item) {
            if (in_array($item->id, $used, true)) {
                continue;
            }
            $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;
            foreach ([$item->food_name, $food?->name] as $candidate) {
                $candidate = CompatibilityFilter::fold((string) $candidate);
                if ($candidate !== '' && (str_contains($candidate, $folded) || str_contains($folded, $candidate))) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Articles de stock disponibles (quantité > 0, non épuisés, non périmés) dans la portée.
     */
    private function availableItems(User $user)
    {
        $today = Clock::today($user);

        return StockScope::items($user)
            ->where('quantity', '>', 0)
            ->whereNull('depleted_at')
            ->where(function ($q) use ($today) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $today);
            });
    }

    /**
     * Forme minimale d'un repas quand MealResource (module M4) n'est pas encore disponible.
     *
     * @return array<string, mixed>
     */
    private function mealFallback(Meal $meal): array
    {
        $items = $meal->getRelation('items');

        return [
            'id' => $meal->id,
            'date' => $meal->date?->format('Y-m-d'),
            'type' => $meal->type->value,
            'name' => $meal->name,
            'consumed_at' => $meal->consumed_at?->toISOString(),
            'notes' => $meal->notes,
            'items' => $items->map(function (MealItem $item) {
                $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;

                return [
                    'id' => $item->id,
                    'meal_id' => $item->meal_id,
                    'source_type' => $item->source_type,
                    'food_id' => $item->food_id,
                    'recipe_id' => $item->recipe_id,
                    'stock_item_id' => $item->stock_item_id,
                    'label' => $item->label,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'grams_equivalent' => $item->grams_equivalent,
                    'calories' => $item->calories,
                    'proteins' => $item->proteins,
                    'carbs' => $item->carbs,
                    'fat' => $item->fat,
                    'fiber' => $item->fiber,
                    'sugar' => $item->sugar,
                    'salt' => $item->salt,
                    'is_estimate' => $item->is_estimate,
                    'food' => $food ? [
                        'id' => $food->id,
                        'barcode' => $food->barcode,
                        'brand' => $food->brand,
                        'image_url' => $food->image_url,
                    ] : null,
                    'created_at' => $item->created_at?->toISOString(),
                ];
            })->values()->all(),
            'totals' => $this->calculator->totalsForItems($items),
        ];
    }
}
