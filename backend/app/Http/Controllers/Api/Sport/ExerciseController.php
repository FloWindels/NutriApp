<?php

namespace App\Http\Controllers\Api\Sport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sport\IndexExercisesRequest;
use App\Http\Resources\Sport\ExerciseResource;
use App\Models\Exercise;
use Illuminate\Http\JsonResponse;

/**
 * GET /sport/exercises (brief §13.2) — catalogue **public** d'exercices, sans authentification,
 * sous `throttle:30,1`. Pagination construite à la main : `{data, meta}` plafonné à 50 par page.
 */
class ExerciseController extends Controller
{
    public function index(IndexExercisesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = Exercise::query()->public();

        $term = isset($validated['q']) ? mb_strtolower(trim((string) $validated['q'])) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        if (! empty($validated['equipment'])) {
            $query->where('equipment', $validated['equipment']);
        }
        if (! empty($validated['muscle'])) {
            $query->where('muscle_group', $validated['muscle']);
        }
        if (! empty($validated['level'])) {
            $query->where('level', $validated['level']);
        }
        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $perPage = min(max((int) ($validated['per_page'] ?? IndexExercisesRequest::PER_PAGE_DEFAULT), 1), IndexExercisesRequest::PER_PAGE_MAX);
        $page = max((int) ($validated['page'] ?? 1), 1);

        $total = (clone $query)->count();

        $exercises = $query
            ->orderBy('category')
            ->orderBy('name')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'data' => ExerciseResource::collection($exercises)->resolve($request),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
