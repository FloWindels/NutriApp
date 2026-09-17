<?php

namespace App\Enums;

enum RecommendationStatus: string
{
    case Nouvelle = 'new';
    case Acceptee = 'acceptee';
    case Ignoree = 'ignoree';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Nouvelle->value => 'Nouvelle',
            self::Acceptee->value => 'Acceptée',
            self::Ignoree->value => 'Ignorée',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
