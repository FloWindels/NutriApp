<?php

namespace App\Enums;

/**
 * Statut partagé par les plans de repas (meal_plans) et le calendrier sportif (sport_plans).
 */
enum PlanStatus: string
{
    case Prevu = 'prevu';
    case Realise = 'realise';
    case Annule = 'annule';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Prevu->value => 'Prévu',
            self::Realise->value => 'Réalisé',
            self::Annule->value => 'Annulé',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
