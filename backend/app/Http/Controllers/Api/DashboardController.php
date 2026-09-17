<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardRequest;
use App\Http\Resources\RecommendationResource;
use App\Models\Meal;
use App\Models\NotificationRead;
use App\Models\Recommendation;
use App\Models\StockItem;
use App\Models\User;
use App\Models\WeightLog;
use App\Models\WorkoutSession;
use App\Services\Coach\CoachContext;
use App\Services\Coach\CoachEngine;
use App\Services\MealCalculator;
use App\Services\NutritionCalculator;
use App\Support\Clock;
use App\Support\StockScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * GET /dashboard?date= (brief §7) — tout est chargé en amont (≤ 20 requêtes en régime établi).
 */
class DashboardController extends Controller
{
    public const RECOMMANDATIONS_MAX = 4;
    public const STOCK_EXPIRING_MAX = 5;
    public const WEIGHT_HISTORY_MAX = 8;
    public const STREAK_LOOKBACK_DAYS = 60;
    public const STREAK_MIN_MINUTES = 10;

    public function __construct(
        private readonly MealCalculator $calculator,
        private readonly NutritionCalculator $nutrition,
        private readonly CoachEngine $coach,
    ) {
    }

    public function show(DashboardRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['profile', 'settings']);

        $date = Clock::date($user, $request->validated('date'));
        $today = Clock::today($user);
        $profile = $user->getRelation('profile');
        $settings = $user->getRelation('settings');

        // --- Repas & résumé -----------------------------------------------------------------
        $summary = $this->calculator->daySummary($user, $date);
        $summary['logged_types'] = CoachContext::normalizeTypes($summary['logged_types'] ?? []);
        $hour = $date === $today ? Clock::hour($user) : ($date < $today ? 24 : 0);
        $summary['next_meal_type'] = $this->calculator->nextMealType($summary['logged_types'], $hour);

        $meals = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->with(['items.food'])
            ->get();

        // --- Stock --------------------------------------------------------------------------
        $stockItems = StockScope::items($user)->with(['stock', 'food'])->get();
        $joursAlerte = max(1, (int) ($settings?->jours_alerte_peremption ?? 3));

