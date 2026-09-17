<?php

namespace App\Http\Resources;

use App\Models\Food;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Charge utile d'un aliment (contrat legacy §0.3 + clés §3.2).
 *
 * `is_owner` et `is_favorite` dépendent du lecteur : utiliser `forViewer()` pour un aliment
 * et `collectionForViewer()` pour une liste (favoris préchargés en une requête, pas de N+1).
 *
 * @mixin Food
 */
class FoodResource extends JsonResource
{
    /**
     * @param  array<int, true>|null  $favoriteIds  ids favoris du lecteur (null = à résoudre)
     */
    public function __construct(
        $resource,
        private readonly ?int $viewerId = null,
        private readonly ?array $favoriteIds = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forViewer(Food $food, ?User $viewer): array
    {
        return (new self($food, $viewer?->id))->resolve();
    }

    /**
     * @param  iterable<Food>  $foods
     * @return list<array<string, mixed>>
     */
    public static function collectionForViewer(iterable $foods, ?User $viewer): array
    {
        $foods = collect($foods);
        $favoriteIds = $viewer === null ? [] : self::favoriteIdsFor($viewer, $foods->pluck('id')->all());

        return $foods
            ->map(fn (Food $food) => (new self($food, $viewer?->id, $favoriteIds))->resolve())
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $foodIds
     * @return array<int, true>
     */
    public static function favoriteIdsFor(User $viewer, array $foodIds): array
    {
        if ($foodIds === []) {
            return [];
        }

        return DB::table('food_favorites')
            ->where('user_id', $viewer->id)
            ->whereIn('food_id', $foodIds)
            ->pluck('food_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Food $food */
        $food = $this->resource;

        return [
            // --- contrat legacy (ordre et noms figés) ---
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
            'is_owner' => $this->viewerId !== null && $food->created_by_user_id === $this->viewerId,
            'created_at' => $food->created_at?->toISOString(),
            'updated_at' => $food->updated_at?->toISOString(),
            // --- clés additives §3.2 ---
            'fiber' => $food->fiber,
            'sugar' => $food->sugar,
            'salt' => $food->salt,
            'serving_size_g' => $food->serving_size_g,
            'serving_label' => $food->serving_label,
            'category' => $food->category,
            'allergens' => $food->allergens ?? [],
            'per_unit' => $food->per_unit ?? '100g',
            'is_verified' => (bool) $food->is_verified,
            'source_fetched_at' => $food->source_fetched_at?->toISOString(),
            'is_estimate' => $food->isFromOpenFoodFacts() && ! $food->is_verified,
            'is_favorite' => $this->isFavorite($food),
        ];
    }

    private function isFavorite(Food $food): bool
    {
        if ($this->viewerId === null) {
            return false;
        }

        if ($this->favoriteIds !== null) {
            return isset($this->favoriteIds[(int) $food->id]);
        }

        return DB::table('food_favorites')
            ->where('user_id', $this->viewerId)
            ->where('food_id', $food->id)
            ->exists();
    }
}
