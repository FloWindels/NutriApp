<?php

namespace App\Enums;

enum HouseholdRole: string
{
    case Proprietaire = 'proprietaire';
    case Membre = 'membre';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Proprietaire->value => 'Propriétaire',
            self::Membre->value => 'Membre',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
