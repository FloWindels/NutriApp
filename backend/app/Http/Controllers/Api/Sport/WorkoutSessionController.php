<?php

namespace App\Http\Controllers\Api\Sport;

use App\Enums\PlanStatus;
use App\Enums\SessionKind;
use App\Enums\SessionSource;
use App\Enums\SessionStatus;
use App\Http\Requests\Sport\CompleteSessionRequest;
use App\Http\Requests\Sport\GenerateSessionRequest;
use App\Http\Requests\Sport\IndexSessionsRequest;
use App\Http\Requests\Sport\StoreSessionRequest;
use App\Http\Requests\Sport\UpdateSessionRequest;
use App\Http\Resources\Sport\ProposalResource;
use App\Http\Resources\Sport\WorkoutSessionResource;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Services\Coach\RecommendationCopy;
use App\Services\Sport\CaloriesEstimator;
use App\Services\Sport\WorkoutAiGenerator;
use App\Services\Sport\WorkoutProposalSchema;
use App\Services\SportNutrition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Séances structurées (brief §13.2 + addendum §C.3/§C.4) : génération non persistée, enregistrement
 * d'une proposition telle quelle, déroulé (démarrer / terminer / annuler) et calories auto|manuel.
 */
class WorkoutSessionController extends SportController
{
    /** Bornes de la liste quand aucune fenêtre n'est demandée. */
    public const LIST_LIMIT = 200;

