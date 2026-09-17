<?php

namespace App\Enums;

enum Lieu: string
{
    case Maison = 'maison';
    case Exterieur = 'exterieur';
    case SallePublique = 'salle_publique';
    case SallePrivee = 'salle_privee';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Maison->value => 'Maison',
            self::Exterieur->value => 'Extérieur',
            self::SallePublique->value => 'Salle publique',
            self::SallePrivee->value => 'Salle privée',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
