<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Diets\EvaluateDietRequest;
use App\Http\Resources\DietResource;
use App\Services\Diets\DietEvaluator;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DietController extends Controller
{
    public function __construct(private readonly DietEvaluator $evaluator)
    {
    }

    /**
     * GET /diets — catalogue public (résumés).
     */
    public function index(): JsonResponse
    {
        $data = [];
        foreach ((array) config('diets', []) as $key => $diet) {
            $data[] = DietResource::summary((string) $key, (array) $diet);
        }

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * GET /diets/{key} — fiche complète.
     */
    public function show(string $key): JsonResponse
    {
        $diet = config("diets.$key");
        if (! is_array($diet)) {
            abort(404);
        }

        return response()->json([
            'data' => (new DietResource(['key' => $key] + $diet))->resolve(),
        ])->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * GET /diets/evaluate?days=7&regime= — évaluation indicative sur les derniers jours.
     */
    public function evaluate(EvaluateDietRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['profile', 'settings']);

        $regime = $request->validated('regime') ?: ($user->getRelation('profile')?->regime_alimentaire ?: 'omnivore');
        if (! in_array($regime, DietEvaluator::regimes(), true)) {
            $regime = 'omnivore';
        }

        $days = $request->days();
        $to = Clock::today($user);
        $from = CarbonImmutable::parse($to)->subDays($days - 1)->toDateString();

        return response()->json([
            'data' => $this->evaluator->evaluate($user, $regime, $from, $to),
        ]);
    }
}
