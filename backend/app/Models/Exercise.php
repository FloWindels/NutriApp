<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'muscle_group',
        'equipment',
        'level',
        'met',
        'default_sets',
        'default_reps',
        'default_duration_sec',
        'instructions',
        'contraindications',
        'is_public',
        'created_by_user_id',
    ];

    protected $casts = [
        'met' => 'float',
        'default_sets' => 'integer',
        'default_reps' => 'integer',
        'default_duration_sec' => 'integer',
        'contraindications' => 'array',
        'is_public' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    protected $attributes = [
        'is_public' => true,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Vrai si l'exercice sollicite au moins une des zones à éviter.
     *
     * @param  list<string>  $zones
     */
    public function hitsZones(array $zones): bool
    {
        if ($zones === [] || empty($this->contraindications)) {
            return false;
        }

        return array_intersect($this->contraindications, $zones) !== [];
    }
}
