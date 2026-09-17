<?php

namespace App\Enums;

enum ExerciseLevel: string
{
    case Debutant = 'debutant';
    case Intermediaire = 'intermediaire';
    case Avance = 'avance';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Rang numérique pour comparer les niveaux (débutant < intermédiaire < avancé).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Debutant => 1,
            self::Intermediaire => 2,
            self::Avance => 3,
        };
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Debutant->value => 'Débutant',
            self::Intermediaire->value => 'Intermédiaire',
            self::Avance->value => 'Avancé',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
