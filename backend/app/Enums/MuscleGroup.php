<?php

namespace App\Enums;

/**
 * Vocabulaire `exercises.muscle_group` (brief §13.1). Enum complémentaire, non imposée par le brief.
 */
enum MuscleGroup: string
{
    case Jambes = 'jambes';
    case Fessiers = 'fessiers';
    case Dos = 'dos';
    case Pectoraux = 'pectoraux';
    case Epaules = 'epaules';
    case Bras = 'bras';
    case Abdos = 'abdos';
    case CorpsEntier = 'corps_entier';
    case Cardio = 'cardio';
    case Mobilite = 'mobilite';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Jambes->value => 'Jambes',
            self::Fessiers->value => 'Fessiers',
            self::Dos->value => 'Dos',
            self::Pectoraux->value => 'Pectoraux',
            self::Epaules->value => 'Épaules',
            self::Bras->value => 'Bras',
            self::Abdos->value => 'Abdominaux',
            self::CorpsEntier->value => 'Corps entier',
            self::Cardio->value => 'Cardio',
            self::Mobilite->value => 'Mobilité',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
