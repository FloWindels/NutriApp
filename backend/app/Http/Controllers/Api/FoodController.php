<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FoodController extends Controller
{
    private function toPayload(Food $food, ?int $viewerId = null): array
    {
        $isOwner = $viewerId !== null && (int) $food->created_by_user_id === $viewerId;

        return [
            'id' => $food->id,
            'barcode' => $food->barcode,
            'name' => $food->name,
            'brand' => $food->brand,
            'image_url' => $food->image_url,
            'calories' => $food->calories,
            'fat' => $food->fat,
            'carbs' => $food->carbs,
            'proteins' => $food->proteins,
            'source_type' => $food->source_type,
            'created_by_user_id' => $food->created_by_user_id,
            'is_owner' => $isOwner,
            'created_at' => $food->created_at?->toISOString(),
            'updated_at' => $food->updated_at?->toISOString(),
        ];
    }

    public function search(Request $request): JsonResponse
    {
        $viewerId = $request->user('sanctum')?->id;

        $validated = $request->validate([
            'barcode' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Food::query();

        if (!empty($validated['barcode'])) {
            $query->where('barcode', $validated['barcode']);
        }

        if (!empty($validated['q'])) {
            $search = trim($validated['q']);
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%' . $search . '%')
                    ->orWhere('brand', 'like', '%' . $search . '%')
                    ->orWhere('barcode', 'like', '%' . $search . '%');
            });
        }

        $foods = $query->orderByDesc('updated_at')->limit(20)->get();

        return response()->json([
            'data' => $foods->map(fn (Food $food) => $this->toPayload($food, $viewerId))->values(),
        ]);
    }

    public function showByBarcode(Request $request, string $barcode): JsonResponse
    {
        $viewerId = $request->user('sanctum')?->id;
        $food = Food::where('barcode', $barcode)->first();

        if (!$food) {
            return response()->json([
                'message' => 'Produit introuvable dans la base publique.',
            ], 404);
        }

        return response()->json([
            'data' => $this->toPayload($food, $viewerId),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:32', 'unique:food,barcode'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'calories' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'proteins' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'source_type' => ['nullable', Rule::in(['manual', 'open_food_facts'])],
        ]);

        $food = Food::create([
            'barcode' => $validated['barcode'],
            'name' => $validated['name'],
            'brand' => $validated['brand'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'calories' => $validated['calories'] ?? null,
            'fat' => $validated['fat'] ?? null,
            'carbs' => $validated['carbs'] ?? null,
            'proteins' => $validated['proteins'] ?? null,
            'source_type' => $validated['source_type'] ?? 'manual',
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Produit ajoute a la base publique.',
            'data' => $this->toPayload($food, $request->user()->id),
        ], 201);
    }

    public function update(Request $request, Food $food): JsonResponse
    {
        $userId = $request->user()->id;

        if ((int) $food->created_by_user_id !== $userId) {
            return response()->json([
                'message' => 'Seul le createur peut modifier cet aliment.',
            ], 403);
        }

        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:32', Rule::unique('food', 'barcode')->ignore($food->id)],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'calories' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'fat' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'carbs' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'proteins' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $food->update([
            'barcode' => $validated['barcode'],
            'name' => $validated['name'],
            'brand' => $validated['brand'] ?? null,
            'image_url' => $validated['image_url'] ?? null,
            'calories' => $validated['calories'] ?? null,
            'fat' => $validated['fat'] ?? null,
            'carbs' => $validated['carbs'] ?? null,
            'proteins' => $validated['proteins'] ?? null,
        ]);

        return response()->json([
            'message' => 'Produit mis a jour.',
            'data' => $this->toPayload($food->fresh(), $userId),
        ]);
    }
}
