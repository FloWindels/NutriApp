<?php

namespace App\Services;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Clock;
use App\Support\Portions;

/**
 * Calculs des repas (brief §4) : instantané d'un élément (`snapshot`), recalcul d'une
 * quantité depuis les références figées (`recompute`), totaux et résumé de journée.
 */
class MealCalculator
{
    /** Heures par défaut des repas (évaluation du jeûne, sport_pre, next_meal_type). */
    public const DEFAULT_HOURS = [
        'petit_dejeuner' => '08:00',
        'dejeuner' => '12:30',
        'collation' => '16:30',
        'diner' => '19:30',
    ];

    public const MACROS = ['calories', 'proteins', 'carbs', 'fat'];
    public const EXTRAS = ['fiber', 'sugar', 'salt'];

    public function __construct(
        private readonly NutritionCalculator $nutrition,
        private readonly SportNutrition $sport,
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Instantané d'un élément de repas
    // ------------------------------------------------------------------------------------

    /**
     * Construit les colonnes `meal_items` (hors meal_id / stock_item_id) pour un ItemInput :
     * `{food_id? | recipe_id? | custom?:{label, per_100g?, calories, proteins, carbs, fat}, quantity, unit}`.
     *
     * @param  array<string, mixed>  $itemInput
     * @return array<string, mixed>
     */
    public function snapshot(array $itemInput, ?Food $food = null, ?Recipe $recipe = null): array
    {
        $qty = max(0.0, (float) ($itemInput['quantity'] ?? 1));
        $unitInput = (string) ($itemInput['unit'] ?? ($recipe ? 'portion' : 'g'));

        if ($food !== null) {
            return $this->snapshotFood($food, $qty, $unitInput, $itemInput['label'] ?? null);
        }

        if ($recipe !== null) {
            return $this->snapshotRecipe($recipe, $qty, $itemInput['label'] ?? null);
        }

        $custom = is_array($itemInput['custom'] ?? null) ? $itemInput['custom'] : [];

        return $this->snapshotCustom($custom, $qty, $unitInput);
    }

    /**
     * Recalcule un élément existant pour une nouvelle quantité/unité à partir de ses `ref_*`.
     * Retourne les colonnes à mettre à jour.
     *
     * @return array<string, mixed>
     */
    public function recompute(MealItem $item, float $quantity, string $unit, ?Food $food = null): array
    {
        $qty = max(0.0, $quantity);
        $refs = [
            'calories' => $this->f($item->ref_calories),
            'proteins' => $this->f($item->ref_proteins),
            'carbs' => $this->f($item->ref_carbs),
            'fat' => $this->f($item->ref_fat),
            'fiber' => $this->f($item->ref_fiber),
            'sugar' => $this->f($item->ref_sugar),
            'salt' => $this->f($item->ref_salt),
        ];

        switch ($item->ref_basis) {
            case 'per_serving':
                return [
                    'quantity' => round($qty, 2),
                    'unit' => 'portion',
                    'grams_equivalent' => null,
                    'is_estimate' => (bool) $item->is_estimate,
                ] + $this->scale($refs, $qty);

            case 'absolute':
                $canonical = Portions::canonical($unit) ?? $item->unit;
                $qtyCanonical = $qty * Portions::normalize($unit)[1];

                return [
                    'quantity' => round($qtyCanonical, 2),
                    'unit' => $canonical,
                    'grams_equivalent' => in_array($canonical, ['g', 'ml'], true) ? round($qtyCanonical, 2) : null,
                    'is_estimate' => (bool) $item->is_estimate,
                ] + $this->scale($refs, $qtyCanonical);

            case 'per_100g':
            default:
                if ($food === null && $item->relationLoaded('food')) {
                    $food = $item->getRelation('food');
                }
                if ($food === null && $item->ref_serving_size_g !== null) {
                    $food = (new Food)->forceFill(['serving_size_g' => (float) $item->ref_serving_size_g]);
                }

                $conversion = Portions::toGrams($qty, $unit, $food);
                if (! $conversion->isConvertible()) {
                    $conversion = Portions::toGrams($qty, 'piece', $food);
                }
                $grams = (float) $conversion->grams;

                return [
                    'quantity' => round($conversion->quantity, 2),
                    'unit' => $conversion->unit,
                    'grams_equivalent' => round($grams, 2),
                    'is_estimate' => $conversion->is_estimate || $this->hasMissingMacros($refs),
                ] + $this->scale($refs, $grams / 100);
        }
    }

    // ------------------------------------------------------------------------------------
    // Totaux
    // ------------------------------------------------------------------------------------

    /**
     * Totaux d'un repas (items déjà chargés).
     *
     * @return array{calories: float, proteins: float, carbs: float, fat: float, fiber: float|null, sugar: float|null, salt: float|null, is_partial: bool}
     */
    public function totalsForMeal(Meal $meal): array
    {
        $items = $meal->relationLoaded('items')
            ? $meal->getRelation('items')
            : MealItem::query()->where('meal_id', $meal->id)->get();

        return $this->totalsForItems($items);
    }

    /**
     * @param  iterable<MealItem>  $items
     * @return array{calories: float, proteins: float, carbs: float, fat: float, fiber: float|null, sugar: float|null, salt: float|null, is_partial: bool}
     */
    public function totalsForItems(iterable $items): array
    {
        $totals = ['calories' => 0.0, 'proteins' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        $extras = ['fiber' => null, 'sugar' => null, 'salt' => null];
        $isPartial = false;

        foreach ($items as $item) {
            foreach (self::MACROS as $macro) {
                $totals[$macro] += (float) ($item->{$macro} ?? 0);
            }
            foreach (self::EXTRAS as $extra) {
                $value = $item->{$extra};
                if ($value !== null) {
                    $extras[$extra] = ($extras[$extra] ?? 0.0) + (float) $value;
                }
            }
            if ($item->is_estimate) {
                $isPartial = true;
            }
        }

        $out = [];
        foreach ($totals as $key => $value) {
            $out[$key] = round($value, 1);
        }
        foreach ($extras as $key => $value) {
            $out[$key] = $value === null ? null : round($value, 1);
        }
        $out['is_partial'] = $isPartial;

        return $out;
    }

    // ------------------------------------------------------------------------------------
    // Résumé de journée
    // ------------------------------------------------------------------------------------

    /**
     * Résumé d'une journée (sans la liste des repas, ajoutée par le module M4).
     *
     * @return array<string, mixed> {date, totals, targets, remaining, sport, next_meal_type, plancher_kcal, is_partial, logged_types, regime, has_profile}
     */
    public function daySummary(User $user, string $date): array
    {
        $meals = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->get(['id', 'type']);

        $items = $meals->isEmpty()
            ? collect()
            : MealItem::query()->whereIn('meal_id', $meals->pluck('id'))->get();

        $itemsByMeal = $items->groupBy('meal_id');
        $loggedTypes = $meals
            ->filter(fn (Meal $meal) => ($itemsByMeal->get($meal->id)?->count() ?? 0) > 0)
            ->pluck('type')
            ->unique()
            ->values()
            ->all();

        $totals = $this->totalsForItems($items);

        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first();

        $besoins = $profile ? $this->nutrition->fromProfile($profile, $date) : null;
        $cibles = $profile ? $this->nutrition->ciblesEffectives($profile, $date) : null;

        $targets = [
            'calories' => $cibles['calories'] ?? null,
            'proteins' => $cibles['proteines'] ?? null,
            'carbs' => $cibles['glucides'] ?? null,
            'fat' => $cibles['lipides'] ?? null,
            'fiber' => $besoins['fibres_g'] ?? null,
            'sugar' => null,
            'salt' => $besoins['sel_max_g'] ?? null,
            'is_partial' => false,
        ];

        $sport = $this->sport->bonusForDay($user, $date);

        $remaining = [
            'calories' => $targets['calories'] === null ? null : round($targets['calories'] + $sport['calories_bonus'] - $totals['calories'], 1),
            'proteins' => $this->diff($targets['proteins'], $totals['proteins']),
            'carbs' => $this->diff($targets['carbs'], $totals['carbs']),
            'fat' => $this->diff($targets['fat'], $totals['fat']),
            'fiber' => $this->diff($targets['fiber'], $totals['fiber']),
            'sugar' => null,
            'salt' => $this->diff($targets['salt'], $totals['salt']),
            'is_partial' => $totals['is_partial'],
        ];

        $today = Clock::today($user);
        $hour = $date === $today ? Clock::hour($user) : ($date < $today ? 24 : 0);

        return [
            'date' => $date,
            'totals' => $totals,
            'targets' => $targets,
            'remaining' => $remaining,
            'sport' => $sport,
            'next_meal_type' => $this->nextMealType($loggedTypes, $hour),
            'plancher_kcal' => (int) ($besoins['plancher_kcal'] ?? 0),
            'is_partial' => $totals['is_partial'],
            'logged_types' => $loggedTypes,
            'regime' => $profile?->regime_alimentaire,
            'has_profile' => $profile !== null && $this->nutrition->isComplete($profile),
        ];
    }

    /**
     * Prochain type de repas : premier type non enregistré dont la fenêtre (heure par défaut + 2 h 30)
     * n'est pas passée ; sinon le dernier type non enregistré ; « collation » si tout est enregistré.
     *
     * @param  array<int, string>  $loggedTypes
     */
    public function nextMealType(array $loggedTypes, int $hour): string
    {
        $ordered = self::DEFAULT_HOURS;
        asort($ordered);

        $lastUnlogged = null;
        foreach ($ordered as $type => $time) {
            if (in_array($type, $loggedTypes, true)) {
                continue;
            }
            $lastUnlogged = $type;
            [$h, $m] = array_map('intval', explode(':', $time));
            if ($hour < $h + $m / 60 + 2.5) {
                return $type;
            }
        }

        return $lastUnlogged ?? 'collation';
    }

    /**
     * Heure par défaut d'un type de repas (« HH:MM »).
     */
    public static function defaultHour(string $type): string
    {
        return self::DEFAULT_HOURS[$type] ?? '12:30';
    }

    // ------------------------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function snapshotFood(Food $food, float $qty, string $unit, ?string $label): array
    {
        $conversion = Portions::toGrams($qty, $unit, $food);
        if (! $conversion->isConvertible()) {
            $conversion = Portions::toGrams($qty, 'piece', $food);
        }
        $grams = (float) $conversion->grams;

        $refs = [
            'calories' => $this->f($food->calories),
            'proteins' => $this->f($food->proteins),
            'carbs' => $this->f($food->carbs),
            'fat' => $this->f($food->fat),
            'fiber' => $this->f($food->fiber ?? null),
            'sugar' => $this->f($food->sugar ?? null),
            'salt' => $this->f($food->salt ?? null),
        ];

        return [
            'food_id' => $food->id,
            'recipe_id' => null,
            'source_type' => 'food',
            'label' => $this->label($label, $food->name),
            'quantity' => round($conversion->quantity, 2),
            'unit' => $conversion->unit,
            'grams_equivalent' => round($grams, 2),
            'ref_basis' => 'per_100g',
            'ref_serving_size_g' => $this->f($food->serving_size_g ?? null),
            'is_estimate' => $conversion->is_estimate || $this->hasMissingMacros($refs),
        ] + $this->scale($refs, $grams / 100) + $this->refColumns($refs);
    }

    /** @return array<string, mixed> */
    private function snapshotRecipe(Recipe $recipe, float $qty, ?string $label): array
    {
        $servings = $this->f($recipe->servings ?? null) ?? 1.0;
        $servings = $servings > 0 ? $servings : 1.0;

        $refs = [
            'calories' => $this->div($this->f($recipe->calories), $servings),
            'proteins' => $this->div($this->f($recipe->proteins ?? null), $servings),
            'carbs' => $this->div($this->f($recipe->carbs ?? null), $servings),
            'fat' => $this->div($this->f($recipe->fat ?? null), $servings),
            'fiber' => null,
            'sugar' => null,
            'salt' => null,
        ];

        $missingMacros = $refs['proteins'] === null || $refs['carbs'] === null || $refs['fat'] === null;

        return [
            'food_id' => null,
            'recipe_id' => $recipe->id,
            'source_type' => 'recipe',
            'label' => $this->label($label, $recipe->title),
            'quantity' => round($qty, 2),
            'unit' => 'portion',
            'grams_equivalent' => null,
            'ref_basis' => 'per_serving',
            'ref_serving_size_g' => null,
            'is_estimate' => $missingMacros || (bool) ($recipe->is_estimate ?? false),
        ] + $this->scale($refs, $qty) + $this->refColumns($refs);
    }

    /**
     * @param  array<string, mixed>  $custom
     * @return array<string, mixed>
     */
    private function snapshotCustom(array $custom, float $qty, string $unit): array
    {
        $per100 = filter_var($custom['per_100g'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $label = $this->label($custom['label'] ?? null, 'Aliment personnalisé');

        $values = [
            'calories' => $this->f($custom['calories'] ?? 0) ?? 0.0,
            'proteins' => $this->f($custom['proteins'] ?? 0) ?? 0.0,
            'carbs' => $this->f($custom['carbs'] ?? 0) ?? 0.0,
            'fat' => $this->f($custom['fat'] ?? 0) ?? 0.0,
            'fiber' => $this->f($custom['fiber'] ?? null),
            'sugar' => $this->f($custom['sugar'] ?? null),
            'salt' => $this->f($custom['salt'] ?? null),
        ];

        if ($per100) {
            $conversion = Portions::toGrams($qty, $unit, null);
            if (! $conversion->isConvertible()) {
                $conversion = Portions::toGrams($qty, 'piece', null);
            }
            $grams = (float) $conversion->grams;

            return [
                'food_id' => null,
                'recipe_id' => null,
                'source_type' => 'custom',
                'label' => $label,
                'quantity' => round($conversion->quantity, 2),
                'unit' => $conversion->unit,
                'grams_equivalent' => round($grams, 2),
                'ref_basis' => 'per_100g',
                'ref_serving_size_g' => null,
                'is_estimate' => $conversion->is_estimate,
            ] + $this->scale($values, $grams / 100) + $this->refColumns($values);
        }

        // Valeurs absolues : les macros saisies sont les totaux pour la quantité donnée ;
        // les références sont ramenées « par unité de quantité » pour permettre le recalcul.
        [$canonical, $factor] = Portions::normalize($unit);
        $canonical ??= 'portion';
        $qtyCanonical = $qty * $factor;
        $perUnit = $qtyCanonical > 0 ? $qtyCanonical : 1.0;

        $refs = [];
        foreach ($values as $key => $value) {
            $refs[$key] = $value === null ? null : $value / $perUnit;
        }

        return [
            'food_id' => null,
            'recipe_id' => null,
            'source_type' => 'custom',
            'label' => $label,
            'quantity' => round($qtyCanonical, 2),
            'unit' => $canonical,
            'grams_equivalent' => in_array($canonical, ['g', 'ml'], true) ? round($qtyCanonical, 2) : null,
            'ref_basis' => 'absolute',
            'ref_serving_size_g' => null,
            'is_estimate' => false,
        ] + $this->roundAll($values) + $this->refColumns($refs);
    }

    /**
     * @param  array<string, float|null>  $refs
     * @return array<string, float|null>
     */
    private function scale(array $refs, float $factor): array
    {
        $out = [];
        foreach (self::MACROS as $macro) {
            $out[$macro] = round(($refs[$macro] ?? 0.0) * $factor, 2);
        }
        foreach (self::EXTRAS as $extra) {
            $out[$extra] = $refs[$extra] === null ? null : round($refs[$extra] * $factor, 2);
        }

        return $out;
    }

    /**
     * @param  array<string, float|null>  $values
     * @return array<string, float|null>
     */
    private function roundAll(array $values): array
    {
        $out = [];
        foreach (self::MACROS as $macro) {
            $out[$macro] = round((float) ($values[$macro] ?? 0.0), 2);
        }
        foreach (self::EXTRAS as $extra) {
            $out[$extra] = $values[$extra] === null ? null : round($values[$extra], 2);
        }

        return $out;
    }

    /**
     * @param  array<string, float|null>  $refs
     * @return array<string, float|null>
     */
    private function refColumns(array $refs): array
    {
        return [
            'ref_calories' => $this->r($refs['calories']),
            'ref_proteins' => $this->r($refs['proteins']),
            'ref_carbs' => $this->r($refs['carbs']),
            'ref_fat' => $this->r($refs['fat']),
            'ref_fiber' => $this->r($refs['fiber']),
            'ref_sugar' => $this->r($refs['sugar']),
            'ref_salt' => $this->r($refs['salt']),
        ];
    }

    /** @param array<string, float|null> $refs */
    private function hasMissingMacros(array $refs): bool
    {
        foreach (self::MACROS as $macro) {
            if ($refs[$macro] === null) {
                return true;
            }
        }

        return false;
    }

    private function label(?string $label, ?string $fallback): string
    {
        $label = trim((string) $label);
        if ($label !== '') {
            return mb_substr($label, 0, 255);
        }

        $fallback = trim((string) $fallback);

        return $fallback !== '' ? mb_substr($fallback, 0, 255) : 'Aliment';
    }

    private function diff(?float $target, ?float $consumed): ?float
    {
        if ($target === null) {
            return null;
        }

        return round($target - (float) ($consumed ?? 0), 1);
    }

    private function div(?float $value, float $by): ?float
    {
        return $value === null ? null : $value / $by;
    }

    private function f(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function r(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }
}
