<?php

namespace App\Models;

use App\Enums\MealType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'type',
        'name',
        'consumed_at',
        'notes',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'date' => 'date:Y-m-d',
        'type' => MealType::class,
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    /**
     * Totaux nutritionnels du repas à partir des items chargés.
     *
     * @return array{calories: float, proteins: float, carbs: float, fat: float, fiber: float|null, sugar: float|null, salt: float|null, is_partial: bool}
     */
    public function totals(): array
    {
        $items = $this->items;

        $sumNullable = function (string $key) use ($items): ?float {
            $values = $items->pluck($key)->filter(fn ($v) => $v !== null);

            return $values->isEmpty() ? null : round((float) $values->sum(), 1);
        };

        return [
            'calories' => round((float) $items->sum('calories'), 1),
            'proteins' => round((float) $items->sum('proteins'), 1),
            'carbs' => round((float) $items->sum('carbs'), 1),
            'fat' => round((float) $items->sum('fat'), 1),
            'fiber' => $sumNullable('fiber'),
            'sugar' => $sumNullable('sugar'),
            'salt' => $sumNullable('salt'),
            'is_partial' => $items->contains(fn (MealItem $i) => $i->fiber === null || $i->sugar === null || $i->salt === null),
        ];
    }
}
