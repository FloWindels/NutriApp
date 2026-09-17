<?php

namespace App\Enums;

enum ExerciseCategory: string
{
    case Force = 'force';
    case Cardio = 'cardio';
    case Mobilite = 'mobilite';
    case Gainage = 'gainage';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Force->value => 'Renforcement',
            self::Cardio->value => 'Cardio',
            self::Mobilite->value => 'Mobilité',
            self::Gainage->value => 'Gainage',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
