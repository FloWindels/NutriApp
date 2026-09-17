<?php

namespace App\Enums;

enum CaloriesSource: string
{
    case Auto = 'auto';
    case Manuel = 'manuel';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Auto->value => 'Calculées automatiquement',
            self::Manuel->value => 'Saisies à la main',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
