<?php

namespace App\Services\Account;

use App\Enums\HouseholdRole;
use App\Models\DailyTarget;
use App\Models\Food;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\NotificationRead;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Recommendation;
use App\Models\ShoppingItem;
use App\Models\Sport;
use App\Models\SportPlan;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WeightLog;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Suppression de compte (brief §1) : tout est supprimé explicitement dans une transaction,
 * sans compter sur les cascades de la base (absentes sur SQLite pour les colonnes ajoutées).
 *
 * Foyer : propriétaire avec d'autres membres → transfert au membre le plus ancien (joined_at) ;
 * propriétaire seul → dissolution ; simple membre → départ. Les lignes de foyer rédigées par
 * l'utilisateur (plans de repas, courses) sont réattribuées au propriétaire : « le partant
 * n'emporte rien ». Les recettes publiques et les aliments sont conservés, créateur mis à null.
 */
class AccountDeletionService
{
    public const MSG_MOT_DE_PASSE = 'Mot de passe incorrect.';

    /**
     * @throws ValidationException quand le mot de passe ne correspond pas (422 sur `password`).
     */
    public function delete(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => [self::MSG_MOT_DE_PASSE],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $this->handleHouseholds($user);
            $this->deletePersonalData($user);

            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            $user->delete();
        });
    }

    // ------------------------------------------------------------------------------------
    // Foyer
    // ------------------------------------------------------------------------------------

    private function handleHouseholds(User $user): void
    {
        $membership = HouseholdMember::query()->where('user_id', $user->id)->first();

        if ($membership) {
            $household = Household::query()->find($membership->household_id);

            if ($household === null) {
                $membership->delete();
            } elseif ((int) $household->owner_id === (int) $user->id) {
                $this->leaveAsOwner($user, $household, $membership);
            } else {
                $this->leaveAsMember($user, $household, $membership);
            }
        }

        // Filet de sécurité : foyers possédés sans adhésion (état incohérent) — la FK
        // households.owner_id est restrictOnDelete, il faut les traiter avant de supprimer.
        Household::query()->where('owner_id', $user->id)->get()->each(function (Household $household) use ($user): void {
            $this->leaveAsOwner($user, $household, null);
        });
    }

    private function leaveAsOwner(User $user, Household $household, ?HouseholdMember $membership): void
    {
        $next = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', '!=', $user->id)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();

        if ($next) {
            // Transfert de propriété au membre le plus ancien.
            $household->update(['owner_id' => $next->user_id]);
            $next->update(['role' => HouseholdRole::Proprietaire->value]);
            $this->reassignAuthoredHouseholdRows($user, $household, (int) $next->user_id);
            $membership?->delete();

            return;
        }

        // Propriétaire seul : dissolution. Les stocks / courses / plans du foyer reviendraient au
        // propriétaire… qui est supprimé juste après : on les supprime directement (même résultat,
        // sans risquer l'index unique (user_id, LOWER(name)) des lieux personnels).
        $stockIds = Stock::query()->where('household_id', $household->id)->pluck('id');
        StockItem::query()->whereIn('stock_id', $stockIds)->delete();
        Stock::query()->whereIn('id', $stockIds)->delete();
        ShoppingItem::query()->where('household_id', $household->id)->delete();
        MealPlan::query()->where('household_id', $household->id)->delete();

        HouseholdMember::query()->where('household_id', $household->id)->delete();
        $household->delete();
    }

    private function leaveAsMember(User $user, Household $household, HouseholdMember $membership): void
    {
        $this->reassignAuthoredHouseholdRows($user, $household, (int) $household->owner_id);
        $membership->delete();
    }

    /**
     * Les lignes de foyer rédigées par l'utilisateur restent au foyer (réattribuées au propriétaire) :
     * meal_plans.user_id est NOT NULL avec cascade, shopping_items.user_id est informatif.
     */
    private function reassignAuthoredHouseholdRows(User $user, Household $household, int $newOwnerId): void
    {
        MealPlan::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->update(['user_id' => $newOwnerId]);

        ShoppingItem::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->update(['user_id' => $newOwnerId]);
    }

    // ------------------------------------------------------------------------------------
    // Données personnelles
    // ------------------------------------------------------------------------------------

    private function deletePersonalData(User $user): void
    {
        $userId = (int) $user->id;

        // Recettes : privées supprimées, publiques conservées (créateur anonymisé).
        $privateRecipeIds = Recipe::query()
            ->where('created_by_user_id', $userId)
            ->where('is_public', false)
            ->pluck('id');
        MealPlan::query()->whereIn('recipe_id', $privateRecipeIds)->update(['recipe_id' => null]);
        MealItem::query()->whereIn('recipe_id', $privateRecipeIds)->update(['recipe_id' => null]);
        Recipe::query()->whereIn('id', $privateRecipeIds)->delete();
        Recipe::query()->where('created_by_user_id', $userId)->update(['created_by_user_id' => null]);

        // Aliments : toujours conservés (base commune), créateur anonymisé ; favoris supprimés.
        Food::query()->where('created_by_user_id', $userId)->update(['created_by_user_id' => null]);
        DB::table('food_favorites')->where('user_id', $userId)->delete();

        // Stocks personnels + articles.
        $stockIds = Stock::query()->where('user_id', $userId)->whereNull('household_id')->pluck('id');
        $stockItemIds = StockItem::query()->whereIn('stock_id', $stockIds)->pluck('id');
        MealItem::query()->whereIn('stock_item_id', $stockItemIds)->update(['stock_item_id' => null]);
        StockItem::query()->whereIn('id', $stockItemIds)->delete();
        Stock::query()->whereIn('id', $stockIds)->delete();

        // Profil, poids, cibles du jour.
        Profile::query()->where('user_id', $userId)->delete();
        WeightLog::query()->where('user_id', $userId)->delete();
        DailyTarget::query()->where('user_id', $userId)->delete();

        // Repas (+ items) ; les plans de repas d'autres membres pointant sur ces repas sont détachés.
        $mealIds = Meal::query()->where('user_id', $userId)->pluck('id');
        MealItem::query()->whereIn('meal_id', $mealIds)->delete();
        MealPlan::query()->whereIn('meal_id', $mealIds)->update(['meal_id' => null]);
        Meal::query()->whereIn('id', $mealIds)->delete();

        // Sport : séances (+ exercices), calendrier, sports personnalisés.
        $sessionIds = WorkoutSession::query()->where('user_id', $userId)->pluck('id');
        WorkoutExercise::query()->whereIn('session_id', $sessionIds)->delete();
        SportPlan::query()->where('user_id', $userId)->delete();
        WorkoutSession::query()->whereIn('id', $sessionIds)->delete();
        Sport::query()->where('created_by_user_id', $userId)->where('is_public', false)->delete();
        Sport::query()->where('created_by_user_id', $userId)->update(['created_by_user_id' => null]);

        // Coach, paramètres, notifications.
        Recommendation::query()->where('user_id', $userId)->delete();
        UserSetting::query()->where('user_id', $userId)->delete();
        NotificationRead::query()->where('user_id', $userId)->delete();

        // Courses et planificateur personnels (les lignes de foyer ont été réattribuées plus haut).
        ShoppingItem::query()->where('user_id', $userId)->whereNull('household_id')->delete();
        MealPlan::query()->where('user_id', $userId)->whereNull('household_id')->delete();

        // Dernier filet : aucune ligne planificateur ne doit plus référencer l'utilisateur
        // (meal_plans.user_id est NOT NULL) — celles d'un foyer inconnu sont supprimées.
        MealPlan::query()->where('user_id', $userId)->delete();
        ShoppingItem::query()->where('user_id', $userId)->update(['user_id' => null]);
    }
}
