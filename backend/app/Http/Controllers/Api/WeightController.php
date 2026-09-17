<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Weights\StoreWeightRequest;
use App\Http\Resources\WeightLogResource;
use App\Services\Profile\WeightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal de poids (brief §2.5).
 */
class WeightController extends Controller
{
    public function __construct(private readonly WeightService $weights)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [], ['from' => 'date de début', 'to' => 'date de fin']);

        $logs = $this->weights->list($request->user(), $validated['from'] ?? null, $validated['to'] ?? null);

        return response()->json([
            'data' => WeightLogResource::collection($logs)->resolve($request),
        ]);
    }

    public function store(StoreWeightRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->weights->upsert(
            $request->user(),
            $validated['date'] ?? null,
            (float) $validated['weight_kg'],
        );

        return response()->json([
            'message' => $result['created'] ? 'Poids enregistré.' : 'Poids mis à jour.',
            'data' => (new WeightLogResource($result['log']))->resolve($request),
            'profil_mis_a_jour' => $result['profil_mis_a_jour'],
            'cibles_recalculees' => $result['cibles_recalculees'],
        ], $result['created'] ? 201 : 200);
    }

    public function destroy(Request $request, string $date): JsonResponse
    {
        $this->weights->delete($request->user(), $date);

        return response()->json(['message' => 'Pesée supprimée.']);
    }
}
