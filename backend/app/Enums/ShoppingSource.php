<?php

namespace App\Enums;

enum ShoppingSource: string
{
    case Manuel = 'manuel';
    case AutoStock = 'auto_stock';
    case Planificateur = 'planificateur';
    case Recommandation = 'recommandation';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Manuel->value => 'Ajouté à la main',
            self::AutoStock->value => 'Stock épuisé',
            self::Planificateur->value => 'Planificateur',
            self::Recommandation->value => 'Recommandation',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
