<?php

namespace App\Models;

use App\Enums\Intensity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sport extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'met_faible',
        'met_moderee',
        'met_elevee',
        'icon',
        'is_public',
        'created_by_user_id',
    ];

    protected $casts = [
        'met_faible' => 'float',
        'met_moderee' => 'float',
        'met_elevee' => 'float',
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

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function sportPlans(): HasMany
    {
        return $this->hasMany(SportPlan::class);
    }

    /**
     * Sports publics + sports personnalisés de l'utilisateur.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('is_public', true)
                ->orWhere('created_by_user_id', $user->id);
        });
    }

    public function metFor(Intensity|string|null $intensity): float
    {
        $intensity = $intensity instanceof Intensity
            ? $intensity
            : (Intensity::tryFrom((string) $intensity) ?? Intensity::Moderee);

        return (float) $this->{$intensity->metColumn()};
    }

    public function isMineFor(User $user): bool
    {
        return ! $this->is_public && (int) $this->created_by_user_id === (int) $user->id;
    }
}
