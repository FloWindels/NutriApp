<?php

namespace App\Services\Admin;

use App\Models\AdminAction;
use App\Models\User;

/**
 * Consigne chaque geste d'administration.
 *
 * L'adresse de l'administrateur est recopiée dans la ligne : si son compte disparaît, la trace
 * doit rester lisible. Le motif est obligatoire — une action sans raison écrite n'est pas
 * défendable devant la personne concernée.
 */
class AdminJournal
{
    public function enregistrer(
        User $admin,
        string $action,
        string $cibleType,
        ?int $cibleId,
        ?string $cibleLibelle,
        string $motif,
    ): AdminAction {
        return AdminAction::query()->create([
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'action' => $action,
            'cible_type' => $cibleType,
            'cible_id' => $cibleId,
            'cible_libelle' => $cibleLibelle !== null ? mb_substr($cibleLibelle, 0, 255) : null,
            'motif' => $motif,
        ]);
    }
}
