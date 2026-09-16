<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

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
        'source_type',
        'created_by_user_id',
    ];

    protected $casts = [
        'calories' => 'float',
        'fat' => 'float',
        'carbs' => 'float',
        'proteins' => 'float',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
