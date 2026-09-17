<?php

namespace App\Http\Resources;

use App\Enums\HouseholdRole;
use App\Models\HouseholdMember;
use App\Models\Profile;
use App\Models\User;
use App\Services\NutritionCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membre du foyer (brief §10) : `{user_id, name, role, share_profile, calories_cibles|null,
 * regime|null, joined_at}` + `is_owner`, `is_me`, `is_minor`. Les cibles et le régime ne
 * sont exposés que si le membre partage son profil (ou s'il s'agit du lecteur).
 *
 * Attend `user.profile` chargé (preventLazyLoading).
 *
 * @mixin HouseholdMember
 */
class HouseholdMemberResource extends JsonResource
{
    public function __construct($resource, private readonly ?User $viewer = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HouseholdMember $member */
        $member = $this->resource;
        /** @var User|null $user */
        $user = $member->relationLoaded('user') ? $member->getRelation('user') : null;
        /** @var Profile|null $profile */
        $profile = $user !== null && $user->relationLoaded('profile') ? $user->getRelation('profile') : null;

        $isMe = $this->viewer !== null && (int) $member->user_id === (int) $this->viewer->id;
        $shared = $isMe || (bool) $member->share_profile;

        $calories = null;
        $regime = null;

        if ($shared && $profile !== null) {
            $cibles = app(NutritionCalculator::class)->ciblesEffectives($profile);
            $calories = $cibles['calories'] === null ? null : (int) $cibles['calories'];
            $regime = $profile->regime_alimentaire;
        }

        return [
            'user_id' => (int) $member->user_id,
            'name' => (string) ($user?->name ?? ''),
            'role' => (string) $member->role,
            'is_owner' => $member->role === HouseholdRole::Proprietaire->value,
            'is_me' => $isMe,
            'share_profile' => (bool) $member->share_profile,
            'calories_cibles' => $calories,
            'regime' => $regime,
            'is_minor' => $shared && $profile !== null ? $profile->isMinor() : null,
            'joined_at' => $member->joined_at?->toISOString(),
        ];
    }
}
