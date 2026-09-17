<?php

namespace App\Enums;

enum Intensity: string
{
    case Faible = 'faible';
    case Moderee = 'moderee';
    case Elevee = 'elevee';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Nom de la colonne MET correspondante sur la table `sports`.
     */
    public function metColumn(): string
    {
        return 'met_'.$this->value;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Faible->value => 'Faible',
            self::Moderee->value => 'Modérée',
            self::Elevee->value => 'Élevée',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
