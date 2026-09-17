<?php

namespace App\Services\Household;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\MealPlan;
use App\Models\ShoppingItem;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie du foyer (brief §10). Chaque opération qui touche `household_members`
 * met à jour `users.household_id` (simple cache) dans la MÊME transaction : c'est le seul
 * endroit de l'application autorisé à écrire cette colonne (HouseholdInvariantTest).
 *
 * Portées déplacées avec l'utilisateur à la création / à l'adhésion : lieux de stock
 * personnels (fusion par LOWER(name)), articles de courses personnels et plans de repas
 * personnels. Le membre qui part ne reprend rien ; la dissolution rend tout au propriétaire.
 */
class HouseholdService
{
    public const MSG_DEJA_MEMBRE = 'Tu fais déjà partie d’un foyer.';
    public const MSG_AUCUN_FOYER = 'Tu ne fais partie d’aucun foyer.';
    public const MSG_CODE_INTROUVABLE = 'Code introuvable.';
    public const MSG_PROPRIETAIRE_QUITTE = 'Transfère la propriété du foyer ou supprime-le avant de le quitter.';
    public const MSG_RETIRER_SOI_MEME = 'Utilise « Quitter le foyer » pour te retirer toi-même.';
    public const MSG_TRANSFERT_SOI_MEME = 'Tu es déjà propriétaire de ce foyer.';
    public const MSG_TRANSFERT_NON_MEMBRE = 'Cette personne ne fait pas partie du foyer.';

    public const SUFFIXE_FOYER = ' (foyer)';

    private const MAX_CODE_ATTEMPTS = 20;

    // ------------------------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------------------------

    /**
     * Adhésion courante de l'utilisateur (avec le foyer chargé), ou null.
     */
    public function membershipOf(User $user): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->with('household')
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Foyer courant de l'utilisateur (source de vérité : household_members), ou null.
     */
    public function householdOf(User $user): ?Household
    {
        return $this->membershipOf($user)?->getRelation('household');
    }

    /**
     * Foyer correspondant à un code d'invitation (comparaison en majuscules), ou null.
     */
    public function findByInviteCode(string $code): ?Household
    {
        $normalized = self::normalizeCode($code);

        if (strlen($normalized) !== Household::INVITE_LENGTH) {
            return null;
        }

        return Household::query()->where('invite_code', $normalized)->first();
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    // ------------------------------------------------------------------------------------
    // Création / adhésion
    // ------------------------------------------------------------------------------------

    /**
     * Crée un foyer dont l'utilisateur devient propriétaire, et y rattache ses portées personnelles.
     *
     * @return array{household: Household, merged_stock_items: int}
     */
    public function create(User $user, string $name): array
    {
        $this->assertNotMember($user);

        return DB::transaction(function () use ($user, $name) {
            $household = Household::query()->create([
                'name' => trim($name),
                'owner_id' => $user->id,
                'invite_code' => $this->uniqueInviteCode(),
            ]);

            $this->addMember($household, $user, HouseholdRole::Proprietaire);
            $merged = $this->attachPersonalScopes($user, $household);

            return ['household' => $household, 'merged_stock_items' => $merged];
        });
    }

    /**
     * Rejoint un foyer par code d'invitation ; fusionne les stocks personnels dans ceux du foyer.
     *
     * @return array{household: Household, merged_stock_items: int}
     *
     * @throws ModelNotFoundException si le code est inconnu
     */
    public function join(User $user, string $inviteCode): array
    {
        $this->assertNotMember($user);

        $household = $this->findByInviteCode($inviteCode);
        if ($household === null) {
            throw (new ModelNotFoundException)->setModel(Household::class);
        }

        return DB::transaction(function () use ($user, $household) {
            // Verrou sur la ligne du foyer : deux adhésions simultanées se sérialisent.
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);

            $this->addMember($household, $user, HouseholdRole::Membre);
            $merged = $this->attachPersonalScopes($user, $household);

            return ['household' => $household, 'merged_stock_items' => $merged];
        });
    }

    // ------------------------------------------------------------------------------------
    // Départs
    // ------------------------------------------------------------------------------------