    public function __construct(
        private readonly CaloriesEstimator $calories,
        private readonly SportNutrition $nutrition,
        private readonly WorkoutAiGenerator $generator,
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------------------------

    /**
     * GET /sport/sessions?from=&to=&status= → {data:[SessionResource]}
     */
    public function index(IndexSessionsRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $query = $this->sessions($user)->with(['exercises', 'exercises.exercise']);

        if (! empty($validated['from'])) {
            $query->where('date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('date', '<=', $validated['to']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $sessions = $query->orderByDesc('date')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        return $this->json(['data' => WorkoutSessionResource::collection($sessions)->resolve($request)]);
    }

    /**
     * GET /sport/sessions/{session} → {data: SessionResource}
     */
    public function show(Request $request, int $session): JsonResponse
    {
        $user = $request->user();

        $model = $this->sessions($user)->with(['exercises', 'exercises.exercise'])->findOrFail($session);

        return $this->json(['data' => WorkoutSessionResource::make($model)->resolve($request)]);
    }

    // ------------------------------------------------------------------------------------
    // Génération et enregistrement
    // ------------------------------------------------------------------------------------

    /**
     * POST /sport/sessions/generate → {data: proposition non persistée} (déclarée avant /{session}).
     */
    public function generate(GenerateSessionRequest $request): JsonResponse
    {
        $user = $request->user();

        $proposal = $this->generator->generate($user, $request->validated());

        return $this->json(['data' => ProposalResource::payload($proposal)]);
    }

    /**
     * POST /sport/sessions → 201 {message, data} ; accepte une proposition telle quelle
     * (blocs + exercices) accompagnée de `date`, `sport_plan_id`, `planned_at`, `status`.
     */
    public function store(StoreSessionRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $plan = null;
        if (! empty($validated['sport_plan_id'])) {
            /** @var SportPlan $plan */
            $plan = $this->plans($user)->findOrFail((int) $validated['sport_plan_id']);
        }

        $sport = empty($validated['sport_id'])
            ? null
            : $this->visibleSports($user)->find((int) $validated['sport_id']);

        $manual = array_key_exists('calories_burned', $validated) && $validated['calories_burned'] !== null;

        $session = DB::transaction(function () use ($user, $validated, $plan, $sport, $manual) {
            $session = WorkoutSession::query()->create([
                'user_id' => $user->id,
                'date' => $validated['date'],
                'planned_at' => $validated['planned_at'] ?? $plan?->planned_at,
                'title' => trim((string) $validated['title']),
                'kind' => $validated['kind'] ?? SessionKind::Seance->value,
                'source' => $validated['source'] ?? SessionSource::Generee->value,
                'status' => $validated['status'] ?? SessionStatus::Prevue->value,
                'goal' => $validated['goal'] ?? null,
                'level' => $validated['level'] ?? null,
                'equipment' => $validated['equipment'] ?? null,
                'focus' => $validated['focus'] ?? null,
                'zones_a_eviter' => $validated['zones_a_eviter'] ?? null,
                'duration_min' => (int) $validated['duration_min'],
                'intensity' => $validated['intensity'] ?? null,
                'distance_km' => isset($validated['distance_km']) ? (float) $validated['distance_km'] : null,
                'rpe' => isset($validated['rpe']) ? (int) $validated['rpe'] : null,
                'notes' => $validated['notes'] ?? null,
                'sport_id' => $sport?->id,
                'sport_name' => $validated['sport_name'] ?? $sport?->name ?? $plan?->sport_name,
                'lieu' => $validated['lieu'] ?? $plan?->lieu,
                'generated_by' => $validated['generated_by'] ?? null,
                'llm_model' => $validated['llm_model'] ?? null,
                'sport_plan_id' => $plan?->id,
                'started_at' => $validated['started_at'] ?? null,
                'calories_source' => $manual ? 'manuel' : 'auto',
                'calories_burned' => $manual ? round((float) $validated['calories_burned'], 1) : null,
            ]);

            $this->syncExercises($session, $this->flattenExercises($validated));

            return $session;
        });

        $session->load(['exercises', 'exercises.exercise']);

        return $this->json([
            'message' => 'Séance enregistrée.',
            'data' => WorkoutSessionResource::make($session)->resolve($request),
        ], 201);
    }

    /**
     * PUT /sport/sessions/{session} → {message, data} ; `calories_burned` valué ⇒ « manuel »,
     * `calories_burned: null` ⇒ recalcul automatique (addendum §B).
     */
    public function update(UpdateSessionRequest $request, int $session): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        /** @var WorkoutSession $model */
        $model = $this->sessions($user)->with(['exercises', 'exercises.exercise'])->findOrFail($session);

        $changes = [];

        foreach ([
            'date', 'title', 'kind', 'source', 'status', 'goal', 'level', 'equipment', 'focus',
            'zones_a_eviter', 'planned_at', 'lieu', 'intensity', 'notes', 'generated_by', 'llm_model',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        if (array_key_exists('duration_min', $validated)) {
            $changes['duration_min'] = (int) $validated['duration_min'];
        }
        if (array_key_exists('distance_km', $validated)) {
            $changes['distance_km'] = $validated['distance_km'] === null ? null : (float) $validated['distance_km'];
        }
        if (array_key_exists('rpe', $validated)) {
            $changes['rpe'] = $validated['rpe'] === null ? null : (int) $validated['rpe'];
        }

        if (! empty($validated['sport_id'])) {
            $sport = $this->visibleSports($user)->find((int) $validated['sport_id']);
            $changes['sport_id'] = $sport?->id;
            $changes['sport_name'] = $validated['sport_name'] ?? $sport?->name ?? $model->sport_name;
        } elseif (array_key_exists('sport_name', $validated)) {
            $changes['sport_name'] = $validated['sport_name'];
        }

        if (! empty($validated['sport_plan_id'])) {
            $plan = $this->plans($user)->findOrFail((int) $validated['sport_plan_id']);
            $changes['sport_plan_id'] = $plan->id;
        }

        DB::transaction(function () use ($model, $validated, $changes, $user) {
            $model->fill($changes)->save();

            $exercises = $this->flattenExercises($validated);
            if ($exercises !== []) {
                $model->exercises()->delete();
                $this->syncExercises($model, $exercises);
                $model->load(['exercises', 'exercises.exercise']);
            }

            // Calories : valeur envoyée ⇒ manuel ; `null` explicite ⇒ estimation MET.
            if (array_key_exists('calories_burned', $validated)) {
                if ($validated['calories_burned'] === null) {
                    $model->calories_source = 'auto';
                    $model->calories_burned = $this->calories->forSession($model, $this->calories->weightFor($user, $model->date->format('Y-m-d')));
                } else {
                    $model->calories_source = 'manuel';
                    $model->calories_burned = round((float) $validated['calories_burned'], 1);
                }
                $model->save();
            }
        });

        return $this->json([
            'message' => 'Séance mise à jour.',
            'data' => WorkoutSessionResource::make($model)->resolve($request),
        ]);
    }

    // ------------------------------------------------------------------------------------
    // Déroulé
    // ------------------------------------------------------------------------------------

    /**
     * POST /sport/sessions/{session}/start → {message, data}
     */
    public function start(Request $request, int $session): JsonResponse
    {
        $user = $request->user();

        /** @var WorkoutSession $model */
        $model = $this->sessions($user)->with(['exercises', 'exercises.exercise'])->findOrFail($session);

        $model->fill([
            'status' => SessionStatus::EnCours->value,
            'started_at' => $model->started_at ?? now(),
        ])->save();

        return $this->json([
            'message' => 'C’est parti, bonne séance !',
            'data' => WorkoutSessionResource::make($model)->resolve($request),
        ]);
    }

    /**
     * POST /sport/sessions/{session}/complete → {message, data, nutrition, reco_post}
     */
    public function complete(CompleteSessionRequest $request, int $session): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        /** @var WorkoutSession $model */
        $model = $this->sessions($user)->with(['exercises', 'exercises.exercise'])->findOrFail($session);

        $date = $model->date->format('Y-m-d');
        $weight = $this->calories->weightFor($user, $date);
        $manual = array_key_exists('calories_burned', $validated) && $validated['calories_burned'] !== null;

        DB::transaction(function () use ($model, $validated, $manual, $weight) {
            foreach ($validated['exercises'] ?? [] as $row) {
                /** @var WorkoutExercise|null $exercise */
                $exercise = $model->getRelation('exercises')->firstWhere('id', (int) $row['id']);
                if ($exercise === null) {
                    continue;
                }

                $exercise->completed = (bool) ($row['completed'] ?? true);
                foreach (['sets', 'reps', 'duration_sec'] as $field) {
                    if (array_key_exists($field, $row) && $row[$field] !== null) {
                        $exercise->{$field} = (int) $row[$field];
                    }
                }
                if (array_key_exists('weight_kg', $row)) {
                    $exercise->weight_kg = $row['weight_kg'] === null ? null : (float) $row['weight_kg'];
                }
                $exercise->save();
            }

            $model->fill([
                'status' => SessionStatus::Terminee->value,
                'duration_min' => isset($validated['duration_min']) ? (int) $validated['duration_min'] : $model->duration_min,
                'rpe' => isset($validated['rpe']) ? (int) $validated['rpe'] : $model->rpe,
                'distance_km' => isset($validated['distance_km']) ? (float) $validated['distance_km'] : $model->distance_km,
                'notes' => $validated['notes'] ?? $model->notes,
                'started_at' => $model->started_at ?? now(),
                'completed_at' => now(),
            ]);

            $model->calories_source = $manual ? 'manuel' : 'auto';
            $model->calories_burned = $manual
                ? round((float) $validated['calories_burned'], 1)
                : $this->calories->forSession($model, $weight);

            $model->save();
        });

        $this->markPlanDone($user, $model);

        return $this->json([
            'message' => 'Séance terminée. Bravo !',
            'data' => WorkoutSessionResource::make($model)->resolve($request),
            'nutrition' => $this->nutrition->bonusForDay($user, $date),
            'reco_post' => self::recoPost($weight),
        ]);
    }

    /**
     * POST /sport/sessions/{session}/cancel → {message, data}
     */
    public function cancel(Request $request, int $session): JsonResponse
    {
        $user = $request->user();

        /** @var WorkoutSession $model */
        $model = $this->sessions($user)->with(['exercises', 'exercises.exercise'])->findOrFail($session);

        $model->fill(['status' => SessionStatus::Annulee->value])->save();

        return $this->json([
            'message' => 'Séance annulée.',
            'data' => WorkoutSessionResource::make($model)->resolve($request),
        ]);
    }

    /**
     * DELETE /sport/sessions/{session} → {message} ; le plan lié repasse à « prévu ».
     */
    public function destroy(Request $request, int $session): JsonResponse
    {
        $user = $request->user();

        /** @var WorkoutSession $model */
        $model = $this->sessions($user)->findOrFail($session);

        DB::transaction(function () use ($model, $user) {
            $this->plans($user)->where('session_id', $model->id)->update([
                'session_id' => null,
                'status' => PlanStatus::Prevu->value,
            ]);

            $model->exercises()->delete();
            $model->delete();
        });

        return $this->json(['message' => 'Séance supprimée.']);
    }

    // ------------------------------------------------------------------------------------

    /**
     * Conseil de récupération après une séance (≈ 0,3 g de protéines par kilo).
     */
    public static function recoPost(float $weightKg): string
    {
        $grammes = (int) (round(0.3 * $weightKg / 5) * 5);

        return RecommendationCopy::sportPost(max(10, $grammes), RecommendationCopy::EXEMPLE_PROTEINES)[1];
    }

    /**
     * Aplatit les exercices d'une proposition (`blocks[].exercises[]`) ou d'une liste plate
     * (`exercises[]`) en conservant le bloc et l'ordre d'affichage.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private function flattenExercises(array $validated): array
    {
        $out = [];

        foreach ($validated['blocks'] ?? [] as $block) {
            $key = in_array($block['key'] ?? null, WorkoutProposalSchema::BLOCK_KEYS, true) ? $block['key'] : 'principal';
            foreach ($block['exercises'] ?? [] as $exercise) {
                $out[] = ['block' => $key] + $exercise;
            }
        }

        foreach ($validated['exercises'] ?? [] as $exercise) {
            $key = in_array($exercise['block'] ?? null, WorkoutProposalSchema::BLOCK_KEYS, true) ? $exercise['block'] : 'principal';
            $out[] = ['block' => $key] + $exercise;
        }

        return $out;
    }

    /**
     * Crée les `workout_exercises` d'une séance : bloc, position, nom et MET sont des **instantanés**.
     *
     * @param  list<array<string, mixed>>  $exercises
     */
    private function syncExercises(WorkoutSession $session, array $exercises): void
    {
        $position = 0;

        foreach ($exercises as $exercise) {
            WorkoutExercise::query()->create([
                'session_id' => $session->id,
                'exercise_id' => $exercise['exercise_id'] ?? null,
                'block' => $exercise['block'],
                'position' => ++$position,
                'name' => mb_substr(trim((string) $exercise['name']), 0, 120),
                'sets' => isset($exercise['sets']) ? (int) $exercise['sets'] : null,
                'reps' => isset($exercise['reps']) ? (int) $exercise['reps'] : null,
                'duration_sec' => isset($exercise['duration_sec']) ? (int) $exercise['duration_sec'] : null,
                'weight_kg' => isset($exercise['weight_kg']) ? (float) $exercise['weight_kg'] : null,
                'rest_sec' => isset($exercise['rest_sec']) ? (int) $exercise['rest_sec'] : null,
                'met' => isset($exercise['met']) ? (float) $exercise['met'] : null,
                'notes' => $exercise['notes'] ?? null,
                'completed' => false,
            ]);
        }
    }
}
