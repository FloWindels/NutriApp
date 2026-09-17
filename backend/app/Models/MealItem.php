<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'meal_id',
        'food_id',
        'recipe_id',
        'stock_item_id',
        'source_type',
        'label',
        'quantity',
        'unit',
        'grams_equivalent',
        'calories',
        'proteins',
        'carbs',
        'fat',
        'fiber',
        'sugar',
        'salt',
        'ref_basis',
        'ref_calories',
        'ref_proteins',
        'ref_carbs',
        'ref_fat',
        'ref_fiber',
        'ref_sugar',
        'ref_salt',
        'ref_serving_size_g',
        'is_estimate',
    ];

    protected $casts = [
        'meal_id' => 'integer',
        'food_id' => 'integer',
        'recipe_id' => 'integer',
        'stock_item_id' => 'integer',
        'quantity' => 'float',
        'grams_equivalent' => 'float',
        'calories' => 'float',
        'proteins' => 'float',
        'carbs' => 'float',
        'fat' => 'float',
        'fiber' => 'float',
        'sugar' => 'float',
        'salt' => 'float',
        'ref_calories' => 'float',
        'ref_proteins' => 'float',
        'ref_carbs' => 'float',
        'ref_fat' => 'float',
        'ref_fiber' => 'float',
        'ref_sugar' => 'float',
        'ref_salt' => 'float',
        'ref_serving_size_g' => 'float',
        'is_estimate' => 'boolean',
    ];

    protected $attributes = [
        'is_estimate' => false,
    ];

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
