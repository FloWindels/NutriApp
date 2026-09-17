<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Household extends Model
{
    use HasFactory;

    /**
     * Alphabet du code d'invitation (sans caractères ambigus : 0, O, 1, I).
     */
    public const INVITE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const INVITE_LENGTH = 8;

    protected $fillable = [
        'name',
        'owner_id',
        'invite_code',
    ];

    protected $casts = [
        'owner_id' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    /**
     * Utilisateurs du foyer, via les adhésions.
     */
    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            HouseholdMember::class,
            'household_id',
            'id',
            'id',
            'user_id',
        );
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function shoppingItems(): HasMany
    {
        return $this->hasMany(ShoppingItem::class);
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->owner_id === (int) $user->id;
    }

    /**
     * Génère un code d'invitation aléatoire (l'unicité est garantie par l'appelant / l'index).
     */
    public static function generateInviteCode(): string
    {
        $alphabet = self::INVITE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::INVITE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
