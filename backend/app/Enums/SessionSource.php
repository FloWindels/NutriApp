<?php

namespace App\Enums;

enum SessionSource: string
{
    case Generee = 'generee';
    case Manuelle = 'manuelle';
    case Catalogue = 'catalogue';
    case Activite = 'activite';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Generee->value => 'Générée',
            self::Manuelle->value => 'Créée à la main',
            self::Catalogue->value => 'Depuis le catalogue',
            self::Activite->value => 'Activité enregistrée',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
