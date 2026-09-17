<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'household_id',
        'consentement_sante_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'consentement_sante_at' => 'datetime',
        'household_id' => 'integer',
    ];

    // ------------------------------------------------------------------
    // Compte
    // ------------------------------------------------------------------

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function notificationReads(): HasMany
    {
        return $this->hasMany(NotificationRead::class);
    }

    // ------------------------------------------------------------------
    // Foyer
    // ------------------------------------------------------------------

    /**
     * Foyer courant (cache `users.household_id`, écrit uniquement par HouseholdService).
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'household_id');
    }

    public function householdMembership(): HasOne
    {
        return $this->hasOne(HouseholdMember::class);
    }

    public function ownedHouseholds(): HasMany
    {
        return $this->hasMany(Household::class, 'owner_id');
    }

    // ------------------------------------------------------------------
    // Nutrition
    // ------------------------------------------------------------------

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    public function weightLogs(): HasMany
    {
        return $this->hasMany(WeightLog::class);
    }

    public function dailyTargets(): HasMany
    {
        return $this->hasMany(DailyTarget::class);
    }

    /**
     * Articles de courses personnels (hors foyer).
     */
    public function shoppingItems(): HasMany
    {
        return $this->hasMany(ShoppingItem::class);
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    public function favoriteFoods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class, 'food_favorites', 'user_id', 'food_id')
            ->withTimestamps();
    }

    /**
     * Aliments créés par l'utilisateur.
     */
    public function foods(): HasMany
    {
        return $this->hasMany(Food::class, 'created_by_user_id');
    }

    /**
     * Recettes créées par l'utilisateur.
     */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'created_by_user_id');
    }

    /**
     * Lieux de stock personnels (les lieux de foyer passent par Household::stocks()).
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    // ------------------------------------------------------------------
    // Sport
    // ------------------------------------------------------------------

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function sportPlans(): HasMany
    {
        return $this->hasMany(SportPlan::class);
    }

    /**
     * Sports personnalisés créés par l'utilisateur.
     */
    public function sports(): HasMany
    {
        return $this->hasMany(Sport::class, 'created_by_user_id');
    }
}
