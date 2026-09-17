<?php

namespace App\Http\Controllers\Api\Sport;

use App\Enums\PlanStatus;
use App\Enums\SessionStatus;
use App\Http\Requests\Sport\IndexCalendarRequest;
use App\Http\Requests\Sport\LogPlanRequest;
use App\Http\Requests\Sport\PlanWeekRequest;
use App\Http\Requests\Sport\ProposePlanSessionRequest;
use App\Http\Requests\Sport\StorePlanRequest;
use App\Http\Requests\Sport\StoreRecurringPlanRequest;
use App\Http\Requests\Sport\UpdatePlanRequest;
use App\Http\Resources\Sport\ProposalResource;
use App\Http\Resources\Sport\SportPlanResource;
use App\Http\Resources\Sport\WorkoutSessionResource;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Sport\CaloriesEstimator;
use App\Services\Sport\WeekPlanner;
use App\Services\Sport\WorkoutAiGenerator;
use App\Services\SportNutrition;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Calendrier sportif (addendum §C.2) : « tel jour je prévois tel sport pendant N minutes »,
 * séries hebdomadaires, planification de la semaine, enregistrement d'une séance réalisée
 * et proposition d'une séance (IA ou règles) à partir du plan.
 */
class SportCalendarController extends SportController
{
    public function __construct(
        private readonly CaloriesEstimator $calories,
        private readonly SportNutrition $nutrition,
        private readonly WorkoutAiGenerator $generator,
        private readonly WeekPlanner $weekPlanner,
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------------------------

    /**
     * GET /sport/calendar?from=&to= (62 jours maximum, mois en cours par défaut)
     * → {data:{days:[{date, plans, sessions}], summary:{…}}}
     */
    public function index(IndexCalendarRequest $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = $this->window($user, $request->validated());

        return $this->json(['data' => $this->calendarPayload($request, $user, $from, $to)]);
    }

    // ------------------------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------------------------

    /**
     * POST /sport/calendar → 201 {message, data}
     */
    public function store(StorePlanRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $sport = $this->sportFrom($user, $validated['sport_id'] ?? null);

        $plan = SportPlan::query()->create([
            'user_id' => $user->id,
            'date' => $validated['date'],
            'sport_id' => $sport?->id,
            'sport_name' => $this->sportName($sport, $validated['sport_name'] ?? null),
            'planned_duration_min' => (int) $validated['planned_duration_min'],
            'planned_at' => $validated['planned_at'] ?? null,
            'lieu' => $validated['lieu'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => PlanStatus::Prevu->value,
        ]);

        $plan->setRelation('sport', $sport);

        return $this->json([
            'message' => 'Séance planifiée.',
            'data' => SportPlanResource::make($plan)->resolve($request),
        ], 201);
    }

    /**
     * POST /sport/calendar/recurring {weekday (1=lundi…7), weeks (1–12), …}
     * → 201 {message, data:[…], recurrence_id}
     */
    public function storeRecurring(StoreRecurringPlanRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $sport = $this->sportFrom($user, $validated['sport_id'] ?? null);
        $sportName = $this->sportName($sport, $validated['sport_name'] ?? null);

        $weekday = (int) $validated['weekday'];
        $weeks = (int) $validated['weeks'];
        $start = CarbonImmutable::parse(Clock::date($user, $validated['start_date'] ?? null));

        // Première occurrence : le jour demandé à partir de la date de départ (incluse).
        $first = $start->addDays((($weekday - $start->dayOfWeekIso) + 7) % 7);
        $recurrenceId = (string) Str::uuid();

        $plans = DB::transaction(function () use ($user, $validated, $sport, $sportName, $first, $weeks, $recurrenceId) {
            $created = collect();

            for ($i = 0; $i < $weeks; $i++) {
                $plan = SportPlan::query()->create([
                    'user_id' => $user->id,
                    'date' => $first->addWeeks($i)->toDateString(),
                    'sport_id' => $sport?->id,
                    'sport_name' => $sportName,
                    'planned_duration_min' => (int) $validated['planned_duration_min'],
                    'planned_at' => $validated['planned_at'] ?? null,
                    'lieu' => $validated['lieu'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'status' => PlanStatus::Prevu->value,
                    'recurrence_id' => $recurrenceId,
                ]);
                $plan->setRelation('sport', $sport);
                $created->push($plan);
            }

            return $created;
        });

        return $this->json([
            'message' => $weeks > 1
                ? sprintf('%d séances planifiées, une par semaine.', $weeks)
                : 'Séance planifiée.',
            'data' => SportPlanResource::collection($plans)->resolve($request),
            'recurrence_id' => $recurrenceId,
        ], 201);
    }

    /**
     * POST /sport/calendar/plan-week → {message, data (calendrier de la semaine), generated_by, …}
     */
    public function planWeek(PlanWeekRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $weekStart = isset($validated['week_start'])
            ? CarbonImmutable::parse($validated['week_start'])->toDateString()
            : Clock::weekStart($user);

        $result = $this->weekPlanner->plan(
            $user,
            $weekStart,
            isset($validated['days']) ? array_map('intval', $validated['days']) : null,
            $validated['mode'] ?? 'ia',
            (bool) ($validated['replace'] ?? false),
        );

        $to = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();

        return $this->json([
            'message' => sprintf('%d séances planifiées cette semaine.', $result['plans']->count()),
            'data' => $this->calendarPayload($request, $user, $weekStart, $to),
            'generated_by' => $result['generated_by'],
            'explication' => array_values($result['explication']),
            'warnings' => array_values($result['warnings']),
            'days' => array_values($result['days']),
        ]);
    }

    /**
     * PUT /sport/calendar/{plan} → {message, data}
     */
    public function update(UpdatePlanRequest $request, int $plan): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        /** @var SportPlan $model */
        $model = $this->plans($user)->with('sport')->findOrFail($plan);

        $changes = [];

        if (array_key_exists('sport_id', $validated)) {
            $sport = $this->sportFrom($user, $validated['sport_id']);
            $changes['sport_id'] = $sport?->id;
            $changes['sport_name'] = $this->sportName($sport, $validated['sport_name'] ?? $model->sport_name);
            $model->setRelation('sport', $sport);
        } elseif (array_key_exists('sport_name', $validated)) {
            $changes['sport_name'] = trim((string) $validated['sport_name']);
        }

        foreach (['date', 'planned_duration_min', 'planned_at', 'lieu', 'notes', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        $model->update($changes);

        return $this->json([
            'message' => 'Séance planifiée mise à jour.',
            'data' => SportPlanResource::make($model)->resolve($request),
        ]);
    }

    /**
     * DELETE /sport/calendar/{plan}?serie=1 → {message, data:{deleted}} ;
     * `serie=1` supprime aussi les occurrences suivantes de la même série.
     */
    public function destroy(Request $request, int $plan): JsonResponse
    {
        $user = $request->user();

        /** @var SportPlan $model */
        $model = $this->plans($user)->findOrFail($plan);

        $serie = filter_var($request->query('serie', false), FILTER_VALIDATE_BOOLEAN);

        if ($serie && $model->recurrence_id !== null) {
            $deleted = $this->plans($user)
                ->where('recurrence_id', $model->recurrence_id)
                ->where('date', '>=', $model->date->format('Y-m-d'))
                ->delete();

            return $this->json([
                'message' => 'Série supprimée du calendrier.',
                'data' => ['deleted' => (int) $deleted],
            ]);
        }

        $model->delete();

        return $this->json([
            'message' => 'Séance retirée du calendrier.',
            'data' => ['deleted' => 1],
        ]);
    }

    /**
     * POST /sport/calendar/{plan}/log → 201 {message, data:{plan, session}, nutrition}
     */
    public function log(LogPlanRequest $request, int $plan): JsonResponse
    {
        $user = $request->user();

        /** @var SportPlan $model */
        $model = $this->plans($user)->with('sport')->findOrFail($plan);

        $session = $this->recordActivity($user, $request->validated(), $model, $this->calories);

        return $this->json([
            'message' => 'Séance enregistrée. Bravo !',
            'data' => [
                // `recordActivity` a déjà mis à jour l'instance (status realise + session_id).
                'plan' => SportPlanResource::make($model)->resolve($request),
                'session' => WorkoutSessionResource::make($session)->resolve($request),
            ],
            'nutrition' => $this->nutrition->bonusForDay($user, $session->date->format('Y-m-d')),
        ], 201);
    }

    /**
     * POST /sport/calendar/{plan}/propose → {data: proposition (non persistée)}
     */
    public function propose(ProposePlanSessionRequest $request, int $plan): JsonResponse
    {
        $user = $request->user();

        /** @var SportPlan $model */
        $model = $this->plans($user)->with('sport')->findOrFail($plan);

        $proposal = $this->generator->generate($user, $request->validated(), $model);

        return $this->json(['data' => ProposalResource::payload($proposal)]);
    }

    // ------------------------------------------------------------------------------------

    /**
     * Fenêtre demandée, mois en cours par défaut (fuseau de l'utilisateur).
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string}
     */
    private function window(User $user, array $validated): array
    {
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        if ($from === null && $to === null) {
            $month = CarbonImmutable::parse(Clock::today($user));

            return [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()];
        }

        $from ??= CarbonImmutable::parse($to)->subDays(IndexCalendarRequest::MAX_DAYS - 1)->toDateString();
        $to ??= CarbonImmutable::parse($from)->addDays(IndexCalendarRequest::MAX_DAYS - 1)->toDateString();

        return [$from, $to];
    }

    /**
     * Jours (plans + séances) et récapitulatif de la fenêtre.
     *
     * @return array<string, mixed>
     */
    private function calendarPayload(Request $request, User $user, string $from, string $to): array
    {
        /** @var Collection<int, SportPlan> $plans */
        $plans = $this->plans($user)
            ->with('sport')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderByRaw('planned_at IS NULL')
            ->orderBy('planned_at')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, WorkoutSession> $sessions */
        $sessions = $this->sessions($user)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $plansByDate = $plans->groupBy(fn (SportPlan $p) => $p->date->format('Y-m-d'));
        $sessionsByDate = $sessions->groupBy(fn (WorkoutSession $s) => $s->date->format('Y-m-d'));

        $days = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $days[] = [
                'date' => $date,
                'plans' => SportPlanResource::collection($plansByDate->get($date, collect()))->resolve($request),
                'sessions' => $sessionsByDate->get($date, collect())
                    ->map(fn (WorkoutSession $s) => WorkoutSessionResource::lite($s))
                    ->values()
                    ->all(),
            ];
            $cursor = $cursor->addDay();
        }

        $done = $sessions->where('status', SessionStatus::Terminee->value);

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'summary' => [
                'planned_count' => $plans->where('status', PlanStatus::Prevu->value)->count(),
                'done_count' => $done->count(),
                'minutes_done' => (int) $done->sum(fn (WorkoutSession $s) => (int) $s->duration_min),
                'calories_done' => round((float) $done->sum(fn (WorkoutSession $s) => (float) ($s->calories_burned ?? 0)), 1),
            ],
        ];
    }

    private function sportFrom(User $user, mixed $sportId): ?Sport
    {
        return $sportId === null || $sportId === '' ? null : $this->visibleSports($user)->find((int) $sportId);
    }

    private function sportName(?Sport $sport, mixed $fallback): string
    {
        if ($sport !== null) {
            return (string) $sport->name;
        }

        $name = is_string($fallback) ? trim($fallback) : '';

        return $name !== '' ? $name : 'Séance libre';
    }
}
