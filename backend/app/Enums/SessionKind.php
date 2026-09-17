<?php

namespace App\Enums;

enum SessionKind: string
{
    case Seance = 'seance';
    case Activite = 'activite';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Seance->value => 'Séance structurée',
            self::Activite->value => 'Activité libre',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
