<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Foods\SearchFoodsRequest;
use App\Http\Requests\Foods\StoreFoodRequest;
use App\Http\Requests\Foods\UpdateFoodRequest;
use App\Http\Resources\FoodResource;
use App\Models\Food;
use App\Services\Foods\FoodCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aliments : recherche locale + Open Food Facts, code-barres, création/édition, favoris (brief §3.2).
 */
class FoodController extends Controller
{
    public const SEARCH_DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function __construct(private readonly FoodCatalog $catalog)
    {
    }

    /**
     * GET /foods/search?q=&barcode=&page=&per_page=&off=1 (public, throttle 30/min).
     * Réponse : {data:[FoodResource], meta:{current_page,last_page,per_page,total,off_queried}}.
     */
    public function search(SearchFoodsRequest $request): JsonResponse
    {
        $viewer = $request->user('sanctum');
        $validated = $request->validated();

        $term = FoodCatalog::term($validated['q'] ?? null);
        $barcode = $validated['barcode'] ?? null;

        $perPage = min(max((int) $request->integer('per_page', self::SEARCH_DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $page = max((int) $request->integer('page', 1), 1);

        $query = $this->catalog->searchQuery($term, $barcode);
        $total = (clone $query)->toBase()->getCountForPagination();

        $offQueried = false;
        if (FoodCatalog::shouldQueryOff($request->boolean('off'), $viewer !== null, $total, $term)) {
            $offQueried = $this->catalog->importSearchResults($term);

            if ($offQueried) {
                $query = $this->catalog->searchQuery($term, $barcode);
                $total = (clone $query)->toBase()->getCountForPagination();
            }
        }

        $foods = $query->forPage($page, $perPage)->get();

        return response()->json([
            'data' => FoodResource::collectionForViewer($foods, $viewer),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'off_queried' => $offQueried,
            ],
        ]);
    }

    /**
     * GET /foods/barcode/{barcode} (public, throttle 30/min).
     * Local (rafraîchi si fiche OFF > 90 jours) sinon import Open Food Facts.
     */
    public function showByBarcode(Request $request, string $barcode): JsonResponse
    {
        $viewer = $request->user('sanctum');
        $code = trim($barcode);

        $food = $this->catalog->findLocalByBarcode($code);

        if ($food !== null) {
            $food = $this->catalog->refreshFromOff($food);

            return response()->json(['data' => FoodResource::forViewer($food, $viewer)]);
        }

        // Une valeur non numérique ne peut pas exister chez OFF : inutile de l'interroger.
        $product = ctype_digit($code) ? $this->catalog->fetchFromOff($code) : null;

        if ($product === null) {
            return response()->json(['message' => 'Produit introuvable, même sur Open Food Facts.'], 404);
        }

        $food = $this->catalog->createFromOff($product, $code);

        return response()->json(['data' => FoodResource::forViewer($food, $viewer)]);
    }

    /**
     * GET /foods/{food} (auth).
     */
    public function show(Request $request, Food $food): JsonResponse
    {
        return response()->json(['data' => FoodResource::forViewer($food, $request->user())]);
    }

    /**
     * POST /foods (auth) — 201 {message, data} ; code-barres déjà connu → 200 avec l'existant.
     */
    public function store(StoreFoodRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $barcode = isset($validated['barcode']) && trim((string) $validated['barcode']) !== ''
            ? trim((string) $validated['barcode'])
            : null;

        if ($barcode !== null) {
            $existing = Food::query()->where('barcode', $barcode)->first();
            if ($existing !== null) {
                return $this->alreadyPresent($existing, $request);
            }
        }

        $sourceType = $validated['source_type'] ?? 'manual';
        $isOff = $sourceType === 'open_food_facts';

        $attributes = [
            'barcode' => $barcode,
            'name' => $validated['name'],
            'brand' => $validated['brand'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'calories' => $validated['calories'] ?? null,
            'fat' => $validated['fat'] ?? null,
            'carbs' => $validated['carbs'] ?? null,
            'proteins' => $validated['proteins'] ?? null,
            'fiber' => $validated['fiber'] ?? null,
            'sugar' => $validated['sugar'] ?? null,
            'salt' => $validated['salt'] ?? null,
            'serving_size_g' => $validated['serving_size_g'] ?? null,
            'serving_label' => $validated['serving_label'] ?? null,
            'category' => $validated['category'] ?? null,
            'per_unit' => $validated['per_unit'] ?? '100g',
            'density_g_per_ml' => $validated['density_g_per_ml'] ?? null,
            'source_type' => $sourceType,
            'source_fetched_at' => $isOff ? now() : null,
            'off_last_checked_at' => $isOff ? now() : null,
            'is_verified' => false,
            'created_by_user_id' => $user->id,
        ];

        try {
            $food = Food::create($attributes);
        } catch (UniqueConstraintViolationException) {
            // Course sur le code-barres : quelqu'un vient de l'ajouter.
            $existing = Food::query()->where('barcode', $barcode)->firstOrFail();

            return $this->alreadyPresent($existing, $request);
        }

        return response()->json([
            'message' => 'Produit ajouté à la base publique.',
            'data' => FoodResource::forViewer($food, $user),
        ], 201);
    }

    /**
     * PUT /foods/{food} (auth) — créateur, ou fiche OFF jamais reprise. L'édition rend
     * l'aliment manuel, vérifié et attribué à l'éditeur.
     */
    public function update(UpdateFoodRequest $request, Food $food): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $barcode = isset($validated['barcode']) && trim((string) $validated['barcode']) !== ''
            ? trim((string) $validated['barcode'])
            : null;

        $food->fill([
            'barcode' => $barcode,
            'name' => $validated['name'],
            'brand' => $validated['brand'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'calories' => $validated['calories'] ?? null,
            'fat' => $validated['fat'] ?? null,
            'carbs' => $validated['carbs'] ?? null,
            'proteins' => $validated['proteins'] ?? null,
            'fiber' => $validated['fiber'] ?? $food->fiber,
            'sugar' => $validated['sugar'] ?? $food->sugar,
            'salt' => $validated['salt'] ?? $food->salt,
            'serving_size_g' => $validated['serving_size_g'] ?? $food->serving_size_g,
            'serving_label' => $validated['serving_label'] ?? $food->serving_label,
            'category' => $validated['category'] ?? $food->category,
            'per_unit' => $validated['per_unit'] ?? $food->per_unit ?? '100g',
            'density_g_per_ml' => $validated['density_g_per_ml'] ?? $food->density_g_per_ml,
            'source_type' => 'manual',
            'is_verified' => true,
            'created_by_user_id' => $user->id,
        ])->save();

        return response()->json([
            'message' => 'Produit mis à jour.',
            'data' => FoodResource::forViewer($food->refresh(), $user),
        ]);
    }

    /**
     * GET /foods/favorites (auth) — favoris du lecteur, les plus récents d'abord.
     */
    public function favorites(Request $request): JsonResponse
    {
        $user = $request->user();

        $foods = $user->favoriteFoods()
            ->orderByDesc('food_favorites.created_at')
            ->orderByDesc('food.id')
            ->get();

        return response()->json([
            'data' => FoodResource::collectionForViewer($foods, $user),
        ]);
    }

    /**
     * POST /foods/{food}/favorite (auth) — 201 si ajouté, 200 s'il l'était déjà.
     */
    public function favorite(Request $request, Food $food): JsonResponse
    {
        $user = $request->user();

        $result = $user->favoriteFoods()->syncWithoutDetaching([$food->id]);
        $created = in_array($food->id, $result['attached'] ?? [], false);

        return response()->json([
            'message' => $created ? 'Ajouté aux favoris.' : 'Déjà dans tes favoris.',
            'data' => FoodResource::forViewer($food, $user),
        ], $created ? 201 : 200);
    }

    /**
     * DELETE /foods/{food}/favorite (auth).
     */
    public function unfavorite(Request $request, Food $food): JsonResponse
    {
        $request->user()->favoriteFoods()->detach($food->id);

        return response()->json(['message' => 'Retiré des favoris.']);
    }

    private function alreadyPresent(Food $existing, Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Produit déjà présent dans la base.',
            'data' => FoodResource::forViewer($existing, $request->user()),
        ], 200);
    }
}
