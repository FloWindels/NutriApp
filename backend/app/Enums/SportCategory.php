<?php

namespace App\Enums;

enum SportCategory: string
{
    case Endurance = 'endurance';
    case Force = 'force';
    case Collectif = 'collectif';
    case Raquette = 'raquette';
    case Aquatique = 'aquatique';
    case Combat = 'combat';
    case BienEtre = 'bien_etre';
    case Glisse = 'glisse';
    case Autre = 'autre';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * MET modéré par défaut quand un sport personnalisé n'en précise pas (addendum §C.1).
     */
    public function defaultMet(): float
    {
        return match ($this) {
            self::Endurance => 7.0,
            self::Force => 5.0,
            self::Collectif => 7.0,
            self::Raquette => 6.0,
            self::Aquatique => 7.0,
            self::Combat => 8.0,
            self::BienEtre => 3.0,
            self::Glisse => 6.0,
            self::Autre => 5.0,
        };
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Endurance->value => 'Endurance',
            self::Force->value => 'Force et musculation',
            self::Collectif->value => 'Sport collectif',
            self::Raquette->value => 'Sport de raquette',
            self::Aquatique->value => 'Sport aquatique',
            self::Combat->value => 'Sport de combat',
            self::BienEtre->value => 'Bien-être',
            self::Glisse->value => 'Glisse',
            self::Autre->value => 'Autre',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
