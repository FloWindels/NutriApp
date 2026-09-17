<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'household_id',
        'food_id',
        'label',
        'quantity',
        'unit',
        'checked',
        'source',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'household_id' => 'integer',
        'food_id' => 'integer',
        'quantity' => 'float',
        'checked' => 'boolean',
    ];

    protected $attributes = [
        'checked' => false,
        'source' => 'manuel',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
