<?php

namespace App\Http\Controllers\Api\Sport;

use App\Enums\PlanStatus;
use App\Enums\SessionKind;
use App\Enums\SessionSource;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Sport\CaloriesEstimator;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Base des contrôleurs du module M8 (Sport) : enveloppe JSON commune et chargement
 * des ressources **à travers l'utilisateur** (un identifiant étranger donne 404 « Introuvable. »).
 */
abstract class SportController extends Controller
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Entrées du calendrier de l'utilisateur.
     *
     * @return Builder<SportPlan>
     */
    protected function plans(User $user): Builder
    {
        return SportPlan::query()->where('user_id', $user->id);
    }

    /**
     * Séances de l'utilisateur.
     *
     * @return Builder<WorkoutSession>
     */
    protected function sessions(User $user): Builder
    {
        return WorkoutSession::query()->where('user_id', $user->id);
    }

    /**
     * Sports visibles : catalogue public + sports personnalisés de l'utilisateur.
     *
     * @return Builder<Sport>
     */
    protected function visibleSports(User $user): Builder
    {
        return Sport::query()->visibleTo($user);
    }

    /**
     * Sports personnalisés de l'utilisateur uniquement (404 sur un sport public ou d'autrui).
     *
     * @return Builder<Sport>
     */
    protected function ownSports(User $user): Builder
    {
        return Sport::query()->where('is_public', false)->where('created_by_user_id', $user->id);
    }

    /**
     * Enregistre une **activité libre déjà réalisée** (addendum §C.3) : séance `kind=activite`,
     * `source=activite`, `status=terminee`. Les calories sont saisies (`calories_source=manuel`)
     * ou estimées par la formule MET (`auto`). Quand un plan est fourni, il passe à « réalisé ».
     *
     * @param  array<string, mixed>  $data  Corps validé
     */
    protected function recordActivity(
        User $user,
        array $data,
        ?SportPlan $plan,
        CaloriesEstimator $estimator,
    ): WorkoutSession {
        $date = $plan?->date?->format('Y-m-d') ?? Clock::date($user, $data['date'] ?? null);

        $sport = null;
        $sportId = $data['sport_id'] ?? $plan?->sport_id;
        if ($sportId !== null) {
            $sport = $this->visibleSports($user)->find((int) $sportId);
        }

        $sportName = $sport?->name ?? ($data['sport_name'] ?? $plan?->sport_name);
        $sportName = is_string($sportName) && trim($sportName) !== '' ? trim($sportName) : 'Activité libre';

        $manual = array_key_exists('calories_burned', $data) && $data['calories_burned'] !== null;

        return DB::transaction(function () use ($user, $data, $plan, $estimator, $date, $sport, $sportName, $manual) {
            $session = WorkoutSession::query()->create([
                'user_id' => $user->id,
                'date' => $date,
                'planned_at' => $plan?->planned_at,
                'title' => $sportName,
                'kind' => SessionKind::Activite->value,
                'source' => SessionSource::Activite->value,
                'status' => SessionStatus::Terminee->value,
                'duration_min' => (int) $data['duration_min'],
                'intensity' => $data['intensity'] ?? 'moderee',
                'distance_km' => isset($data['distance_km']) ? (float) $data['distance_km'] : null,
                'rpe' => isset($data['rpe']) ? (int) $data['rpe'] : null,
                'notes' => $data['notes'] ?? null,
                'sport_id' => $sport?->id,
                'sport_name' => $sportName,
                'lieu' => $data['lieu'] ?? $plan?->lieu,
                'sport_plan_id' => $plan?->id,
                'completed_at' => now(),
                'calories_source' => $manual ? 'manuel' : 'auto',
                'calories_burned' => $manual ? round((float) $data['calories_burned'], 1) : null,
            ]);

            // Une activité libre n'a pas d'exercice : on pose la relation pour éviter tout lazy loading.
            $session->setRelation('exercises', new EloquentCollection());
            $session->setRelation('sport', $sport);

            if (! $manual) {
                $session->calories_burned = $estimator->forSession($session, $estimator->weightFor($user, $date));
                $session->save();
            }

            if ($plan !== null) {
                $plan->forceFill([
                    'status' => PlanStatus::Realise->value,
                    'session_id' => $session->id,
                ])->save();
            }

            return $session;
        });
    }

    /**
     * Marque « réalisé » le plan lié à une séance terminée (addendum §C.2/§C.3).
     */
    protected function markPlanDone(User $user, WorkoutSession $session): ?SportPlan
    {
        if ($session->sport_plan_id === null) {
            return null;
        }

        /** @var SportPlan|null $plan */
        $plan = $this->plans($user)->find($session->sport_plan_id);

        $plan?->forceFill([
            'status' => PlanStatus::Realise->value,
            'session_id' => $session->id,
        ])->save();

        return $plan;
    }
}
