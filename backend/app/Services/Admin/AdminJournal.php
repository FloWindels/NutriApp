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
    /** Auteur inscrit pour un geste fait en ligne de commande, sur le serveur. */
    public const AUTEUR_SYSTEME = 'système (console)';

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

    /**
     * Geste posé depuis le serveur (commande artisan) : aucun compte n'en est l'auteur, la ligne
     * le dit au lieu de l'attribuer à quelqu'un.
     */
    public function enregistrerSysteme(
        string $action,
        string $cibleType,
        ?int $cibleId,
        ?string $cibleLibelle,
        string $motif,
    ): AdminAction {
        return AdminAction::query()->create([
            'admin_id' => null,
            'admin_email' => self::AUTEUR_SYSTEME,
            'action' => $action,
            'cible_type' => $cibleType,
            'cible_id' => $cibleId,
            'cible_libelle' => $cibleLibelle !== null ? mb_substr($cibleLibelle, 0, 255) : null,
            'motif' => $motif,
        ]);
    }
}
