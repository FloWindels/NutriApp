<?php

namespace App\Services;

use App\Models\DailyTarget;
use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use App\Support\StockScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Écriture des repas (brief §4.2) : création idempotente d'un repas (index unique
 * user/date/type + rattrapage de la collision), ajout d'éléments en transaction avec
 * décrément de stock optionnel, et figeage des cibles du jour (`daily_targets`).
 */
class MealService
{
    public function __construct(
        private readonly MealCalculator $calculator,
        private readonly StockService $stock,
        private readonly NutritionCalculator $nutrition,
    ) {
    }

    /**
     * Retourne le repas (user, date, type), créé si besoin. `$meal->wasRecentlyCreated`
     * indique au contrôleur s'il doit répondre 201 ou 200. `name` n'est posé qu'à la création.
     */
    public function findOrCreate(User $user, string $date, string $type, ?string $name = null): Meal
    {
        $existing = $this->query($user, $date, $type)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return Meal::query()->forceCreate([
                'user_id' => $user->id,
                'date' => $date,
                'type' => $type,
                'name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->query($user, $date, $type)->firstOrFail();
        }
    }

    /**
     * Ajoute des éléments à un repas dans une transaction.
     *
     * @param  array<int, array<string, mixed>>  $items  ItemInput[] : {food_id?|recipe_id?|custom?, quantity, unit, stock_item_id?, decrement_stock?}
     * @param  bool  $decrementStock  Valeur par défaut de `decrement_stock` quand l'élément ne la précise pas
     * @return array{0: array<int, MealItem>, 1: array<int, array<string, mixed>>}  [items créés, décréments de stock]
     */
    public function addItems(Meal $meal, array $items, bool $decrementStock = false): array
    {
        return DB::transaction(function () use ($meal, $items, $decrementStock) {
            $user = $meal->relationLoaded('user')
                ? $meal->getRelation('user')
                : User::query()->findOrFail($meal->user_id);

            $date = $meal->date instanceof \DateTimeInterface ? $meal->date->format('Y-m-d') : (string) $meal->date;

            $this->ensureDailyTargets($user, $date);

            $created = [];
            $decrements = [];

            foreach ($items as $input) {
                $food = null;
                $recipe = null;

                if (! empty($input['food_id'])) {
                    $food = Food::query()->findOrFail((int) $input['food_id']);
                } elseif (! empty($input['recipe_id'])) {
                    $recipe = Recipe::query()
                        ->where(function ($q) use ($user) {
                            $q->where('is_public', true)->orWhere('created_by_user_id', $user->id);
                        })
                        ->findOrFail((int) $input['recipe_id']);
                }

                $stockItem = null;
                if (! empty($input['stock_item_id'])) {
                    $stockItem = StockScope::items($user)->findOrFail((int) $input['stock_item_id']);
                }

                $attributes = $this->calculator->snapshot($input, $food, $recipe);
                $attributes['meal_id'] = $meal->id;
                $attributes['stock_item_id'] = $stockItem?->id;

                $item = MealItem::query()->forceCreate($attributes);
                $created[] = $item;

                $shouldDecrement = filter_var($input['decrement_stock'] ?? $decrementStock, FILTER_VALIDATE_BOOLEAN);
                if ($shouldDecrement && $stockItem !== null) {
                    $decrements[] = $this->stock->decrement(
                        $stockItem,
                        (float) ($input['quantity'] ?? 1),
                        (string) ($input['unit'] ?? $stockItem->unit)
                    );
                }
            }

            return [$created, $decrements];
        });
    }

    /**
     * Fige les cibles du jour au premier élément enregistré (idempotent, tolère la concurrence).
     */
    public function ensureDailyTargets(User $user, string $date): void
    {
        if (DailyTarget::query()->where('user_id', $user->id)->where('date', $date)->exists()) {
            return;
        }

        $profile = $user->relationLoaded('profile')
            ? $user->getRelation('profile')
            : Profile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return;
        }

        $cibles = $this->nutrition->ciblesEffectives($profile, $date);
        if ($cibles['calories'] === null) {
            return;
        }

        try {
            DailyTarget::query()->forceCreate([
                'user_id' => $user->id,
                'date' => $date,
                'calories' => (int) $cibles['calories'],
                'proteins' => (int) ($cibles['proteines'] ?? 0),
                'carbs' => (int) ($cibles['glucides'] ?? 0),
                'fat' => (int) ($cibles['lipides'] ?? 0),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Déjà figées par une requête concurrente : rien à faire.
        }
    }

    private function query(User $user, string $date, string $type)
    {
        return Meal::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->where('type', $type);
    }
}
