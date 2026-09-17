<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Portée « foyer ou personnelle » partagée par la liste de courses et le planificateur
 * (tables shopping_items / meal_plans : user_id + household_id sans FK).
 *
 * - Membre d'un foyer : toutes les lignes du foyer (household_id = H), quel que soit l'auteur.
 * - Sans foyer       : ses lignes personnelles (user_id = U et household_id NULL).
 */
final class OwnerScope
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->household_id) {
            return $query->where('household_id', $user->household_id);
        }

        return $query->where('user_id', $user->id)->whereNull('household_id');
    }

    /**
     * Attributs à poser sur une nouvelle ligne : l'auteur reste renseigné (meal_plans.user_id
     * est obligatoire), le foyer est renseigné si l'utilisateur en a un.
     *
     * @return array{user_id: int, household_id: int|null}
     */
    public static function ownerAttributes(User $user): array
    {
        return [
            'user_id' => (int) $user->id,
            'household_id' => $user->household_id ? (int) $user->household_id : null,
        ];
    }
}
