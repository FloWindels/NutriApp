<?php

namespace App\Models;

use App\Enums\Offre;
use App\Enums\UserRole;
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
        'cgu_accepted_at',
        'cgu_version',
        'confidentialite_version',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'consentement_sante_at' => 'datetime',
        'suspendu_le' => 'datetime',
        'role' => UserRole::class,
        'offre' => Offre::class,
        'offre_expire_le' => 'datetime',
        'cgu_accepted_at' => 'datetime',
        'household_id' => 'integer',
    ];

    // ------------------------------------------------------------------
    // Compte
    // ------------------------------------------------------------------

    /**
     * Offre réellement applicable.
     *
     * C'est la meilleure des deux entre celle du compte et celle du propriétaire de son foyer :
     * sans cela l'offre Foyer ne servirait qu'à celui qui la paie, alors qu'elle est faite pour
     * couvrir le ménage. Une offre expirée retombe sur la gratuite, sans rien détruire.
     */
    public function offreEffective(): Offre
    {
        $sienne = $this->offreValide();

        if ($this->household_id === null) {
            return $sienne;
        }

        $proprietaire = static::query()
            ->select(['id', 'offre', 'offre_expire_le'])
            ->whereIn('id', function ($query) {
                $query->select('owner_id')->from('households')->where('id', $this->household_id);
            })
            ->first();

        $celleDuFoyer = $proprietaire?->offreValide() ?? Offre::Gratuit;

        return $celleDuFoyer->rang() > $sienne->rang() ? $celleDuFoyer : $sienne;
    }

    /** Offre du compte lui-même, ramenée à la gratuite si elle a expiré. */
    public function offreValide(): Offre
    {
        $offre = $this->offre ?? Offre::Gratuit;

        if ($this->offre_expire_le !== null && $this->offre_expire_le->isPast()) {
            return Offre::Gratuit;
        }

        return $offre;
    }

    /** L'unique question posée par le middleware et les contrôleurs. */
    public function peut(string $capacite): bool
    {
        return $this->offreEffective()->permet($capacite);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Administrateur;
    }

    public function estSuspendu(): bool
    {
        return $this->suspendu_le !== null;
    }

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
