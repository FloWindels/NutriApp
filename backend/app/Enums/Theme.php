<?php

namespace App\Enums;

enum Theme: string
{
    case Systeme = 'systeme';
    case Clair = 'clair';
    case Sombre = 'sombre';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Systeme->value => 'Selon le système',
            self::Clair->value => 'Clair',
            self::Sombre->value => 'Sombre',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
