<?php

namespace App\Enums;

/**
 * Offre d'un compte. Calquée sur UserRole, seul autre enum de ce genre.
 *
 * Aucune facturation n'existe encore : une offre se pose à la main, par une commande ou par un
 * code d'accès. Le jour où un paiement sera branché, c'est lui qui écrira cette colonne.
 */
enum Offre: string
{
    case Gratuit = 'gratuit';
    case Complet = 'complet';
    case Foyer = 'foyer';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Gratuit->value => 'Gratuit',
            self::Complet->value => 'Complet',
            self::Foyer->value => 'Foyer',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Capacités ouvertes par cette offre. */
    public function capacites(): array
    {
        return (array) config('offres.capacites.'.$this->value, []);
    }

    public function permet(string $capacite): bool
    {
        return in_array($capacite, $this->capacites(), true);
    }

    /** Rang, pour retenir la meilleure des deux offres entre un membre et son foyer. */
    public function rang(): int
    {
        return match ($this) {
            self::Gratuit => 0,
            self::Complet => 1,
            self::Foyer => 2,
        };
    }
}
