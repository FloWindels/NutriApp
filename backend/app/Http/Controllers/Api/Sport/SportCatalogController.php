<?php

namespace App\Http\Controllers\Api\Sport;

use App\Enums\SportCategory;
use App\Http\Requests\Sport\IndexSportsRequest;
use App\Http\Requests\Sport\StoreSportRequest;
use App\Http\Requests\Sport\UpdateSportRequest;
use App\Http\Resources\Sport\SportResource;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue des sports (addendum §C.1) : sports publics + sports personnalisés de l'utilisateur.
 * Un sport personnalisé porte `is_public = false`, `slug = Str::slug(nom).'-u'.userId` et n'est
 * visible, modifiable et supprimable que par son créateur (sinon 404 « Introuvable. »).
 */
class SportCatalogController extends SportController
{
    public const MSG_USED = 'Ce sport est utilisé dans ton calendrier.';

    public const MSG_DUPLICATE = 'Tu as déjà créé un sport portant ce nom.';

    /** Rapports MET faible / élevé déduits du MET modéré (addendum §C.1). */
    public const RATIO_FAIBLE = 0.75;

    public const RATIO_ELEVEE = 1.3;

    /**
     * GET /sport/sports?q=&category= → {data:[SportResource]}
     */
    public function index(IndexSportsRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $query = $this->visibleSports($user);

        $term = isset($validated['q']) ? mb_strtolower(trim((string) $validated['q'])) : '';
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $sports = $query->orderBy('name')->get();

        return $this->json(['data' => SportResource::collection($sports)->resolve($request)]);
    }

    /**
     * POST /sport/sports {name, category, met_moderee?, icon?} → 201 {message, data}
     */
    public function store(StoreSportRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $name = trim((string) $validated['name']);
        $category = (string) $validated['category'];

        try {
            $sport = Sport::query()->create([
                'name' => $name,
                'slug' => $this->slugFor($name, $user),
                'category' => $category,
                'icon' => $validated['icon'] ?? null,
                'is_public' => false,
                'created_by_user_id' => $user->id,
            ] + $this->metTriplet($category, $validated['met_moderee'] ?? null));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [self::MSG_DUPLICATE]]);
        }

        return $this->json([
            'message' => 'Sport ajouté à ton catalogue.',
            'data' => SportResource::make($sport)->resolve($request),
        ], 201);
    }

    /**
     * PUT /sport/sports/{sport} → {message, data} ; 404 sur un sport public ou d'un autre utilisateur.
     */
    public function update(UpdateSportRequest $request, int $sport): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        /** @var Sport $model */
        $model = $this->ownSports($user)->findOrFail($sport);

        $changes = [];

        if (array_key_exists('name', $validated)) {
            $name = trim((string) $validated['name']);
            $changes['name'] = $name;
            $changes['slug'] = $this->slugFor($name, $user);
        }

        if (array_key_exists('icon', $validated)) {
            $changes['icon'] = $validated['icon'];
        }

        $category = $validated['category'] ?? $model->category;
        if (array_key_exists('category', $validated)) {
            $changes['category'] = $category;
        }

        // Le MET modéré (explicite ou défaut de la nouvelle catégorie) recalcule tout le triplet.
        if (array_key_exists('met_moderee', $validated) || array_key_exists('category', $validated)) {
            $changes += $this->metTriplet((string) $category, $validated['met_moderee'] ?? null);
        }

        try {
            $model->update($changes);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [self::MSG_DUPLICATE]]);
        }

        return $this->json([
            'message' => 'Sport mis à jour.',
            'data' => SportResource::make($model)->resolve($request),
        ]);
    }

    /**
     * DELETE /sport/sports/{sport} → {message} ; 422 si le sport est encore référencé.
     */
    public function destroy(\Illuminate\Http\Request $request, int $sport): JsonResponse
    {
        $user = $request->user();

        /** @var Sport $model */
        $model = $this->ownSports($user)->findOrFail($sport);

        $used = SportPlan::query()->where('sport_id', $model->id)->exists()
            || WorkoutSession::query()->where('sport_id', $model->id)->exists();

        if ($used) {
            throw ValidationException::withMessages(['sport' => [self::MSG_USED]]);
        }

        $model->delete();

        return $this->json(['message' => 'Sport supprimé.']);
    }

    // ------------------------------------------------------------------------------------

    /**
     * MET par intensité : `met_moderee` fourni, sinon défaut de la catégorie ;
     * faible = modéré × 0,75 et élevé = modéré × 1,3.
     *
     * @return array{met_faible: float, met_moderee: float, met_elevee: float}
     */
    private function metTriplet(string $category, mixed $moderee): array
    {
        $met = $moderee !== null && is_numeric($moderee)
            ? round((float) $moderee, 1)
            : (SportCategory::tryFrom($category) ?? SportCategory::Autre)->defaultMet();

        return [
            'met_faible' => round($met * self::RATIO_FAIBLE, 1),
            'met_moderee' => round($met, 1),
            'met_elevee' => round($met * self::RATIO_ELEVEE, 1),
        ];
    }

    private function slugFor(string $name, User $user): string
    {
        return mb_substr(Str::slug($name), 0, 80).'-u'.$user->id;
    }
}
