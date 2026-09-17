<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutExercise extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'exercise_id',
        'block',
        'position',
        'name',
        'sets',
        'reps',
        'duration_sec',
        'weight_kg',
        'rest_sec',
        'met',
        'completed',
        'notes',
    ];

    protected $casts = [
        'session_id' => 'integer',
        'exercise_id' => 'integer',
        'position' => 'integer',
        'sets' => 'integer',
        'reps' => 'integer',
        'duration_sec' => 'integer',
        'weight_kg' => 'float',
        'rest_sec' => 'integer',
        'met' => 'float',
        'completed' => 'boolean',
    ];

    protected $attributes = [
        'block' => 'principal',
        'position' => 0,
        'completed' => false,
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
