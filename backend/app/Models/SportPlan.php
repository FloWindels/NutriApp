<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée du calendrier sportif : « tel jour, je prévois tel sport pendant N minutes ».
 */
class SportPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'sport_id',
        'sport_name',
        'planned_duration_min',
        'planned_at',
        'lieu',
        'notes',
        'status',
        'session_id',
        'recurrence_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'sport_id' => 'integer',
        'session_id' => 'integer',
        'date' => 'date:Y-m-d',
        'planned_duration_min' => 'integer',
    ];

    protected $attributes = [
        'status' => 'prevu',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'session_id');
    }
}
