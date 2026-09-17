<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recommendations\IndexRecommendationRequest;
use App\Http\Requests\Recommendations\UpdateRecommendationRequest;
use App\Http\Resources\RecommendationResource;
use App\Services\Coach\CoachEngine;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    public function __construct(private readonly CoachEngine $coach)
    {
    }

    /**
     * GET /recommendations?date=&all=1 — génère (au plus une fois par minute) puis liste par priorité.
     */
    public function index(IndexRecommendationRequest $request): JsonResponse
    {
        $user = $request->user();
        $date = Clock::date($user, $request->validated('date'));
        $all = $request->boolean('all');

        $this->coach->generateIfDue($user, $date);

        $rows = $this->coach->listForDay($user, $date, $all);

        return response()->json([
            'data' => RecommendationResource::collection($rows)->resolve(),
        ]);
    }

    /**
     * PUT /recommendations/{recommendation} {status}
     */
    public function update(UpdateRecommendationRequest $request, int $recommendation): JsonResponse
    {
        $row = $request->user()->recommendations()->findOrFail($recommendation);
        $row->update(['status' => $request->validated('status')]);

        return response()->json([
            'message' => match ($row->status) {
                'acceptee' => 'Recommandation acceptée.',
                'ignoree' => 'Recommandation ignorée.',
                default => 'Recommandation mise à jour.',
            },
            'data' => (new RecommendationResource($row->refresh()))->resolve(),
        ]);
    }
}
