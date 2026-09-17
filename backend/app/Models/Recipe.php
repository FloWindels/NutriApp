<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by_user_id',
        'title',
        'description',
        'prep_time_minutes',
        'calories',
        'proteins',
        'carbs',
        'fat',
        'servings',
        'image_url',
        'ingredients',
        'tags',
        'meal_types',
        'is_public',
        'is_estimate',
    ];

    protected $casts = [
        'prep_time_minutes' => 'integer',
        'calories' => 'float',
        'proteins' => 'float',
        'carbs' => 'float',
        'fat' => 'float',
        'servings' => 'float',
        'ingredients' => 'array',
        'tags' => 'array',
        'meal_types' => 'array',
        'is_public' => 'boolean',
        'is_estimate' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    protected $attributes = [
        'servings' => 1,
        'tags' => '[]',
        'meal_types' => '[]',
        'is_public' => false,
        'is_estimate' => false,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function mealItems(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    public function hasMacros(): bool
    {
        return $this->proteins !== null && $this->carbs !== null && $this->fat !== null;
    }

    /**
     * Valeurs par portion (macros null si inconnues).
     *
     * @return array{calories: float, proteins: float|null, carbs: float|null, fat: float|null}
     */
    public function perServing(): array
    {
        $servings = max((float) $this->servings, 0.5);

        $divide = fn (?float $v): ?float => $v === null ? null : round($v / $servings, 1);

        return [
            'calories' => round((float) $this->calories / $servings, 1),
            'proteins' => $divide($this->proteins),
            'carbs' => $divide($this->carbs),
            'fat' => $divide($this->fat),
        ];
    }
}
