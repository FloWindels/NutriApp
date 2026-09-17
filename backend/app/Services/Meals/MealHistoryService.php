<?php

namespace App\Services\Meals;

use App\Models\DailyTarget;
use App\Models\MealItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Historique et fréquents (brief §4.2) :
 * - `GET /meals/history` : une ligne par jour de la période (≤ 92 jours) avec totaux des éléments,
 *   cibles figées (`daily_targets`) ou cibles effectives actuelles en repli, nombre de repas
 *   enregistrés, minutes et calories des séances terminées ;
 * - `GET /meals/frequent` : les 12 aliments/recettes les plus enregistrés sur 60 jours.
 */
class MealHistoryService
{
    public const MAX_DAYS = 92;

    public const DEFAULT_DAYS = 30;

    public const FREQUENT_DAYS = 60;

    public const FREQUENT_LIMIT = 12;

    public const MSG_PERIODE_TROP_LONGUE = 'La période ne peut pas dépasser 92 jours.';

    public function __construct(private readonly NutritionCalculator $nutrition)
    {
    }

    /**
     * Résout la période demandée : `to` = aujourd'hui par défaut, `from` = `to` − 29 jours par défaut.
     * Lève une ValidationException (422) si la période dépasse 92 jours.
     *
     * @return array{0: string, 1: string} [from, to]
     */
    public function resolveRange(User $user, ?string $from, ?string $to): array
    {
        $today = Clock::today($user);
        $tz = Clock::timezone($user);

        $toDate = CarbonImmutable::parse($to ?: $today, $tz)->startOfDay();
        $fromDate = $from
            ? CarbonImmutable::parse($from, $tz)->startOfDay()
            : $toDate->subDays(self::DEFAULT_DAYS - 1);

        if ($fromDate->greaterThan($toDate)) {
            throw ValidationException::withMessages([
                'to' => ['La date de fin doit être postérieure ou égale à la date de début.'],
            ]);
        }

        // Période inclusive : from..to compte (diff + 1) jours, plafonnée à 92.
        if ($fromDate->diffInDays($toDate) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => [self::MSG_PERIODE_TROP_LONGUE]]);
        }

        return [$fromDate->toDateString(), $toDate->toDateString()];
    }

    /**
     * Lignes d'historique, une par jour de `from` à `to` inclus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(User $user, string $from, string $to): array
    {
        $consumed = DB::table('meal_items')
            ->join('meals', 'meals.id', '=', 'meal_items.meal_id')
            ->where('meals.user_id', $user->id)
            ->whereBetween('meals.date', [$from, $to])
            ->groupBy('meals.date')
            ->selectRaw('meals.date as date')
            ->selectRaw('COALESCE(SUM(meal_items.calories), 0) as calories')
            ->selectRaw('COALESCE(SUM(meal_items.proteins), 0) as proteins')
            ->selectRaw('COALESCE(SUM(meal_items.carbs), 0) as carbs')
            ->selectRaw('COALESCE(SUM(meal_items.fat), 0) as fat')
            ->selectRaw('COUNT(DISTINCT meals.id) as meals_count')
            ->get()
            ->keyBy(fn ($row) => $this->dateKey($row->date));

        $targets = DailyTarget::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(fn (DailyTarget $t) => $t->date->format('Y-m-d'));

        $sport = DB::table('workout_sessions')
            ->where('user_id', $user->id)
            ->where('status', 'terminee')
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')
            ->selectRaw('date')
            ->selectRaw('COALESCE(SUM(duration_min), 0) as sport_minutes')
            ->selectRaw('COALESCE(SUM(calories_burned), 0) as calories_burned')
            ->get()
            ->keyBy(fn ($row) => $this->dateKey($row->date));

        $fallback = $this->currentTargets($user);

        $rows = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $day = $consumed->get($date);
            $target = $targets->get($date);
            $sportDay = $sport->get($date);

            $rows[] = [
                'date' => $date,
                'calories' => round((float) ($day?->calories ?? 0), 1),
                'proteins' => round((float) ($day?->proteins ?? 0), 1),
                'carbs' => round((float) ($day?->carbs ?? 0), 1),
                'fat' => round((float) ($day?->fat ?? 0), 1),
                'target_calories' => $target ? (int) $target->calories : $fallback['calories'],
                'target_proteins' => $target ? (int) $target->proteins : $fallback['proteins'],
                'target_carbs' => $target ? (int) $target->carbs : $fallback['carbs'],
                'target_fat' => $target ? (int) $target->fat : $fallback['fat'],
                'meals_count' => (int) ($day?->meals_count ?? 0),
                'sport_minutes' => (int) ($sportDay?->sport_minutes ?? 0),
                'calories_burned' => round((float) ($sportDay?->calories_burned ?? 0), 1),
            ];

            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    /**
     * Aliments / recettes les plus enregistrés sur 60 jours (top 12 par nombre d'occurrences).
     *
     * @return array<int, array<string, mixed>>
     */
    public function frequent(User $user): array
    {
        $since = CarbonImmutable::parse(Clock::today($user))->subDays(self::FREQUENT_DAYS)->toDateString();

        $groups = DB::table('meal_items')
            ->join('meals', 'meals.id', '=', 'meal_items.meal_id')
            ->where('meals.user_id', $user->id)
            ->where('meals.date', '>=', $since)
            ->where(function ($q) {
                $q->whereNotNull('meal_items.food_id')->orWhereNotNull('meal_items.recipe_id');
            })
            ->groupBy('meal_items.food_id', 'meal_items.recipe_id')
            ->selectRaw('meal_items.food_id as food_id, meal_items.recipe_id as recipe_id')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('MAX(meal_items.id) as last_item_id')
            ->orderByDesc('count')
            ->orderByDesc('last_item_id')
            ->limit(self::FREQUENT_LIMIT)
            ->get();

        if ($groups->isEmpty()) {
            return [];
        }

        $lastItems = MealItem::query()
            ->whereIn('id', $groups->pluck('last_item_id')->map(fn ($id) => (int) $id)->all())
            ->with(['food', 'recipe'])
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($groups as $group) {
            /** @var MealItem|null $item */
            $item = $lastItems->get((int) $group->last_item_id);
            if ($item === null) {
                continue;
            }

            $isFood = $group->food_id !== null;
            $food = $isFood ? $item->getRelation('food') : null;
            $recipe = $isFood ? null : $item->getRelation('recipe');

            $rows[] = [
                'kind' => $isFood ? 'food' : 'recipe',
                'id' => (int) ($isFood ? $group->food_id : $group->recipe_id),
                'label' => $isFood ? ($food?->name ?? $item->label) : ($recipe?->title ?? $item->label),
                'brand' => $isFood ? ($food?->brand) : null,
                'last_quantity' => (float) $item->quantity,
                'last_unit' => $item->unit,
                'calories_per_100g' => $isFood
                    ? (float) ($food?->calories ?? $item->ref_calories ?? 0)
                    : null,
                'calories_per_serving' => $isFood
                    ? null
                    : (float) ($recipe ? $recipe->perServing()['calories'] : ($item->ref_calories ?? 0)),
                'count' => (int) $group->count,
            ];
        }

        return $rows;
    }

    /**
     * Cibles effectives actuelles (repli quand `daily_targets` n'a pas de ligne).
     *
     * @return array{calories: int|null, proteins: int|null, carbs: int|null, fat: int|null}
     */
    private function currentTargets(User $user): array
    {
        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return ['calories' => null, 'proteins' => null, 'carbs' => null, 'fat' => null];
        }

        $cibles = $this->nutrition->ciblesEffectives($profile, Clock::today($user));

        return [
            'calories' => $cibles['calories'] === null ? null : (int) $cibles['calories'],
            'proteins' => $cibles['proteines'] === null ? null : (int) $cibles['proteines'],
            'carbs' => $cibles['glucides'] === null ? null : (int) $cibles['glucides'],
            'fat' => $cibles['lipides'] === null ? null : (int) $cibles['lipides'],
        ];
    }

    /**
     * Normalise une date brute (« 2026-09-16 » ou « 2026-09-16 00:00:00 ») en Y-m-d.
     */
    private function dateKey(mixed $raw): string
    {
        return substr((string) $raw, 0, 10);
    }
}
