<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'household_id',
        'date',
        'meal_type',
        'recipe_id',
        'food_id',
        'title',
        'servings',
        'notes',
        'status',
        'meal_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'household_id' => 'integer',
        'recipe_id' => 'integer',
        'food_id' => 'integer',
        'meal_id' => 'integer',
        'date' => 'date:Y-m-d',
        'servings' => 'float',
    ];

    protected $attributes = [
        'servings' => 1,
        'status' => 'prevu',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }
}
