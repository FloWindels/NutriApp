<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lieu de stockage (Frigo, Congélateur, Placard…).
 * Personnel : user_id renseigné, household_id NULL. Foyer : user_id NULL, household_id renseigné.
 */
class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'household_id',
        'name',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'household_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function isShared(): bool
    {
        return $this->household_id !== null;
    }

    // ------------------------------------------------------------------
    // Portées
    // ------------------------------------------------------------------

    /**
     * Lieux visibles par l'utilisateur : ceux de son foyer s'il en a un, sinon ses lieux personnels.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->household_id) {
            return $query->where('household_id', $user->household_id);
        }

        return $query->where('user_id', $user->id)->whereNull('household_id');
    }

    /**
     * Lieux strictement personnels de l'utilisateur (même s'il appartient à un foyer).
     */
    public function scopePersonalOf(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id)->whereNull('household_id');
    }

    public function scopeOfHousehold(Builder $query, int $householdId): Builder
    {
        return $query->where('household_id', $householdId);
    }
}
