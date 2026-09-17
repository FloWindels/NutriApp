<?php

namespace App\Http\Controllers\Api\Sport;

use App\Enums\PlanStatus;
use App\Enums\SessionStatus;
use App\Http\Resources\Sport\SportPlanResource;
use App\Http\Resources\Sport\WorkoutSessionResource;
use App\Models\Profile;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Coach\RecommendationCopy;
use App\Services\Sport\CaloriesEstimator;
use App\Services\Sport\StreakCalculator;
use App\Services\SportNutrition;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /sport/summary?date= (brief §13.2 + addendum §C.3) : tuiles « Aujourd'hui / Semaine / Série »,
 * bonus calorique réintégré, conseils avant / après séance, séance en cours, prochaine séance
 * planifiée et plans de la semaine.
 */
class SportSummaryController extends SportController
{
    /** Durée minimale d'une séance pour déclencher le conseil « avant séance ». */
    public const RECO_PRE_DUREE_MIN = 30;

    public function __construct(
        private readonly SportNutrition $nutrition,
        private readonly StreakCalculator $streak,
        private readonly CaloriesEstimator $calories,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $date = Clock::date($user, $request->query('date'));

        $weekStart = Clock::weekStart($user, $date);
        $weekEnd = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();

        /** @var Collection<int, WorkoutSession> $weekSessions */
        $weekSessions = $this->sessions($user)
            ->with(['exercises', 'exercises.exercise'])
            ->whereBetween('date', [min($weekStart, $date), max($weekEnd, $date)])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $today = $weekSessions->filter(fn (WorkoutSession $s) => $s->date->format('Y-m-d') === $date)->values();
        $todayDone = $today->where('status', SessionStatus::Terminee->value);

        $weekDone = $weekSessions
            ->filter(fn (WorkoutSession $s) => $s->status === SessionStatus::Terminee->value
                && $s->date->format('Y-m-d') >= $weekStart
                && $s->date->format('Y-m-d') <= $weekEnd)
            ->values();

        /** @var WorkoutSession|null $active */
        $active = $weekSessions->last(fn (WorkoutSession $s) => $s->status === SessionStatus::EnCours->value);

        /** @var Collection<int, SportPlan> $weekPlans */
        $weekPlans = $this->plans($user)
            ->with('sport')
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->orderBy('date')
            ->orderByRaw('planned_at IS NULL')
            ->orderBy('planned_at')
            ->orderBy('id')
            ->get();

        /** @var SportPlan|null $next */
        $next = $this->plans($user)
            ->where('status', PlanStatus::Prevu->value)
            ->where('date', '>=', $date)
            ->orderBy('date')
            ->orderByRaw('planned_at IS NULL')
            ->orderBy('planned_at')
            ->orderBy('id')
            ->first();

        $profile = Profile::query()->where('user_id', $user->id)->first();

        return $this->json([
            'data' => [
                'date' => $date,
                'today' => [
                    'sessions' => WorkoutSessionResource::collection($today)->resolve($request),
                    'calories_burned' => round((float) $todayDone->sum(fn (WorkoutSession $s) => (float) ($s->calories_burned ?? 0)), 1),
                    'minutes' => (int) $todayDone->sum(fn (WorkoutSession $s) => (int) $s->duration_min),
                ],
                'week' => [
                    'week_start' => $weekStart,
                    'sessions' => $weekDone->count(),
                    'minutes' => (int) $weekDone->sum(fn (WorkoutSession $s) => (int) $s->duration_min),
                    'calories' => round((float) $weekDone->sum(fn (WorkoutSession $s) => (float) ($s->calories_burned ?? 0)), 1),
                ],
                'streak_days' => $this->streak->streakDays($user, $date),
                'nutrition' => $this->nutrition->bonusForDay($user, $date),
                'coef_calories' => $this->nutrition->coefficient($user),
                'recos' => [
                    'pre' => $this->recoPre($user, $profile, $today, $next, $date),
                    'post' => $this->recoPost($user, $todayDone, $date),
                ],
                'active_session_id' => $active !== null ? (int) $active->id : null,
                'next_plan' => $next === null ? null : [
                    'id' => (int) $next->id,
                    'date' => $next->date->format('Y-m-d'),
                    'sport_id' => $next->sport_id !== null ? (int) $next->sport_id : null,
                    'sport_name' => (string) $next->sport_name,
                    'planned_duration_min' => (int) $next->planned_duration_min,
                    'planned_at' => SportPlanResource::time($next->planned_at),
                    'lieu' => $next->lieu,
                ],
                'week_plans' => SportPlanResource::collection($weekPlans)->resolve($request),
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------

    /**
     * Conseil « avant séance » : glucides 1–2 h avant, adapté au régime (brief §8, type `sport_pre`).
     *
     * @param  Collection<int, WorkoutSession>  $today
     */
    private function recoPre(User $user, ?Profile $profile, Collection $today, ?SportPlan $next, string $date): ?string
    {
        $planned = $today->first(fn (WorkoutSession $s) => $s->status === SessionStatus::Prevue->value
            && (int) $s->duration_min >= self::RECO_PRE_DUREE_MIN);

        $titre = null;
        $heure = null;
        $duree = 0;

        if ($planned !== null) {
            $titre = (string) $planned->title;
            $heure = SportPlanResource::time($planned->planned_at);
            $duree = (int) $planned->duration_min;
        } elseif ($next !== null && $next->date->format('Y-m-d') === $date && (int) $next->planned_duration_min >= self::RECO_PRE_DUREE_MIN) {
            $titre = (string) $next->sport_name;
            $heure = SportPlanResource::time($next->planned_at);
            $duree = (int) $next->planned_duration_min;
        }

        if ($titre === null) {
            return null;
        }

        $heure ??= '18:30';

        if (in_array($profile?->regime_alimentaire, ['keto', 'low_carb'], true)) {
            return RecommendationCopy::sportPreKeto($titre, $heure)[1];
        }

        $poids = $this->calories->weightFor($user, $date);
        $gkg = $duree < 45 ? 0.5 : 1.0;
        $grammes = (int) (round($gkg * $poids / 5) * 5);

        return RecommendationCopy::sportPre($titre, $heure, $grammes, $gkg, RecommendationCopy::EXEMPLE_GLUCIDES)[1];
    }

    /**
     * Conseil « après séance » : protéines dans les 2 h (brief §8, type `sport_post`).
     *
     * @param  Collection<int, WorkoutSession>  $done
     */
    private function recoPost(User $user, Collection $done, string $date): ?string
    {
        if ($done->isEmpty()) {
            return null;
        }

        return WorkoutSessionController::recoPost($this->calories->weightFor($user, $date));
    }
}
