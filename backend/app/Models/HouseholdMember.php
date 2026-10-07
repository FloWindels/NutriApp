<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'user_id',
        'role',
        'share_profile',
        'joined_at',
    ];

    protected $casts = [
        'household_id' => 'integer',
        'user_id' => 'integer',
        'share_profile' => 'boolean',
        'joined_at' => 'datetime',
    ];

    protected $attributes = [
        'role' => 'membre',
        // Opt-in : le profil n'est partagé avec le foyer que si le membre le décide.
        'share_profile' => false,
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === HouseholdRole::Proprietaire->value;
    }
}
