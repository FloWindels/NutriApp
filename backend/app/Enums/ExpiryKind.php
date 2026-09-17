<?php

namespace App\Enums;

enum ExpiryKind: string
{
    case Dlc = 'dlc';
    case Ddm = 'ddm';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Dlc->value => 'Date limite de consommation (DLC)',
            self::Ddm->value => 'Date de durabilité minimale (DDM)',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
