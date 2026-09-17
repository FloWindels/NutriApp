<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Food extends Model
{
    use HasFactory;

    protected $table = 'food';

    protected $fillable = [
        'barcode',
        'name',
        'brand',
        'image_url',
        'calories',
        'fat',
        'carbs',
        'proteins',
        'fiber',
        'sugar',
        'salt',
        'serving_size_g',
        'serving_label',
        'category',
        'allergens',
        'density_g_per_ml',
        'per_unit',
        'source_type',
        'source_fetched_at',
        'off_last_checked_at',
        'is_verified',
        'created_by_user_id',
    ];

    protected $casts = [
        'calories' => 'float',
        'fat' => 'float',
        'carbs' => 'float',
        'proteins' => 'float',
        'fiber' => 'float',
        'sugar' => 'float',
        'salt' => 'float',
        'serving_size_g' => 'float',
        'allergens' => 'array',
        'density_g_per_ml' => 'float',
        'source_fetched_at' => 'datetime',
        'off_last_checked_at' => 'datetime',
        'is_verified' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    protected $attributes = [
        'source_type' => 'manual',
        'per_unit' => '100g',
        'is_verified' => false,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Utilisateurs ayant mis cet aliment en favori (pivot `food_favorites`).
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'food_favorites', 'food_id', 'user_id')
            ->withTimestamps();
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function mealItems(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }

    public function isFromOpenFoodFacts(): bool
    {
        return $this->source_type === 'open_food_facts';
    }
}
