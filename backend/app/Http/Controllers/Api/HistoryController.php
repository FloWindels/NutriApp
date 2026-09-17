<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\HistoryRequest;
use App\Models\DailyTarget;
use App\Models\Meal;
use App\Models\WeightLog;
use App\Models\WorkoutSession;
use App\Services\MealCalculator;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;

class HistoryController extends Controller
{
    public const JOURS_DEFAUT = 30;
    public const ADHERENCE_TOLERANCE = 0.10;

    public function __construct(
        private readonly MealCalculator $calculator,
        private readonly NutritionCalculator $nutrition,
    ) {
    }

    /**
     * GET /history?from=&to= → {data:{days:[…], weights:[…], summary:{avg_calories, days_logged, adherence_pct}}}
     */
    public function index(HistoryRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['profile', 'settings']);

        $today = Clock::today($user);
        $to = $request->validated('to') ?: $today;
        $from = $request->validated('from') ?: CarbonImmutable::parse($to)->subDays(self::JOURS_DEFAUT - 1)->toDateString();

        // Repas + items de la période, groupés par jour.
        $meals = Meal::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->with('items')
            ->get()
            ->groupBy(fn (Meal $meal) => $meal->date->format('Y-m-d'));

        $targets = DailyTarget::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(fn (DailyTarget $t) => $t->date->format('Y-m-d'));

        $sessions = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->where('status', 'terminee')
            ->get(['id', 'date', 'duration_min', 'calories_burned'])
            ->groupBy(fn (WorkoutSession $s) => $s->date->format('Y-m-d'));

        $weights = WeightLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get(['date', 'weight_kg']);

        // Cibles courantes (repli quand daily_targets n'a pas de ligne).
        $profile = $user->getRelation('profile');
        $current = $profile ? $this->nutrition->ciblesEffectives($profile, $today) : null;
        $fallback = [
            'calories' => $current['calories'] ?? null,
            'proteins' => $current['proteines'] ?? null,
            'carbs' => $current['glucides'] ?? null,
            'fat' => $current['lipides'] ?? null,
        ];

        $days = [];
        $sumCalories = 0.0;
        $daysLogged = 0;
        $adherent = 0;

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $dayMeals = $meals->get($date, collect())->filter(fn (Meal $m) => $m->items->isNotEmpty());
            $items = $dayMeals->flatMap(fn (Meal $m) => $m->items);
            $totals = $this->calculator->totalsForItems($items);
            $target = $targets->get($date);
            $daySessions = $sessions->get($date, collect());

            $targetCalories = $target ? (int) $target->calories : $fallback['calories'];
            $row = [
                'date' => $date,
                'calories' => (float) $totals['calories'],
                'proteins' => (float) $totals['proteins'],
                'carbs' => (float) $totals['carbs'],
                'fat' => (float) $totals['fat'],
                'target_calories' => $targetCalories === null ? null : (int) $targetCalories,
                'target_proteins' => $target ? (int) $target->proteins : ($fallback['proteins'] === null ? null : (int) $fallback['proteins']),
                'target_carbs' => $target ? (int) $target->carbs : ($fallback['carbs'] === null ? null : (int) $fallback['carbs']),
                'target_fat' => $target ? (int) $target->fat : ($fallback['fat'] === null ? null : (int) $fallback['fat']),
                'meals_count' => $dayMeals->count(),
                'sport_minutes' => (int) $daySessions->sum(fn ($s) => (int) $s->duration_min),
                'calories_burned' => round((float) $daySessions->sum(fn ($s) => (float) ($s->calories_burned ?? 0)), 1),
            ];
            $days[] = $row;

            if ($row['meals_count'] > 0) {
                $daysLogged++;
                $sumCalories += $row['calories'];
                if ($targetCalories !== null && $targetCalories > 0
                    && abs($row['calories'] - $targetCalories) <= self::ADHERENCE_TOLERANCE * $targetCalories) {
                    $adherent++;
                }
            }
        }

        return response()->json([
            'data' => [
                'from' => $from,
                'to' => $to,
                'days' => $days,
                'weights' => $weights->map(fn (WeightLog $w) => [
                    'date' => $w->date->format('Y-m-d'),
                    'weight_kg' => (float) $w->weight_kg,
                ])->values()->all(),
                'summary' => [
                    'avg_calories' => $daysLogged > 0 ? round($sumCalories / $daysLogged) : null,
                    'days_logged' => $daysLogged,
                    'adherence_pct' => $daysLogged > 0 ? (int) round($adherent / $daysLogged * 100) : null,
                ],
            ],
        ]);
    }
}
