<?php

namespace App\Http\Resources;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Foyer vu par un membre (brief §10) : `{id, name, invite_code (propriétaire seulement,
 * sinon null), role, members:[HouseholdMemberResource], stock_items_count}` + `owner_id`,
 * `members_count`, `is_owner`, `created_at`.
 *
 * Attend `members.user.profile` chargé (preventLazyLoading).
 *
 * @mixin Household
 */
class HouseholdResource extends JsonResource
{
    public function __construct($resource, private readonly User $viewer)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Household $household */
        $household = $this->resource;
        $isOwner = $household->isOwnedBy($this->viewer);

        $members = $household->relationLoaded('members')
            ? $household->getRelation('members')
            : collect();

        $mine = $members->first(fn (HouseholdMember $m) => (int) $m->user_id === (int) $this->viewer->id);
        $role = $mine?->role ?? ($isOwner ? HouseholdRole::Proprietaire->value : HouseholdRole::Membre->value);

        $stockItemsCount = StockItem::query()
            ->whereIn('stock_id', $household->stocks()->select('id'))
            ->where('quantity', '>', 0)
            ->count();

        return [
            'id' => (int) $household->id,
            'name' => (string) $household->name,
            'owner_id' => (int) $household->owner_id,
            'invite_code' => $isOwner ? (string) $household->invite_code : null,
            'role' => (string) $role,
            'is_owner' => $isOwner,
            'members' => $members
                ->map(fn (HouseholdMember $m) => (new HouseholdMemberResource($m, $this->viewer))->toArray($request))
                ->values()
                ->all(),
            'members_count' => $members->count(),
            'stock_items_count' => $stockItemsCount,
            'created_at' => $household->created_at?->toISOString(),
        ];
    }
}
