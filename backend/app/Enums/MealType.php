<?php

namespace App\Enums;

enum MealType: string
{
    case PetitDejeuner = 'petit_dejeuner';
    case Dejeuner = 'dejeuner';
    case Diner = 'diner';
    case Collation = 'collation';

    /**
     * Heure par défaut (HH:MM) utilisée quand le repas n'a pas d'heure de consommation.
     */
    public function defaultHour(): string
    {
        return match ($this) {
            self::PetitDejeuner => '08:00',
            self::Dejeuner => '12:30',
            self::Diner => '19:30',
            self::Collation => '16:30',
        };
    }

    /**
     * Part indicative du budget calorique quotidien.
     */
    public function share(): float
    {
        return match ($this) {
            self::PetitDejeuner => 0.25,
            self::Dejeuner => 0.35,
            self::Diner => 0.30,
            self::Collation => 0.10,
        };
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PetitDejeuner->value => 'Petit-déjeuner',
            self::Dejeuner->value => 'Déjeuner',
            self::Diner->value => 'Dîner',
            self::Collation->value => 'Collation',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
