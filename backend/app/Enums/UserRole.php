<?php

namespace App\Enums;

/**
 * Rôle d'un compte. Calqué sur HouseholdRole, seul autre rôle du projet.
 *
 * Une colonne plutôt qu'un booléen : le jour où un modérateur non administrateur est nécessaire,
 * il suffit d'ajouter un cas, sans transformer chaque condition en `is_admin || is_moderateur`.
 */
enum UserRole: string
{
    case Utilisateur = 'utilisateur';
    case Administrateur = 'administrateur';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Utilisateur->value => 'Utilisateur',
            self::Administrateur->value => 'Administrateur',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
