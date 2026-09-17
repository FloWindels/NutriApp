<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'food_id',
        'food_name',
        'food_barcode',
        'food_brand',
        'quantity',
        'unit',
        'expires_at',
        'expiry_kind',
        'min_quantity',
        'opened_at',
        'depleted_at',
    ];

    protected $casts = [
        'stock_id' => 'integer',
        'food_id' => 'integer',
        'quantity' => 'float',
        'min_quantity' => 'float',
        'expires_at' => 'date:Y-m-d',
        'opened_at' => 'date:Y-m-d',
        'depleted_at' => 'datetime',
    ];

    protected $attributes = [
        'quantity' => 1,
        'unit' => 'unite',
        'expiry_kind' => 'dlc',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function mealItems(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }

    public function isDepleted(): bool
    {
        return $this->depleted_at !== null || (float) $this->quantity <= 0;
    }

    public function isLow(): bool
    {
        if ($this->isDepleted()) {
            return true;
        }

        return $this->min_quantity !== null && (float) $this->quantity <= (float) $this->min_quantity;
    }
}
