<?php

namespace App\Http\Controllers\Api\Sport;

use App\Http\Requests\Sport\EstimateCaloriesRequest;
use App\Http\Requests\Sport\StoreActivityRequest;
use App\Http\Resources\Sport\WorkoutSessionResource;
use App\Models\SportPlan;
use App\Services\Sport\CaloriesEstimator;
use App\Services\SportNutrition;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;

/**
 * Activités libres (addendum §C.3) : « j'ai fait tel sport pendant autant de temps », avec des
 * calories calculées automatiquement (formule MET) ou saisies à la main, et l'aperçu qui alimente
 * le champ « Calories » des clients avant la saisie.
 */
class SportActivityController extends SportController
{
    public function __construct(
        private readonly CaloriesEstimator $calories,
        private readonly SportNutrition $nutrition,
    ) {
    }

    /**
     * POST /sport/activities → 201 {message, data, nutrition}
     */
    public function store(StoreActivityRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $plan = null;
        if (! empty($validated['sport_plan_id'])) {
            /** @var SportPlan $plan */
            $plan = $this->plans($user)->with('sport')->findOrFail((int) $validated['sport_plan_id']);
        }

        $session = $this->recordActivity($user, $validated, $plan, $this->calories);

        return $this->json([
            'message' => 'Activité enregistrée. Bravo !',
            'data' => WorkoutSessionResource::make($session)->resolve($request),
            'nutrition' => $this->nutrition->bonusForDay($user, $session->date->format('Y-m-d')),
        ], 201);
    }

    /**
     * POST /sport/calories/estimate → {data:{calories, met, poids_kg, is_estimate:true}}
     */
    public function estimate(EstimateCaloriesRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $date = Clock::date($user, $validated['date'] ?? null);
        $weight = $this->calories->weightFor($user, $date);

        $sport = empty($validated['sport_id'])
            ? null
            : $this->visibleSports($user)->find((int) $validated['sport_id']);

        $met = isset($validated['met']) && $validated['met'] !== null
            ? round((float) $validated['met'], 1)
            : $this->calories->metForSport($sport, $validated['sport_name'] ?? null, $validated['intensity'] ?? null);

        return $this->json([
            'data' => [
                'calories' => $this->calories->net($met, $weight, (int) $validated['duration_min']),
                'met' => $met,
                'poids_kg' => $weight,
                'intensity' => $validated['intensity'] ?? 'moderee',
                'is_estimate' => true,
            ],
        ]);
    }
}
