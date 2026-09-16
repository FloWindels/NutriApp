<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by_user_id',
        'title',
        'description',
        'prep_time_minutes',
        'calories',
        'image_url',
        'ingredients',
        'is_public',
    ];

    protected $casts = [
        'prep_time_minutes' => 'integer',
        'calories' => 'float',
        'ingredients' => 'array',
        'is_public' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}