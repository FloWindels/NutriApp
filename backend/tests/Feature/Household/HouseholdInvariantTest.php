<?php

namespace Tests\Feature\Household;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * HouseholdInvariantTest (brief §10) : `users.household_id` n'est écrit que par HouseholdService,
 * dans la même transaction que l'adhésion, et reste égal à l'adhésion après chaque opération
 * du service (create, join, leave, removeMember, dissolve, transferOwnership).
 */
class HouseholdInvariantTest extends HouseholdTestCase
{
    public function test_invariant_conserve_sur_tout_le_cycle_de_vie(): void
    {
        $owner = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->assertHouseholdInvariant();

        $household = $this->service->create($owner, 'Foyer')['household'];
        $this->assertSame($household->id, (int) $owner->household_id, 'l’instance passée est synchronisée');
        $this->assertHouseholdInvariant();

        $this->service->join($a, $household->invite_code);
        $this->service->join($b, mb_strtolower($household->invite_code));
        $this->assertSame(3, HouseholdMember::query()->where('household_id', $household->id)->count());
        $this->assertHouseholdInvariant();

        $this->service->transferOwnership($household, $a);
        $this->assertSame($a->id, (int) $household->fresh()->owner_id);
        $this->assertHouseholdInvariant();

        // L'ancien propriétaire (désormais membre) peut partir.
        $this->assertSame(['dissolved' => false], $this->service->leave($owner));
        $this->assertNull($owner->fresh()->household_id);
        $this->assertHouseholdInvariant();

        $this->service->removeMember($household->fresh(), $b);
        $this->assertNull($b->fresh()->household_id);
        $this->assertHouseholdInvariant();

        // Propriétaire seul → leave dissout.
        $this->assertSame(['dissolved' => true], $this->service->leave($a));
        $this->assertDatabaseMissing('households', ['id' => $household->id]);
        $this->assertSame(0, HouseholdMember::query()->count());
        $this->assertSame(0, User::query()->whereNotNull('household_id')->count());
        $this->assertHouseholdInvariant();
    }

    public function test_dissolve_remet_tous_les_caches_a_null(): void
    {
        $owner = User::factory()->create();
        $household = $this->service->create($owner, 'Foyer')['household'];
        $members = User::factory()->count(3)->create();
        foreach ($members as $m) {
            $this->service->join($m, $household->invite_code);
        }
        $this->assertSame(4, User::query()->where('household_id', $household->id)->count());

        $this->service->dissolve($household);

        $this->assertSame(0, User::query()->whereNotNull('household_id')->count());
        $this->assertSame(0, HouseholdMember::query()->count());
        $this->assertSame(0, Household::query()->count());
        $this->assertHouseholdInvariant();
    }

    public function test_les_echecs_metier_ne_laissent_aucune_trace(): void
    {
        $owner = User::factory()->create();
        $household = $this->service->create($owner, 'Foyer')['household'];
        $member = User::factory()->create();
        $this->service->join($member, $household->invite_code);

        // Déjà membre → 422, rien ne change.
        try {
            $this->service->create($member, 'Autre');
            $this->fail('ValidationException attendue');
        } catch (ValidationException $e) {
            $this->assertSame('Tu fais déjà partie d’un foyer.', $e->errors()['household'][0]);
        }
        $this->assertSame(1, Household::query()->count());

        // Code inconnu → ModelNotFoundException, aucune adhésion.
        $stranger = User::factory()->create();
        try {
            $this->service->join($stranger, 'ZZZZZZZZ');
            $this->fail('ModelNotFoundException attendue');
        } catch (ModelNotFoundException) {
        }
        $this->assertNull($stranger->fresh()->household_id);

        // Propriétaire avec membres → 422, toujours propriétaire.
        try {
            $this->service->leave($owner);
            $this->fail('ValidationException attendue');
        } catch (ValidationException) {
        }
        $this->assertSame($household->id, (int) $owner->fresh()->household_id);

        // Transfert vers un non-membre → 422, owner inchangé.
        try {
            $this->service->transferOwnership($household, $stranger);
            $this->fail('ValidationException attendue');
        } catch (ValidationException) {
        }
        $this->assertSame($owner->id, (int) $household->fresh()->owner_id);

        $this->assertHouseholdInvariant();
    }

    public function test_les_codes_d_invitation_sont_uniques_et_dans_l_alphabet(): void
    {
        $codes = collect(range(1, 8))
            ->map(fn () => $this->service->create(User::factory()->create(), 'Foyer')['household']->invite_code);

        $this->assertSame(8, $codes->unique()->count());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^['.Household::INVITE_ALPHABET.']{8}$/', $code);
        }
        $this->assertHouseholdInvariant();
    }
}
