<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'quantity' => 'float',
        'expires_at' => 'date',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