        // --- Sport (une requête : du jour et des 60 derniers jours) --------------------------
        $since = CarbonImmutable::parse($date)->subDays(self::STREAK_LOOKBACK_DAYS)->toDateString();
        $allSessions = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$since, $date])
            ->orderBy('date')
            ->orderBy('id')
            ->get();
        $sessionsToday = $allSessions->filter(fn (WorkoutSession $s) => $s->date->format('Y-m-d') === $date)->values();

        // --- Recommandations (générées au plus une fois par minute) --------------------------
        $context = $this->coach->context($user, $date, [
            'summary' => $summary,
            'meals' => $meals,
            'stockItems' => $stockItems,
            'sessions' => $sessionsToday,
        ]);
        $this->coach->generateIfDue($user, $date, $context);
        $recommendations = $this->coach->listForDay($user, $date, false, self::RECOMMANDATIONS_MAX);

        // --- Poids --------------------------------------------------------------------------
        $weights = WeightLog::query()
            ->where('user_id', $user->id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(self::WEIGHT_HISTORY_MAX)
            ->get(['date', 'weight_kg'])
            ->sortBy(fn (WeightLog $w) => $w->date->format('Y-m-d'))
            ->values();

        $besoins = ($profile && $context->hasProfile) ? $this->nutrition->fromProfile($profile, $date) : null;

        // --- Notifications non lues ---------------------------------------------------------
        $notificationsUnread = $this->notificationsUnread($user, $stockItems, $sessionsToday, $recommendations, $context, $joursAlerte);

        $targets = $summary['targets'];
        $consumed = $summary['totals'];
        $remaining = $summary['remaining'];
        $bonus = (int) ($summary['sport']['calories_bonus'] ?? 0);
        $targetCalories = $targets['calories'];
        $progress = ($targetCalories !== null && ($targetCalories + $bonus) > 0)
            ? (int) round((float) $consumed['calories'] / ($targetCalories + $bonus) * 100)
            : null;

        return response()->json([
            'data' => [
                'date' => $date,
                'user' => [
                    'name' => (string) $user->name,
                    'first_name' => $this->firstName((string) $user->name),
                ],
                'has_profile' => $context->hasProfile,
                'targets' => $targets,
                'consumed' => $consumed,
                'remaining' => $remaining,
                'progress_pct' => $progress,
                'calories_bonus' => $bonus,
                'plancher_kcal' => (int) ($summary['plancher_kcal'] ?? 0),
                'is_estimate' => $context->hasProfile || (bool) ($summary['is_partial'] ?? false) || (bool) ($summary['sport']['is_estimate'] ?? false),
                'meals' => $meals->map(fn (Meal $meal) => [
                    'id' => (int) $meal->id,
                    'type' => $meal->type instanceof MealType ? $meal->type->value : (string) $meal->type,
                    'name' => $meal->name,
                    'calories' => round((float) $meal->items->sum(fn ($i) => (float) $i->calories), 1),
                    'items_count' => $meal->items->count(),
                ])->values()->all(),
                'next_meal_type' => (string) $summary['next_meal_type'],
                'stock' => $this->stockBlock($stockItems, $context, $joursAlerte),
                'sport' => $this->sportBlock($allSessions, $sessionsToday, $date, $today, $user),
                'recommendations' => RecommendationResource::collection($recommendations)->resolve(),
                'weight' => [
                    'current' => $weights->isNotEmpty() ? (float) $weights->last()->weight_kg : ($profile?->poids !== null ? (float) $profile->poids : null),
                    'target' => $profile?->poids_souhaite_kg !== null ? (float) $profile->poids_souhaite_kg : null,
                    'history' => $weights->map(fn (WeightLog $w) => [
                        'date' => $w->date->format('Y-m-d'),
                        'weight_kg' => (float) $w->weight_kg,
                    ])->values()->all(),
                    'variation_hebdo_kg' => $besoins !== null ? (float) $besoins['variation_hebdo_kg'] : null,
                ],
                'notifications_unread' => $notificationsUnread,
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------

    /**
     * @param  Collection<int, StockItem>  $stockItems
     * @return array{expiring_count: int, expired_count: int, low_count: int, expiring: list<array<string, mixed>>}
     */
    private function stockBlock(Collection $stockItems, CoachContext $context, int $joursAlerte): array
    {
        $available = $context->availableStock();
        $expiring = collect();
        $expired = 0;
        $low = 0;

        foreach ($stockItems as $item) {
            if ($item->isLow()) {
                $low++;
            }
        }
        foreach ($available as $item) {
            $days = $context->daysLeft($item);
            if ($days === null) {
                continue;
            }
            if ($days < 0) {
                $expired++;
            } elseif ($days <= $joursAlerte) {
                $expiring->push([$item, $days]);
            }
        }

        $expiringSorted = $expiring->sortBy(fn (array $pair) => [$pair[1], $pair[0]->id])->values();

        return [
            'expiring_count' => $expiringSorted->count(),
            'expired_count' => $expired,
            'low_count' => $low,
            'expiring' => $expiringSorted->take(self::STOCK_EXPIRING_MAX)->map(fn (array $pair) => [
                'id' => (int) $pair[0]->id,
                'label' => (string) ($pair[0]->food_name ?: ($pair[0]->food?->name ?? 'Article')),
                'expires_at' => $pair[0]->expires_at?->format('Y-m-d'),
                'days_left' => (int) $pair[1],
                'stock_name' => $pair[0]->stock?->name,
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, WorkoutSession>  $all  séances des 60 derniers jours (triées par date)
     * @param  Collection<int, WorkoutSession>  $today  séances de la date demandée
     * @return array<string, mixed>
     */
    private function sportBlock(Collection $all, Collection $today, string $date, string $realToday, User $user): array
    {
        $lite = fn (WorkoutSession $s) => [
            'id' => (int) $s->id,
            'title' => (string) $s->title,
            'status' => (string) $s->status,
            'duration_min' => (int) $s->duration_min,
            'calories_burned' => $s->calories_burned !== null ? (float) $s->calories_burned : null,
            'planned_at' => $s->planned_at ? substr((string) $s->planned_at, 0, 5) : null,
            'sport_name' => $s->sport_name,
            'kind' => (string) $s->kind,
        ];

        $weekStart = Clock::weekStart($user, $date);
        $weekSessions = $all->filter(fn (WorkoutSession $s) => $s->status === 'terminee'
            && $s->date->format('Y-m-d') >= $weekStart
            && $s->date->format('Y-m-d') <= $date);

        // Série : jours consécutifs (en remontant depuis $date, ou la veille si rien ce jour) avec ≥ 1 séance terminée ≥ 10 min.
        $doneDays = $all
            ->filter(fn (WorkoutSession $s) => $s->status === 'terminee' && (int) $s->duration_min >= self::STREAK_MIN_MINUTES)
            ->map(fn (WorkoutSession $s) => $s->date->format('Y-m-d'))
            ->unique()
            ->flip();

        $cursor = CarbonImmutable::parse($date);
        if (! $doneDays->has($cursor->toDateString())) {
            $cursor = $cursor->subDay();
        }
        $streak = 0;
        while ($doneDays->has($cursor->toDateString()) && $streak <= self::STREAK_LOOKBACK_DAYS) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return [
            'sessions_today' => $today->map($lite)->values()->all(),
            'calories_burned' => round((float) $today->filter(fn ($s) => $s->status === 'terminee')->sum(fn ($s) => (float) ($s->calories_burned ?? 0)), 1),
            'planned' => $today->filter(fn ($s) => $s->status === 'prevue')->map($lite)->values()->all(),
            'week_minutes' => (int) $weekSessions->sum(fn ($s) => (int) $s->duration_min),
            'week_sessions' => $weekSessions->count(),
            'streak_days' => $streak,
        ];
    }

    /**
     * Compte des notifications non lues, générées à la volée comme §14 : péremptions (si notif_peremption),
     * périmés, séance prévue du jour (si notif_rappel_sport), recommandations p1. Clés stables
     * « {type}:{id} » comparées à notification_reads.
     *
     * @param  Collection<int, StockItem>  $stockItems
     * @param  Collection<int, WorkoutSession>  $sessionsToday
     * @param  Collection<int, Recommendation>  $recommendations
     */
    private function notificationsUnread(User $user, Collection $stockItems, Collection $sessionsToday, Collection $recommendations, CoachContext $context, int $joursAlerte): int
    {
        $settings = $user->getRelation('settings');
        $keys = [];

        foreach ($context->availableStock() as $item) {
            $days = $context->daysLeft($item);
            if ($days === null) {
                continue;
            }
            if ($days < 0) {
                $keys[] = 'perime:'.$item->id;
            } elseif ($days <= $joursAlerte && (bool) ($settings?->notif_peremption ?? true)) {
                $keys[] = 'peremption:'.$item->id;
            }
        }

        if ((bool) ($settings?->notif_rappel_sport ?? false)) {
            foreach ($sessionsToday as $session) {
                if ($session->status === 'prevue') {
                    $keys[] = 'seance:'.$session->id;
                }
            }
        }

        foreach ($recommendations as $reco) {
            if ((int) $reco->priority === 1) {
                $keys[] = 'reco:'.$reco->id;
            }
        }

        if ($keys === []) {
            return 0;
        }

        $read = NotificationRead::query()
            ->where('user_id', $user->id)
            ->whereIn('key', $keys)
            ->count();

        return max(0, count($keys) - $read);
    }

    private function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $name) ?: [$name];

        return (string) $parts[0];
    }
}
