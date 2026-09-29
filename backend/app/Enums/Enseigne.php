<?php

namespace App\Enums;

/**
 * Enseigne d'un magasin. Calquée sur Offre et UserRole, les autres enums « liste fermée ».
 *
 * Quatre enseignes seulement, et elles sont écrites ici en dur : ce ne sont pas des données
 * saisissables. Une enseigne de plus se décide, se documente et s'accompagne d'un assortiment ;
 * elle ne doit pas apparaître par accident au détour d'un import.
 */
enum Enseigne: string
{
    case Lidl = 'lidl';
    case Colruyt = 'colruyt';
    case Delhaize = 'delhaize';
    case Aldi = 'aldi';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Lidl->value => 'Lidl',
            self::Colruyt->value => 'Colruyt',
            self::Delhaize->value => 'Delhaize',
            self::Aldi->value => 'Aldi',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