    /**
     * Quitte le foyer. Un propriétaire avec d'autres membres doit transférer ou dissoudre (422) ;
     * un propriétaire seul dissout le foyer. Le partant ne reprend rien.
     *
     * @return array{dissolved: bool}
     */
    public function leave(User $user): array
    {
        $membership = $this->membershipOf($user);
        if ($membership === null) {
            $this->fail('household', self::MSG_AUCUN_FOYER);
        }

        /** @var Household $household */
        $household = $membership->getRelation('household');

        if ($membership->isOwner()) {
            $others = $household->members()->where('user_id', '!=', $user->id)->exists();
            if ($others) {
                $this->fail('household', self::MSG_PROPRIETAIRE_QUITTE);
            }

            $this->dissolve($household);

            return ['dissolved' => true];
        }

        DB::transaction(function () use ($membership, $user) {
            $membership->delete();
            $this->syncUserCache($user, null);
        });

        return ['dissolved' => false];
    }

    /**
     * Retire un membre (action propriétaire). Le membre retiré ne reprend rien.
     *
     * @throws ModelNotFoundException si l'utilisateur n'est pas membre de ce foyer
     */
    public function removeMember(Household $household, User $target): void
    {
        if ($household->isOwnedBy($target)) {
            $this->fail('user', self::MSG_RETIRER_SOI_MEME);
        }

        $membership = $household->members()->where('user_id', $target->id)->firstOrFail();

        DB::transaction(function () use ($membership, $target) {
            $membership->delete();
            $this->syncUserCache($target, null);
        });
    }

    /**
     * Dissout le foyer : stocks, courses et plans reviennent au propriétaire (renommage
     * « (foyer) » en cas de doublon), toutes les adhésions sont supprimées, les caches remis à null.
     */
    public function dissolve(Household $household): void
    {
        DB::transaction(function () use ($household) {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);
            $ownerId = (int) $household->owner_id;

            $this->returnStocksToOwner($household, $ownerId);

            ShoppingItem::query()
                ->where('household_id', $household->id)
                ->update(['user_id' => $ownerId, 'household_id' => null]);

            MealPlan::query()
                ->where('household_id', $household->id)
                ->update(['user_id' => $ownerId, 'household_id' => null]);

            $memberIds = HouseholdMember::query()
                ->where('household_id', $household->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            HouseholdMember::query()->where('household_id', $household->id)->delete();

            if ($memberIds !== []) {
                User::query()->whereIn('id', $memberIds)->update(['household_id' => null]);
            }
            // Filet de sécurité : aucun utilisateur ne doit pointer vers un foyer disparu.
            User::query()->where('household_id', $household->id)->update(['household_id' => null]);

            $household->delete();
        });
    }

