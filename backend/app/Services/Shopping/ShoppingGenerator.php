<?php

namespace App\Services\Shopping;

use App\Enums\PlanStatus;
use App\Enums\ShoppingSource;
use App\Models\Food;
use App\Models\MealPlan;
use App\Models\ShoppingItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Planner\CompatibilityFilter;
use App\Support\Clock;
use App\Support\OwnerScope;
use App\Support\StockScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Génération de la liste de courses (brief §11, POST /shopping-list/generate) :
 *  1. ingrédients des recettes planifiées (statut prévu) de la semaine absents du stock
 *     disponible — comparaison par EAN puis par nom (LOWER LIKE) ; un article épuisé ou
 *     périmé n'est pas « disponible » ;
 *  2. articles de stock bas ou épuisés ;
 *  dédoublonnage par LOWER(label) contre les articles non cochés de la portée.
 */
class ShoppingGenerator
{
    /**
     * @return int nombre d'articles ajoutés
     */
    public function generate(User $user, string $weekStart): int
    {
        $start = CarbonImmutable::parse($weekStart)->toDateString();
        $end = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();
        $today = Clock::today($user);

        return DB::transaction(function () use ($user, $start, $end, $today) {
            $plans = OwnerScope::apply(MealPlan::query(), $user)
                ->whereBetween('date', [$start, $end])
                ->where('status', PlanStatus::Prevu->value)
                ->whereNotNull('recipe_id')
                ->with('recipe:id,title,servings,ingredients')
                ->orderBy('date')
                ->orderBy('id')
                ->get();

            $available = StockScope::items($user)
                ->with('food:id,name,barcode')
                ->where('quantity', '>', 0)
                ->whereNull('depleted_at')
                ->where(function ($q) use ($today) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', $today);
                })
                ->get();

            $low = StockScope::items($user)
                ->with('food:id,name,barcode')
                ->where(function ($q) {
                    $q->where('quantity', '<=', 0)
                        ->orWhereNotNull('depleted_at')
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('min_quantity')->whereColumn('quantity', '<=', 'min_quantity');
                        });
                })
                ->orderBy('id')
                ->get();

            $existing = OwnerScope::apply(ShoppingItem::query(), $user)
                ->where('checked', false)
                ->pluck('label')
                ->map(fn ($label) => mb_strtolower(trim((string) $label)))
                ->flip()
                ->all();

            $owner = OwnerScope::ownerAttributes($user);
            $added = 0;

            // 1. Ingrédients manquants des recettes planifiées.
            foreach ($this->neededIngredients($plans) as $needed) {
                if ($this->inStock($available, $needed['ean'], $needed['name'])) {
                    continue;
                }

                $key = mb_strtolower($needed['name']);
                if (isset($existing[$key])) {
                    continue;
                }

                ShoppingItem::query()->forceCreate($owner + [
                    'food_id' => $needed['food_id'],
                    'label' => mb_substr($needed['name'], 0, 255),
                    'quantity' => $needed['amount'],
                    'unit' => $needed['unit'],
                    'checked' => false,
                    'source' => ShoppingSource::Planificateur->value,
                ]);
                $existing[$key] = true;
                $added++;
            }

            // 2. Stock bas ou épuisé.
            foreach ($low as $item) {
                /** @var StockItem $item */
                $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;
                $label = trim((string) ($item->food_name ?: ($food?->name ?? '')));
                if ($label === '') {
                    continue;
                }

                $key = mb_strtolower($label);
                if (isset($existing[$key])) {
                    continue;
                }

                ShoppingItem::query()->forceCreate($owner + [
                    'food_id' => $item->food_id,
                    'label' => mb_substr($label, 0, 255),
                    'quantity' => null,
                    'unit' => $item->unit ? mb_substr((string) $item->unit, 0, 16) : null,
                    'checked' => false,
                    'source' => ShoppingSource::AutoStock->value,
                ]);
                $existing[$key] = true;
                $added++;
            }

            return $added;
        });
    }

    // ------------------------------------------------------------------------------------

    /**
     * Agrège les ingrédients des plans (quantité × portions planifiées / portions de la recette),
     * par EAN sinon par nom foldé. Les quantités ne s'additionnent que si l'unité est identique.
     *
     * @param  Collection<int, MealPlan>  $plans
     * @return list<array{name: string, ean: string|null, amount: float|null, unit: string|null, food_id: int|null}>
     */
    private function neededIngredients(Collection $plans): array
    {
        $needed = [];

        foreach ($plans as $plan) {
            /** @var MealPlan $plan */
            $recipe = $plan->relationLoaded('recipe') ? $plan->getRelation('recipe') : null;
            if ($recipe === null) {
                continue;
            }

            $recipeServings = (float) $recipe->servings > 0 ? (float) $recipe->servings : 1.0;
            $factor = ((float) $plan->servings > 0 ? (float) $plan->servings : 1.0) / $recipeServings;

            foreach (is_array($recipe->ingredients) ? $recipe->ingredients : [] as $ingredient) {
                if (! is_array($ingredient)) {
                    continue;
                }

                $name = trim((string) ($ingredient['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $ean = trim((string) ($ingredient['ean'] ?? ''));
                $ean = $ean !== '' ? $ean : null;
                $unit = trim((string) ($ingredient['unit'] ?? ''));
                $unit = $unit !== '' ? mb_substr($unit, 0, 16) : null;
                $amountRaw = $ingredient['amount'] ?? null;
                $amount = ($amountRaw !== null && is_numeric($amountRaw) && (float) $amountRaw > 0)
                    ? round((float) $amountRaw * $factor, 2)
                    : null;

                $key = $ean !== null ? 'e:'.$ean : 'n:'.CompatibilityFilter::fold($name);

                if (! isset($needed[$key])) {
                    $needed[$key] = ['name' => $name, 'ean' => $ean, 'amount' => $amount, 'unit' => $unit, 'food_id' => null];

                    continue;
                }

                if ($amount !== null && $needed[$key]['amount'] !== null && $needed[$key]['unit'] === $unit) {
                    $needed[$key]['amount'] = round($needed[$key]['amount'] + $amount, 2);
                }
            }
        }

        $eans = array_values(array_filter(array_map(fn ($n) => $n['ean'], $needed)));
        if ($eans !== []) {
            $foods = Food::query()->whereIn('barcode', $eans)->pluck('id', 'barcode');
            foreach ($needed as &$row) {
                if ($row['ean'] !== null && $foods->has($row['ean'])) {
                    $row['food_id'] = (int) $foods->get($row['ean']);
                }
            }
            unset($row);
        }

        return array_values($needed);
    }

    /**
     * L'ingrédient est-il disponible en stock ? EAN d'abord, puis nom (contenance, insensible
     * à la casse et aux accents, dans les deux sens).
     *
     * @param  Collection<int, StockItem>  $available
     */
    private function inStock(Collection $available, ?string $ean, string $name): bool
    {
        $folded = CompatibilityFilter::fold($name);

        foreach ($available as $item) {
            /** @var StockItem $item */
            $food = $item->relationLoaded('food') ? $item->getRelation('food') : null;

            if ($ean !== null && ((string) $item->food_barcode === $ean || (string) ($food?->barcode ?? '') === $ean)) {
                return true;
            }

            if ($folded === '') {
                continue;
            }

            foreach ([$item->food_name, $food?->name] as $candidate) {
                $candidate = CompatibilityFilter::fold((string) $candidate);
                if ($candidate !== '' && (str_contains($candidate, $folded) || str_contains($folded, $candidate))) {
                    return true;
                }
            }
        }

        return false;
    }
}
