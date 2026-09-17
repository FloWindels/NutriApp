<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cibles du jour figées au premier aliment enregistré.
 */
class DailyTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'calories',
        'proteins',
        'carbs',
        'fat',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'date' => 'date:Y-m-d',
        'calories' => 'integer',
        'proteins' => 'integer',
        'carbs' => 'integer',
        'fat' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
