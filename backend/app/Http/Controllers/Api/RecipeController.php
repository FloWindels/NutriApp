<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\EstimateRecipeRequest;
use App\Http\Requests\Recipes\IndexRecipesRequest;
use App\Http\Requests\Recipes\StoreRecipeRequest;
use App\Http\Requests\Recipes\UpdateRecipeRequest;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Foods\FoodCatalog;
use App\Services\Recipes\RecipeIngredients;
use App\Services\Recipes\RecipeNutritionEstimator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recettes (brief §5) : liste filtrée et paginée, détail, création/édition/suppression,
 * estimation nutritionnelle depuis les ingrédients.
 */
class RecipeController extends Controller
{
    /** Le contrat legacy renvoyait jusqu'à 100 recettes : la valeur par défaut et le plafond le conservent. */
    public const INDEX_DEFAULT_PER_PAGE = 100;

    public const INDEX_MAX_PER_PAGE = 100;

    public const FORBIDDEN_DELETE_MESSAGE = 'Seul le createur peut supprimer cette recette.';

    /**
     * GET /recipes?q=&mine=1&tag=&meal_type=&max_calories=&page=&per_page=
     * Réponse : {data:[RecipeResource], meta:{current_page,last_page,per_page,total}}.
     * `max_calories` s'applique aux calories par portion.
     */
    public function index(IndexRecipesRequest $request): JsonResponse
    {
        $viewer = $request->user('sanctum');
        $validated = $request->validated();

        $perPage = min(max((int) $request->integer('per_page', self::INDEX_DEFAULT_PER_PAGE), 1), self::INDEX_MAX_PER_PAGE);
        $page = max((int) $request->integer('page', 1), 1);

        $query = $this->visibleTo($viewer);

        if ($viewer !== null && $request->boolean('mine')) {
            $query->where('created_by_user_id', $viewer->id);
        }

        $term = FoodCatalog::term($validated['q'] ?? null);
        if ($term !== '') {
            $like = FoodCatalog::likePattern($term);
            $query->where(function (Builder $builder) use ($like) {
                $builder->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ? ESCAPE '\\'", [$like]);
            });
        }

        if (! empty($validated['tag'])) {
            $query->whereJsonContains('tags', $validated['tag']);
        }

        if (! empty($validated['meal_type'])) {
            $query->whereJsonContains('meal_types', $validated['meal_type']);
        }

        if (isset($validated['max_calories']) && $validated['max_calories'] !== '') {
            $query->whereRaw(
                // CAST : sur SQLite un paramètre lié est du texte et « nombre <= texte » serait toujours vrai.
                '(calories / CASE WHEN servings > 0 THEN servings ELSE 1 END) <= CAST(? AS REAL)',
                [(float) $validated['max_calories']]
            );
        }

        $total = (clone $query)->toBase()->getCountForPagination();

        $recipes = $query->orderByDesc('updated_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        return response()->json([
            'data' => RecipeResource::collectionForViewer($recipes, $viewer),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * GET /recipes/{recipe} — visible si publique ou à soi (sinon 404 « Introuvable. »).
     */
    public function show(Request $request, int $recipe): JsonResponse
    {
        $viewer = $request->user();

        $model = $this->visibleTo($viewer)->whereKey($recipe)->firstOrFail();

        return response()->json(['data' => RecipeResource::forViewer($model, $viewer)]);
    }

    /**
     * POST /recipes → 201 {message, data}.
     */
    public function store(StoreRecipeRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $recipe = Recipe::create([
            'created_by_user_id' => $user->id,
            ...$this->attributesFrom($validated, $request),
        ]);

        return response()->json([
            'message' => $recipe->is_public ? 'Recette publiée.' : 'Recette enregistrée en privé.',
            'data' => RecipeResource::forViewer($recipe, $user),
        ], 201);
    }

    /**
     * PUT /recipes/{recipe} → {message, data} (403 legacy si pas le créateur).
     */
    public function update(UpdateRecipeRequest $request, Recipe $recipe): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $recipe->update($this->attributesFrom($validated, $request));

        return response()->json([
            'message' => $recipe->is_public ? 'Recette mise à jour.' : 'Recette privée mise à jour.',
            'data' => RecipeResource::forViewer($recipe->refresh(), $user),
        ]);
    }

    /**
     * DELETE /recipes/{recipe} → {message} (403 legacy si pas le créateur).
     */
    public function destroy(Request $request, Recipe $recipe): JsonResponse
    {
        if ($recipe->created_by_user_id !== (int) $request->user()->id) {
            return response()->json(['message' => self::FORBIDDEN_DELETE_MESSAGE], 403);
        }

        $recipe->delete();

        return response()->json(['message' => 'Recette supprimée.']);
    }

    /**
     * POST /recipes/estimate {ingredients:[{name, ean, amount, unit}], servings?}
     * → {data:{calories, proteins, carbs, fat, resolved_count, total_count, is_estimate:true, details:[…]}}.
     */
    public function estimate(EstimateRecipeRequest $request, RecipeNutritionEstimator $estimator): JsonResponse
    {
        $validated = $request->validated();
        $servings = isset($validated['servings']) && $validated['servings'] !== '' ? (float) $validated['servings'] : null;

        return response()->json([
            'data' => $estimator->estimate($validated['ingredients'], $servings),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Recettes visibles : publiques + celles du lecteur.
     *
     * @return Builder<Recipe>
     */
    private function visibleTo(?User $viewer): Builder
    {
        $query = Recipe::query();

        if ($viewer === null) {
            return $query->where('is_public', true);
        }

        return $query->where(function (Builder $builder) use ($viewer) {
            $builder->where('is_public', true)->orWhere('created_by_user_id', $viewer->id);
        });
    }

    /**
     * Colonnes de `recipes` à partir d'une requête validée (création et édition).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated, Request $request): array
    {
        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'prep_time_minutes' => $validated['prep_time_minutes'] ?? null,
            'calories' => $validated['calories'],
            'image_url' => $validated['image_url'] ?? null,
            'ingredients' => RecipeIngredients::normalize($validated['ingredients'] ?? []),
            'is_public' => $request->boolean('is_public'),
        ];

        // Champs §5 : appliqués seulement s'ils sont présents (compatibilité avec les clients legacy).
        if (array_key_exists('servings', $validated)) {
            $attributes['servings'] = $validated['servings'] === null ? 1 : (float) $validated['servings'];
        }
        foreach (['proteins', 'carbs', 'fat'] as $macro) {
            if (array_key_exists($macro, $validated)) {
                $attributes[$macro] = $validated[$macro] === null ? null : (float) $validated[$macro];
            }
        }
        if (array_key_exists('tags', $validated)) {
            $attributes['tags'] = array_values(array_unique($validated['tags'] ?? []));
        }
        if (array_key_exists('meal_types', $validated)) {
            $attributes['meal_types'] = array_values(array_unique($validated['meal_types'] ?? []));
        }
        if (array_key_exists('is_estimate', $validated)) {
            $attributes['is_estimate'] = (bool) $validated['is_estimate'];
        }

        return $attributes;
    }
}
