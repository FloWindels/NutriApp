<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RecipeController extends Controller
{
    private function toPayload(Recipe $recipe, ?int $viewerId = null): array
    {
        $isOwner = $viewerId !== null && (int) $recipe->created_by_user_id === $viewerId;
        $ingredients = collect($recipe->ingredients ?? [])->map(function ($ingredient) {
            if (!is_array($ingredient)) {
                return null;
            }

            $name = trim((string) ($ingredient['name'] ?? ''));

            if ($name === '') {
                return null;
            }

            $amount = $ingredient['amount'] ?? null;
            $ean = isset($ingredient['ean']) ? trim((string) $ingredient['ean']) : null;
            if ($ean === '') {
                $ean = null;
            }

            return [
                'name' => $name,
                'ean' => $ean,
                'amount' => $amount === null || $amount === '' ? null : (float) $amount,
                'unit' => isset($ingredient['unit']) && trim((string) $ingredient['unit']) !== '' ? trim((string) $ingredient['unit']) : null,
            ];
        })->filter()->values();

        return [
            'id' => $recipe->id,
            'title' => $recipe->title,
            'description' => $recipe->description,
            'prep_time_minutes' => $recipe->prep_time_minutes,
            'calories' => $recipe->calories,
            'image_url' => $recipe->image_url,
            'ingredients' => $ingredients,
            'ingredients_count' => $ingredients->count(),
            'is_public' => $recipe->is_public,
            'created_by_user_id' => $recipe->created_by_user_id,
            'is_owner' => $isOwner,
            'created_at' => $recipe->created_at?->toISOString(),
            'updated_at' => $recipe->updated_at?->toISOString(),
        ];
    }

    private function normalizeIngredients(mixed $ingredients): array
    {
        if (!is_array($ingredients)) {
            return [];
        }

        return collect($ingredients)
            ->filter(fn ($ingredient) => is_array($ingredient))
            ->map(function (array $ingredient) {
                $name = trim((string) ($ingredient['name'] ?? ''));

                if ($name === '') {
                    return null;
                }

                $amount = $ingredient['amount'] ?? null;
                $ean = isset($ingredient['ean']) ? trim((string) $ingredient['ean']) : null;
                if ($ean === '') {
                    $ean = null;
                }

                return [
                    'name' => $name,
                    'ean' => $ean,
                    'amount' => $amount === null || $amount === '' ? null : (float) $amount,
                    'unit' => isset($ingredient['unit']) && trim((string) $ingredient['unit']) !== '' ? trim((string) $ingredient['unit']) : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function validateRecipe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'calories' => ['required', 'numeric', 'min:0', 'max:10000'],
            'image_url' => ['nullable', 'string', 'max:500000'],
            'ingredients' => ['nullable', 'array'],
            'ingredients.*.name' => ['nullable', 'string', 'max:255'],
            'ingredients.*.ean' => ['nullable', 'string', 'max:32'],
            'ingredients.*.amount' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:64'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (!$request->boolean('is_public')) {
                return;
            }

            if (!$request->filled('image_url')) {
                $validator->errors()->add('image_url', "Une photo de l'assiette est requise pour publier une recette publique.");
            }

            $imageUrl = (string) $request->input('image_url', '');

            if ($imageUrl !== '' && !str_starts_with($imageUrl, 'data:image/') && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $validator->errors()->add('image_url', 'La photo doit etre une image importee ou une URL valide.');
            }

            if (!$request->filled('prep_time_minutes')) {
                $validator->errors()->add('prep_time_minutes', 'Le temps de preparation est requis pour publier une recette publique.');
            }

            $ingredients = $this->normalizeIngredients($request->input('ingredients', []));

            if (count($ingredients) === 0) {
                $validator->errors()->add('ingredients', 'Une recette publique doit contenir au moins un aliment.');
            }
        });

        return $validator->validate();
    }

    public function index(Request $request): JsonResponse
    {
        $viewerId = $request->user('sanctum')?->id;

        $query = Recipe::query()->orderByDesc('updated_at');

        if ($viewerId !== null) {
            $query->where(function ($builder) use ($viewerId) {
                $builder->where('is_public', true)
                    ->orWhere('created_by_user_id', $viewerId);
            });
        } else {
            $query->where('is_public', true);
        }

        $recipes = $query->limit(100)->get();

        return response()->json([
            'data' => $recipes->map(fn (Recipe $recipe) => $this->toPayload($recipe, $viewerId))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateRecipe($request);
        $ingredients = $this->normalizeIngredients($validated['ingredients'] ?? []);

        $recipe = Recipe::create([
            'created_by_user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'prep_time_minutes' => $validated['prep_time_minutes'] ?? null,
            'calories' => $validated['calories'],
            'image_url' => $validated['image_url'] ?? null,
            'ingredients' => $ingredients,
            'is_public' => $request->boolean('is_public'),
        ]);

        return response()->json([
            'message' => $recipe->is_public ? 'Recette publiee.' : 'Recette enregistree en prive.',
            'data' => $this->toPayload($recipe, $request->user()->id),
        ], 201);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $userId = $request->user()->id;

        if ((int) $recipe->created_by_user_id !== $userId) {
            return response()->json([
                'message' => 'Seul le createur peut modifier cette recette.',
            ], 403);
        }

        $validated = $this->validateRecipe($request);
        $ingredients = $this->normalizeIngredients($validated['ingredients'] ?? []);

        $recipe->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'prep_time_minutes' => $validated['prep_time_minutes'] ?? null,
            'calories' => $validated['calories'],
            'image_url' => $validated['image_url'] ?? null,
            'ingredients' => $ingredients,
            'is_public' => $request->boolean('is_public'),
        ]);

        return response()->json([
            'message' => $recipe->is_public ? 'Recette mise a jour.' : 'Recette privee mise a jour.',
            'data' => $this->toPayload($recipe->fresh(), $userId),
        ]);
    }

    public function destroy(Request $request, Recipe $recipe): JsonResponse
    {
        $userId = $request->user()->id;

        if ((int) $recipe->created_by_user_id !== $userId) {
            return response()->json([
                'message' => 'Seul le createur peut supprimer cette recette.',
            ], 403);
        }

        $recipe->delete();

        return response()->json([
            'message' => 'Recette supprimee.',
        ]);
    }
}