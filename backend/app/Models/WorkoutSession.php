<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'planned_at',
        'title',
        'kind',
        'goal',
        'level',
        'equipment',
        'focus',
        'duration_min',
        'calories_burned',
        'calories_source',
        'status',
        'rpe',
        'notes',
        'source',
        'intensity',
        'distance_km',
        'started_at',
        'completed_at',
        'sport_id',
        'sport_name',
        'lieu',
        'generated_by',
        'llm_model',
        'sport_plan_id',
        'zones_a_eviter',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'sport_id' => 'integer',
        'sport_plan_id' => 'integer',
        'date' => 'date:Y-m-d',
        'equipment' => 'array',
        'focus' => 'array',
        'zones_a_eviter' => 'array',
        'duration_min' => 'integer',
        'calories_burned' => 'float',
        'rpe' => 'integer',
        'distance_km' => 'float',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'kind' => 'seance',
        'status' => 'prevue',
        'source' => 'manuelle',
        'calories_source' => 'auto',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class, 'session_id')->orderBy('position');
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    /**
     * Entrée du calendrier dont cette séance découle (sans FK : évite le cycle plans ↔ séances).
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SportPlan::class, 'sport_plan_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === SessionStatus::Terminee->value;
    }

    public function isManualCalories(): bool
    {
        return $this->calories_source === 'manuel';
    }
}