    /**
     * Transfère la propriété à un membre existant (les caches `users.household_id` ne changent pas).
     */
    public function transferOwnership(Household $household, User $newOwner): void
    {
        if ($household->isOwnedBy($newOwner)) {
            $this->fail('user_id', self::MSG_TRANSFERT_SOI_MEME);
        }

        DB::transaction(function () use ($household, $newOwner) {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);

            $target = HouseholdMember::query()
                ->where('household_id', $household->id)
                ->where('user_id', $newOwner->id)
                ->first();

            if ($target === null) {
                $this->fail('user_id', self::MSG_TRANSFERT_NON_MEMBRE);
            }

            HouseholdMember::query()
                ->where('household_id', $household->id)
                ->where('role', HouseholdRole::Proprietaire->value)
                ->update(['role' => HouseholdRole::Membre->value]);

            $target->update(['role' => HouseholdRole::Proprietaire->value]);
            $household->update(['owner_id' => $newOwner->id]);
        });
    }

    /**
     * Génère un nouveau code d'invitation (l'ancien devient immédiatement invalide).
     */
    public function regenerateCode(Household $household): Household
    {
        $household->update(['invite_code' => $this->uniqueInviteCode()]);

        return $household;
    }

    public function rename(Household $household, string $name): Household
    {
        $household->update(['name' => trim($name)]);

        return $household;
    }

    public function updateShareProfile(HouseholdMember $membership, bool $shareProfile): HouseholdMember
    {
        $membership->update(['share_profile' => $shareProfile]);

        return $membership;
    }

    // ------------------------------------------------------------------------------------
    // Fusion des portées personnelles
    // ------------------------------------------------------------------------------------

    /**
     * Rattache les lieux de stock personnels de l'utilisateur au foyer : même LOWER(name) déjà
     * présent → les articles sont déplacés et le lieu personnel supprimé ; sinon le lieu passe
     * au foyer (user_id NULL, household_id H). Retourne le nombre d'articles entrés dans le foyer.
     */
    public function attachPersonalStocks(User $user, Household $household): int
    {
        $personal = Stock::query()->personalOf($user)->get();
        if ($personal->isEmpty()) {
            return 0;
        }

        $shared = Stock::query()->ofHousehold($household->id)->get()
            ->keyBy(fn (Stock $s) => mb_strtolower($s->name));

        $moved = 0;

        foreach ($personal as $stock) {
            $key = mb_strtolower($stock->name);
            $existing = $shared->get($key);

            if ($existing !== null) {
                $moved += StockItem::query()->where('stock_id', $stock->id)->update(['stock_id' => $existing->id]);
                $stock->delete();

                continue;
            }

            $moved += StockItem::query()->where('stock_id', $stock->id)->count();
            $stock->update(['user_id' => null, 'household_id' => $household->id]);
            $shared->put($key, $stock);
        }

        return $moved;
    }

    /**
     * Stocks + courses + plans personnels → portée du foyer.
     */
    private function attachPersonalScopes(User $user, Household $household): int
    {
        $merged = $this->attachPersonalStocks($user, $household);

        ShoppingItem::query()
            ->where('user_id', $user->id)
            ->whereNull('household_id')
            ->update(['household_id' => $household->id]);

        MealPlan::query()
            ->where('user_id', $user->id)
            ->whereNull('household_id')
            ->update(['household_id' => $household->id]);

        return $merged;
    }

    /**
     * Les lieux du foyer redeviennent personnels au propriétaire ; en cas de doublon
     * (LOWER(name)) avec un lieu personnel existant, le lieu du foyer est renommé « … (foyer) ».
     */
    private function returnStocksToOwner(Household $household, int $ownerId): void
    {
        $taken = Stock::query()
            ->where('user_id', $ownerId)
            ->whereNull('household_id')
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower((string) $n))
            ->flip()
            ->all();

        $shared = Stock::query()->ofHousehold($household->id)->orderBy('id')->get();

        foreach ($shared as $stock) {
            $name = $stock->name;
            $key = mb_strtolower($name);

            while (isset($taken[$key])) {
                $name .= self::SUFFIXE_FOYER;
                $key = mb_strtolower($name);
            }

            $taken[$key] = true;
            $stock->update(['user_id' => $ownerId, 'household_id' => null, 'name' => $name]);
        }
    }

    // ------------------------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------------------------

    private function addMember(Household $household, User $user, HouseholdRole $role): HouseholdMember
    {
        $membership = HouseholdMember::query()->create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'share_profile' => true,
            'joined_at' => now(),
        ]);

        $this->syncUserCache($user, $household->id);

        return $membership;
    }

    /**
     * Écrit le cache `users.household_id` (colonne réservée à ce service) et synchronise l'instance.
     */
    private function syncUserCache(User $user, ?int $householdId): void
    {
        User::query()->whereKey($user->id)->update(['household_id' => $householdId]);
        $user->household_id = $householdId;
        $user->syncOriginalAttribute('household_id');
    }

    private function assertNotMember(User $user): void
    {
        if (HouseholdMember::query()->where('user_id', $user->id)->exists()) {
            $this->fail('household', self::MSG_DEJA_MEMBRE);
        }
    }

    private function uniqueInviteCode(): string
    {
        for ($i = 0; $i < self::MAX_CODE_ATTEMPTS; $i++) {
            $code = Household::generateInviteCode();
            if (! Household::query()->where('invite_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Impossible de générer un code d’invitation unique.');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
