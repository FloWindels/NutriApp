<?php

namespace App\Support;

use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Portée du stock (brief §6.1) : si l'utilisateur appartient à un foyer, on voit les lieux
 * du foyer (user_id NULL, household_id H) ; sinon ses lieux personnels (user_id U, household_id NULL).
 * Toujours charger les articles via cette portée pour que les ids étrangers donnent 404.
 */
final class StockScope
{
    /**
     * Lieux de stock visibles par l'utilisateur.
     *
     * @return Builder<Stock>
     */
    public static function query(User $user): Builder
    {
        return $user->household_id
            ? Stock::query()->where('household_id', $user->household_id)
            : Stock::query()->where('user_id', $user->id)->whereNull('household_id');
    }

    /**
     * Articles de stock visibles par l'utilisateur.
     *
     * @return Builder<StockItem>
     */
    public static function items(User $user): Builder
    {
        return StockItem::query()->whereIn('stock_id', self::query($user)->select('id'));
    }

    /**
     * Attributs propriétaire à poser sur un nouveau lieu de stock.
     *
     * @return array{user_id: int|null, household_id: int|null}
     */
    public static function ownerAttributes(User $user): array
    {
        return $user->household_id
            ? ['user_id' => null, 'household_id' => (int) $user->household_id]
            : ['user_id' => (int) $user->id, 'household_id' => null];
    }

    /**
     * Identifiants des lieux visibles (utile pour des requêtes brutes).
     *
     * @return array<int, int>
     */
    public static function ids(User $user): array
    {
        return self::query($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
