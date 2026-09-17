<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Seule Policy de l'application (brief §0.1) : les actions « propriétaire » du foyer.
 * Découverte automatiquement par Laravel (App\Models\Household → App\Policies\HouseholdPolicy),
 * donc `$this->authorize('update', $household)` renvoie 403 « Action non autorisée. » sans
 * enregistrement dans un provider.
 */
class HouseholdPolicy
{
    /** Voir le foyer : tout membre. */
    public function view(User $user, Household $household): bool
    {
        return $this->isMember($user, $household);
    }

    /** Renommer le foyer : propriétaire uniquement. */
    public function update(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    /** Régénérer le code d'invitation : propriétaire uniquement. */
    public function regenerateCode(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    /** Retirer un membre : propriétaire uniquement. */
    public function removeMember(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    /** Transférer la propriété : propriétaire uniquement. */
    public function transfer(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    /** Supprimer (dissoudre) le foyer : propriétaire uniquement. */
    public function delete(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    private function isMember(User $user, Household $household): bool
    {
        if ($household->isOwnedBy($user)) {
            return true;
        }

        if ($household->relationLoaded('members')) {
            return $household->getRelation('members')->contains(fn ($m) => (int) $m->user_id === (int) $user->id);
        }

        return $household->members()->where('user_id', $user->id)->exists();
    }
}
