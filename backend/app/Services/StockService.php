<?php

namespace App\Services;

use App\Models\Food;
use App\Models\ShoppingItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Support\Portions;
use Illuminate\Support\Facades\DB;

/**
 * Décrément de stock (brief §6.2) : verrou pessimiste, conversion d'unités via Portions,
 * jamais de suppression ; un article épuisé est poussé dans la liste de courses (source auto_stock).
 */
class StockService
{
    /**
     * @return array{stock_item_id: int, previous_quantity: float, new_quantity: float, unit: string, depleted: bool}
     */
    public function decrement(StockItem $item, float $qty, string $unit): array
    {
        return DB::transaction(function () use ($item, $qty, $unit) {
            /** @var StockItem $locked */
            $locked = StockItem::query()->whereKey($item->getKey())->lockForUpdate()->first() ?? $item;

            $food = $this->foodFor($locked, $item);
            $delta = $this->deltaInStockUnit($locked, max(0.0, $qty), $unit, $food);

            $previous = (float) $locked->quantity;
            $new = round(max(0.0, $previous - $delta), 2);
            $depleted = $new <= 0;

            $locked->forceFill([
                'quantity' => $new,
                'depleted_at' => $depleted ? now() : null,
            ])->save();

            if ($depleted) {
                $this->queueShoppingItem($locked, $food);
            }

            // Synchronise l'instance reçue (souvent réutilisée par l'appelant pour la réponse).
            $item->forceFill(['quantity' => $new, 'depleted_at' => $locked->depleted_at])->syncOriginal();

            return [
                'stock_item_id' => (int) $locked->getKey(),
                'previous_quantity' => $previous,
                'new_quantity' => $new,
                'unit' => (string) $locked->unit,
                'depleted' => $depleted,
            ];
        });
    }

    /**
     * Quantité à retirer exprimée dans l'unité du stock :
     * même unité canonique → direct (avec facteurs cl/kg…) ; sinon via les grammes quand les
     * deux unités sont convertibles ; sinon la quantité telle quelle.
     */
    public function deltaInStockUnit(StockItem $item, float $qty, string $unit, ?Food $food): float
    {
        [$stockUnit, $stockFactor] = Portions::normalize((string) $item->unit);
        [$reqUnit, $reqFactor] = Portions::normalize($unit);

        if ($stockUnit !== null && $stockUnit === $reqUnit) {
            return $qty * $reqFactor / ($stockFactor > 0 ? $stockFactor : 1.0);
        }

        $perStockUnit = Portions::toGrams(1.0, (string) $item->unit, $food);
        $requested = Portions::toGrams($qty, $unit, $food);

        if ($perStockUnit->isConvertible() && $requested->isConvertible() && (float) $perStockUnit->grams > 0) {
            return (float) $requested->grams / (float) $perStockUnit->grams;
        }

        return $qty;
    }

    /**
     * Crée un article « auto_stock » dans la liste de courses du propriétaire du stock,
     * sauf si un article non coché de même libellé (insensible à la casse) existe déjà.
     */
    private function queueShoppingItem(StockItem $item, ?Food $food): void
    {
        $stock = $item->relationLoaded('stock')
            ? $item->getRelation('stock')
            : Stock::query()->find($item->stock_id);

        if ($stock === null) {
            return;
        }

        $label = trim((string) ($item->food_name ?: ($food?->name ?? '')));
        if ($label === '') {
            $label = 'Article épuisé';
        }

        $existing = ShoppingItem::query()
            ->where('checked', false)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)]);

        if ($stock->household_id) {
            $existing->where('household_id', $stock->household_id);
        } else {
            $existing->where('user_id', $stock->user_id)->whereNull('household_id');
        }

        if ($existing->exists()) {
            return;
        }

        ShoppingItem::query()->forceCreate([
            'user_id' => $stock->user_id,
            'household_id' => $stock->household_id,
            'food_id' => $item->food_id,
            'label' => mb_substr($label, 0, 255),
            'quantity' => null,
            'unit' => $item->unit,
            'checked' => false,
            'source' => 'auto_stock',
        ]);
    }

    private function foodFor(StockItem $locked, StockItem $original): ?Food
    {
        if ($original->relationLoaded('food')) {
            return $original->getRelation('food');
        }

        return $locked->food_id ? Food::query()->find($locked->food_id) : null;
    }
}
