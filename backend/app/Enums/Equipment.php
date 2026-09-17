<?php

namespace App\Enums;

enum Equipment: string
{
    case Aucun = 'aucun';
    case Halteres = 'halteres';
    case Barre = 'barre';
    case Kettlebell = 'kettlebell';
    case Elastiques = 'elastiques';
    case Banc = 'banc';
    case BarreTraction = 'barre_traction';
    case Machine = 'machine';
    case Tapis = 'tapis';
    case Velo = 'velo';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Aucun->value => 'Aucun matériel',
            self::Halteres->value => 'Haltères',
            self::Barre->value => 'Barre et disques',
            self::Kettlebell->value => 'Kettlebell',
            self::Elastiques->value => 'Élastiques',
            self::Banc->value => 'Banc',
            self::BarreTraction->value => 'Barre de traction',
            self::Machine->value => 'Machines guidées',
            self::Tapis->value => 'Tapis de course',
            self::Velo->value => 'Vélo d’appartement',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
