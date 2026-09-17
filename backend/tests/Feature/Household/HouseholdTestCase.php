<?php

namespace Tests\Feature\Household;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Profile;
use App\Models\User;
use App\Services\Household\HouseholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Socle des tests du module Foyer : helpers de création et assertion d'invariant
 * (« users.household_id == adhésion » pour TOUS les utilisateurs après CHAQUE endpoint).
 */
abstract class HouseholdTestCase extends TestCase
{
    use RefreshDatabase;

    protected HouseholdService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HouseholdService::class);
    }

    protected function login(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Utilisateur avec un profil complet (cibles persistées → ciblesEffectives = valeurs stockées).
     *
     * @param  array<string, mixed>  $profile
     */
    protected function userWithProfile(array $profile = [], array $user = []): User
    {
        $u = User::factory()->create($user);
        Profile::factory()->create(array_merge(['user_id' => $u->id], $profile));

        return $u;
    }

    /**
     * Foyer créé par le service (comme le ferait l'endpoint), propriétaire = $owner.
     */
    protected function householdOwnedBy(User $owner, string $name = 'Foyer test'): Household
    {
        return $this->service->create($owner, $name)['household'];
    }

    protected function joinHousehold(User $user, Household $household): Household
    {
        return $this->service->join($user, $household->invite_code)['household'];
    }

    /**
     * Invariant du foyer : pour chaque utilisateur, le cache users.household_id est exactement
     * le foyer de son adhésion (ou null) ; chaque foyer a un unique propriétaire = owner_id.
     */
    protected function assertHouseholdInvariant(): void
    {
        foreach (User::query()->get(['id', 'household_id']) as $user) {
            $membership = HouseholdMember::query()->where('user_id', $user->id)->value('household_id');

            $this->assertSame(
                $membership === null ? null : (int) $membership,
                $user->household_id === null ? null : (int) $user->household_id,
                "users.household_id désynchronisé pour l'utilisateur {$user->id}"
            );
        }

        foreach (Household::query()->get() as $household) {
            $owners = HouseholdMember::query()
                ->where('household_id', $household->id)
                ->where('role', HouseholdRole::Proprietaire->value)
                ->pluck('user_id');

            $this->assertCount(1, $owners, "Le foyer {$household->id} doit avoir exactement un propriétaire");
            $this->assertSame((int) $household->owner_id, (int) $owners->first(), "owner_id du foyer {$household->id} ≠ adhésion propriétaire");
        }

        // Aucune adhésion orpheline.
        $this->assertSame(
            0,
            HouseholdMember::query()->whereNotIn('household_id', Household::query()->select('id'))->count(),
            'Adhésion orpheline détectée'
        );
    }
}
