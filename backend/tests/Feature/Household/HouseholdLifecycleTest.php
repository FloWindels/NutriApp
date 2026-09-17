<?php

namespace Tests\Feature\Household;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\MealPlan;
use App\Models\ShoppingItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Household\HouseholdService;

/**
 * Cycle de vie du foyer (brief §10) : création, adhésion, départ, retrait, dissolution,
 * transfert, code d'invitation — avec l'invariant users.household_id après chaque appel.
 */
class HouseholdLifecycleTest extends HouseholdTestCase
{
    // ------------------------------------------------------------------------------------
    // GET /household
    // ------------------------------------------------------------------------------------

    public function test_get_household_sans_foyer_renvoie_data_null(): void
    {
        $this->login(User::factory()->create());

        $this->getJson('/api/household')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_get_household_non_authentifie_401(): void
    {
        $this->getJson('/api/household')
            ->assertStatus(401)
            ->assertJson(['message' => 'Non authentifié.']);
    }

    // ------------------------------------------------------------------------------------
    // POST /household
    // ------------------------------------------------------------------------------------

    public function test_create_pose_proprietaire_role_et_code(): void
    {
        $user = $this->login($this->userWithProfile(['calories_cibles' => 2100, 'regime_alimentaire' => 'vegetarien']));

        $response = $this->postJson('/api/household', ['name' => '  Foyer Dupont  '])
            ->assertCreated()
            ->assertJsonPath('message', 'Foyer créé.')
            ->assertJsonPath('data.name', 'Foyer Dupont')
            ->assertJsonPath('data.role', 'proprietaire')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.owner_id', $user->id)
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.stock_items_count', 0)
            ->assertJsonPath('merged_stock_items', 0);

        $data = $response->json('data');
        $this->assertSame(
            ['id', 'name', 'owner_id', 'invite_code', 'role', 'is_owner', 'members', 'members_count', 'stock_items_count', 'created_at'],
            array_keys($data)
        );

        $code = $data['invite_code'];
        $this->assertIsString($code);
        $this->assertSame(Household::INVITE_LENGTH, strlen($code));
        $this->assertMatchesRegularExpression('/^['.Household::INVITE_ALPHABET.']{8}$/', $code);

        $member = $data['members'][0];
        $this->assertSame(
            ['user_id', 'name', 'role', 'is_owner', 'is_me', 'share_profile', 'calories_cibles', 'regime', 'is_minor', 'joined_at'],
            array_keys($member)
        );
        $this->assertSame($user->id, $member['user_id']);
        $this->assertSame('proprietaire', $member['role']);
        $this->assertTrue($member['is_me']);
        $this->assertTrue($member['share_profile']);
        $this->assertSame(2100, $member['calories_cibles']);
        $this->assertSame('vegetarien', $member['regime']);
        $this->assertFalse($member['is_minor']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $member['joined_at']);

        $household = Household::query()->firstOrFail();
        $this->assertSame($user->id, (int) $household->owner_id);
        $this->assertSame($household->id, (int) $user->fresh()->household_id);
        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => HouseholdRole::Proprietaire->value,
        ]);
        $this->assertHouseholdInvariant();
    }

    public function test_create_quand_deja_membre_422(): void
    {
        $user = $this->login(User::factory()->create());
        $this->householdOwnedBy($user);

        $this->postJson('/api/household', ['name' => 'Autre foyer'])
            ->assertStatus(422)
            ->assertJsonPath('message', HouseholdService::MSG_DEJA_MEMBRE)
            ->assertJsonPath('errors.household.0', 'Tu fais déjà partie d’un foyer.');

        $this->assertSame(1, Household::query()->count());
        $this->assertHouseholdInvariant();
    }

    public function test_create_validation_francaise_422(): void
    {
        $this->login(User::factory()->create());

        $this->postJson('/api/household', ['name' => 'A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', fn (string $m) => str_contains($m, 'nom du foyer'));

        $this->postJson('/api/household', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Le champ nom du foyer est obligatoire.');
    }

    // ------------------------------------------------------------------------------------
    // GET /household/preview
    // ------------------------------------------------------------------------------------

    public function test_preview_renvoie_nom_et_nombre_de_membres(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner, 'Les Martin');
        $this->joinHousehold(User::factory()->create(), $household);

        $this->login(User::factory()->create());

        $this->getJson('/api/household/preview?invite_code='.strtolower($household->invite_code))
            ->assertOk()
            ->assertExactJson(['data' => ['name' => 'Les Martin', 'members_count' => 2]]);
    }

    public function test_preview_code_inconnu_404_code_introuvable(): void
    {
        $this->login(User::factory()->create());

        $this->getJson('/api/household/preview?invite_code=ZZZZZZZZ')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Code introuvable.']);
    }

    public function test_preview_code_mal_forme_422(): void
    {
        $this->login(User::factory()->create());

        $this->getJson('/api/household/preview?invite_code=ABC')
            ->assertStatus(422)
            ->assertJsonPath('errors.invite_code.0', 'Le code d’invitation doit comporter 8 caractères.');

        $this->getJson('/api/household/preview')
            ->assertStatus(422)
            ->assertJsonPath('errors.invite_code.0', 'Le champ code d’invitation est obligatoire.');
    }

    // ------------------------------------------------------------------------------------
    // POST /household/join
    // ------------------------------------------------------------------------------------

    public function test_join_par_code_insensible_a_la_casse(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        $user = $this->login(User::factory()->create());

        $response = $this->postJson('/api/household/join', ['invite_code' => mb_strtolower($household->invite_code)])
            ->assertOk()
            ->assertJsonPath('data.id', $household->id)
            ->assertJsonPath('data.role', 'membre')
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.members_count', 2)
            ->assertJsonPath('merged_stock_items', 0);

        $this->assertStringContainsString('Bienvenue dans le foyer', $response->json('message'));
        $this->assertSame($household->id, (int) $user->fresh()->household_id);
        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'membre',
            'share_profile' => 1,
        ]);
        $this->assertHouseholdInvariant();
    }

    public function test_join_code_inconnu_404(): void
    {
        $this->login(User::factory()->create());

        $this->postJson('/api/household/join', ['invite_code' => 'ABCDEFGH'])
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Code introuvable.']);

        $this->assertSame(0, HouseholdMember::query()->count());
    }

    public function test_join_quand_deja_membre_422(): void
    {
        $other = $this->householdOwnedBy(User::factory()->create());
        $user = $this->login(User::factory()->create());
        $this->householdOwnedBy($user, 'Mon foyer');

        $this->postJson('/api/household/join', ['invite_code' => $other->invite_code])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tu fais déjà partie d’un foyer.');

        $this->assertSame(1, HouseholdMember::query()->where('household_id', $other->id)->count());
        $this->assertHouseholdInvariant();
    }

    // ------------------------------------------------------------------------------------
    // Code d'invitation : visibilité et régénération
    // ------------------------------------------------------------------------------------

    public function test_invite_code_visible_uniquement_par_le_proprietaire(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        $member = User::factory()->create();
        $this->joinHousehold($member, $household);

        $this->login($owner);
        $this->getJson('/api/household')
            ->assertOk()
            ->assertJsonPath('data.invite_code', $household->invite_code)
            ->assertJsonPath('data.role', 'proprietaire');

        $this->login($member);
        $this->getJson('/api/household')
            ->assertOk()
            ->assertJsonPath('data.invite_code', null)
            ->assertJsonPath('data.role', 'membre')
            ->assertJsonPath('data.is_owner', false);
    }

    public function test_regenerate_code_par_le_proprietaire_invalide_l_ancien(): void
    {
        $owner = $this->login(User::factory()->create());
        $household = $this->householdOwnedBy($owner);
        $old = $household->invite_code;

        $response = $this->postJson('/api/household/regenerate-code')
            ->assertOk()
            ->assertJsonPath('message', 'Nouveau code d’invitation généré.');

        $new = $response->json('data.invite_code');
        $this->assertNotSame($old, $new);
        $this->assertMatchesRegularExpression('/^['.Household::INVITE_ALPHABET.']{8}$/', $new);
        $this->assertSame($new, $household->fresh()->invite_code);

        $this->login(User::factory()->create());
        $this->getJson('/api/household/preview?invite_code='.$old)->assertStatus(404);
        $this->getJson('/api/household/preview?invite_code='.$new)->assertOk();
    }

    public function test_actions_proprietaire_refusees_au_membre_403(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        $member = $this->login(User::factory()->create());
        $this->joinHousehold($member, $household);

        $this->putJson('/api/household', ['name' => 'Piraté'])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Action non autorisée.']);
        $this->postJson('/api/household/regenerate-code')->assertStatus(403);
        $this->deleteJson('/api/household/members/'.$owner->id)->assertStatus(403);
        $this->deleteJson('/api/household')->assertStatus(403);
        $this->postJson('/api/household/transfer', ['user_id' => $member->id])->assertStatus(403);

        $this->assertSame('Foyer test', $household->fresh()->name);
        $this->assertSame(2, HouseholdMember::query()->count());
        $this->assertHouseholdInvariant();
    }

    public function test_actions_sans_foyer_422(): void
    {
        $this->login(User::factory()->create());

        $this->putJson('/api/household', ['name' => 'Foyer'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tu ne fais partie d’aucun foyer.');
        $this->postJson('/api/household/leave')->assertStatus(422);
        $this->putJson('/api/household/members/me', ['share_profile' => false])->assertStatus(422);
        $this->deleteJson('/api/household')->assertStatus(422);
    }

    // ------------------------------------------------------------------------------------
    // PUT /household
    // ------------------------------------------------------------------------------------

    public function test_update_renomme_le_foyer(): void
    {
        $owner = $this->login(User::factory()->create());
        $this->householdOwnedBy($owner);

        $this->putJson('/api/household', ['name' => 'Chez nous'])
            ->assertOk()
            ->assertJsonPath('message', 'Foyer renommé.')
            ->assertJsonPath('data.name', 'Chez nous');

        $this->putJson('/api/household', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ------------------------------------------------------------------------------------
    // POST /household/leave
    // ------------------------------------------------------------------------------------

    public function test_membre_quitte_le_foyer_sans_rien_reprendre(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        $member = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $member->id, 'name' => 'Cave']);
        StockItem::factory()->count(2)->create(['stock_id' => $stock->id]);
        $this->joinHousehold($member, $household);

        $this->login($member);
        $this->postJson('/api/household/leave')
            ->assertOk()
            ->assertExactJson(['message' => 'Tu as quitté le foyer.']);

        $this->assertNull($member->fresh()->household_id);
        $this->assertDatabaseMissing('household_members', ['user_id' => $member->id]);
        $this->assertDatabaseHas('households', ['id' => $household->id]);
        // Le lieu « Cave » reste au foyer.
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'user_id' => null, 'household_id' => $household->id]);
        $this->assertSame(2, StockItem::query()->where('stock_id', $stock->id)->count());
        $this->assertHouseholdInvariant();

        $this->getJson('/api/household')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_proprietaire_ne_peut_pas_quitter_avec_des_membres_422(): void
    {
        $owner = $this->login(User::factory()->create());
        $household = $this->householdOwnedBy($owner);
        $this->joinHousehold(User::factory()->create(), $household);

        $this->postJson('/api/household/leave')
            ->assertStatus(422)
            ->assertJsonPath('message', HouseholdService::MSG_PROPRIETAIRE_QUITTE);

        $this->assertSame($household->id, (int) $owner->fresh()->household_id);
        $this->assertSame(2, HouseholdMember::query()->count());
        $this->assertHouseholdInvariant();
    }

    public function test_proprietaire_seul_qui_quitte_dissout_le_foyer(): void
    {
        $owner = $this->login(User::factory()->create());
        $household = $this->householdOwnedBy($owner);
        Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);

        $this->postJson('/api/household/leave')
            ->assertOk()
            ->assertJsonPath('message', 'Tu étais seul dans le foyer : il a été supprimé.');

        $this->assertDatabaseMissing('households', ['id' => $household->id]);
        $this->assertNull($owner->fresh()->household_id);
        $this->assertDatabaseHas('stocks', ['name' => 'Frigo', 'user_id' => $owner->id, 'household_id' => null]);
        $this->assertHouseholdInvariant();
    }

    // ------------------------------------------------------------------------------------
    // DELETE /household/members/{user}
    // ------------------------------------------------------------------------------------

    public function test_proprietaire_retire_un_membre(): void
    {
        $owner = $this->login(User::factory()->create());
        $household = $this->householdOwnedBy($owner);
        $member = User::factory()->create(['name' => 'Léa']);
        $this->joinHousehold($member, $household);

        $this->deleteJson('/api/household/members/'.$member->id)
            ->assertOk()
            ->assertJsonPath('message', 'Léa a été retiré du foyer.')
            ->assertJsonPath('data.members_count', 1);

        $this->assertNull($member->fresh()->household_id);
        $this->assertDatabaseMissing('household_members', ['user_id' => $member->id]);
        $this->assertHouseholdInvariant();
    }

    public function test_retirer_un_utilisateur_hors_foyer_404(): void
    {
        $owner = $this->login(User::factory()->create());
        $this->householdOwnedBy($owner);
        $stranger = User::factory()->create();
        $this->householdOwnedBy($stranger, 'Ailleurs');

        $this->deleteJson('/api/household/members/'.$stranger->id)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Introuvable.']);
        $this->deleteJson('/api/household/members/999999')->assertStatus(404);
        $this->deleteJson('/api/household/members/abc')->assertStatus(404);

        $this->assertNotNull($stranger->fresh()->household_id);
        $this->assertHouseholdInvariant();
    }

    public function test_proprietaire_ne_peut_pas_se_retirer_lui_meme_422(): void
    {
        $owner = $this->login(User::factory()->create());
        $this->householdOwnedBy($owner);

        $this->deleteJson('/api/household/members/'.$owner->id)
            ->assertStatus(422)
            ->assertJsonPath('message', HouseholdService::MSG_RETIRER_SOI_MEME);

        $this->assertHouseholdInvariant();
    }

    // ------------------------------------------------------------------------------------
    // DELETE /household (dissolution)
    // ------------------------------------------------------------------------------------

    public function test_dissolution_rend_tout_au_proprietaire_avec_renommage_foyer(): void
    {
        $owner = $this->login(User::factory()->create());
        $household = $this->householdOwnedBy($owner);
        $member = User::factory()->create();
        $this->joinHousehold($member, $household);

        $frigo = Stock::factory()->forHousehold($household)->create(['name' => 'Frigo']);
        $cave = Stock::factory()->forHousehold($household)->create(['name' => 'Cave']);
        StockItem::factory()->count(3)->create(['stock_id' => $frigo->id]);
        // Doublon personnel du propriétaire (cas limite) → le lieu du foyer est renommé.
        Stock::query()->forceCreate(['user_id' => $owner->id, 'household_id' => null, 'name' => 'frigo']);

        ShoppingItem::factory()->create(['user_id' => $member->id, 'household_id' => $household->id, 'label' => 'Lait']);
        MealPlan::factory()->create(['user_id' => $member->id, 'household_id' => $household->id]);

        $this->deleteJson('/api/household')
            ->assertOk()
            ->assertExactJson(['message' => 'Foyer supprimé.']);

        $this->assertDatabaseMissing('households', ['id' => $household->id]);
        $this->assertSame(0, HouseholdMember::query()->count());
        $this->assertNull($owner->fresh()->household_id);
        $this->assertNull($member->fresh()->household_id);

        $this->assertDatabaseHas('stocks', ['id' => $frigo->id, 'user_id' => $owner->id, 'household_id' => null, 'name' => 'Frigo (foyer)']);
        $this->assertDatabaseHas('stocks', ['id' => $cave->id, 'user_id' => $owner->id, 'household_id' => null, 'name' => 'Cave']);
        $this->assertSame(3, StockItem::query()->where('stock_id', $frigo->id)->count());
        $this->assertSame(0, Stock::query()->whereNotNull('household_id')->count());

        $this->assertDatabaseHas('shopping_items', ['label' => 'Lait', 'user_id' => $owner->id, 'household_id' => null]);
        $this->assertSame(1, MealPlan::query()->where('user_id', $owner->id)->whereNull('household_id')->count());
        $this->assertHouseholdInvariant();
    }

    // ------------------------------------------------------------------------------------
    // PUT /household/members/me
    // ------------------------------------------------------------------------------------

    public function test_partage_du_profil_masque_cibles_et_regime_aux_autres(): void
    {
        $owner = User::factory()->create();
        $household = $this->householdOwnedBy($owner);
        $member = $this->login($this->userWithProfile(['calories_cibles' => 1900, 'regime_alimentaire' => 'vegan']));
        $this->joinHousehold($member, $household);

        $this->putJson('/api/household/members/me', ['share_profile' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Ton profil n’est plus partagé avec le foyer.');

        $this->assertDatabaseHas('household_members', ['user_id' => $member->id, 'share_profile' => 0]);

        // Le membre voit toujours ses propres cibles…
        $mine = collect($this->getJson('/api/household')->json('data.members'))->firstWhere('user_id', $member->id);
        $this->assertFalse($mine['share_profile']);
        $this->assertSame(1900, $mine['calories_cibles']);
        $this->assertSame('vegan', $mine['regime']);

        // … mais le propriétaire ne les voit plus.
        $this->login($owner);
        $seen = collect($this->getJson('/api/household')->json('data.members'))->firstWhere('user_id', $member->id);
        $this->assertFalse($seen['share_profile']);
        $this->assertNull($seen['calories_cibles']);
        $this->assertNull($seen['regime']);
        $this->assertNull($seen['is_minor']);

        $this->login($member);
        $this->putJson('/api/household/members/me', ['share_profile' => 'oui'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['share_profile']);
    }

    // ------------------------------------------------------------------------------------
    // POST /household/transfer
    // ------------------------------------------------------------------------------------

    public function test_transfert_de_propriete(): void
    {
        $owner = $this->login(User::factory()->create());
        $household = $this->householdOwnedBy($owner);
        $member = User::factory()->create(['name' => 'Sam']);
        $this->joinHousehold($member, $household);

        $this->postJson('/api/household/transfer', ['user_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('message', 'Propriété du foyer transférée à Sam.')
            ->assertJsonPath('data.owner_id', $member->id)
            ->assertJsonPath('data.role', 'membre')
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.invite_code', null);

        $this->assertSame($member->id, (int) $household->fresh()->owner_id);
        $this->assertDatabaseHas('household_members', ['user_id' => $member->id, 'role' => 'proprietaire']);
        $this->assertDatabaseHas('household_members', ['user_id' => $owner->id, 'role' => 'membre']);
        $this->assertHouseholdInvariant();

        // L'ancien propriétaire peut maintenant quitter.
        $this->postJson('/api/household/leave')->assertOk();
        $this->assertHouseholdInvariant();
    }

    public function test_transfert_vers_un_non_membre_404_et_vers_soi_422(): void
    {
        $owner = $this->login(User::factory()->create());
        $this->householdOwnedBy($owner);
        $stranger = User::factory()->create();

        $this->postJson('/api/household/transfer', ['user_id' => $stranger->id])->assertStatus(404);
        $this->postJson('/api/household/transfer', ['user_id' => $owner->id])
            ->assertStatus(422)
            ->assertJsonPath('message', HouseholdService::MSG_TRANSFERT_SOI_MEME);
        $this->postJson('/api/household/transfer', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'Le champ nouveau propriétaire est obligatoire.');

        $this->assertHouseholdInvariant();
    }

    // ------------------------------------------------------------------------------------
    // Préfixe /api/v1
    // ------------------------------------------------------------------------------------

    public function test_routes_disponibles_sous_api_v1(): void
    {
        $owner = $this->login(User::factory()->create());
        $this->householdOwnedBy($owner);

        $this->getJson('/api/v1/household')->assertOk()->assertJsonPath('data.role', 'proprietaire');
    }
}
