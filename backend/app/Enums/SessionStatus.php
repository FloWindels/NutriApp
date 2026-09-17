<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Prevue = 'prevue';
    case EnCours = 'en_cours';
    case Terminee = 'terminee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Prevue->value => 'Prévue',
            self::EnCours->value => 'En cours',
            self::Terminee->value => 'Terminée',
            self::Annulee->value => 'Annulée',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
