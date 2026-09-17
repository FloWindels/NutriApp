<?php

namespace App\Enums;

enum Unit: string
{
    case Gramme = 'g';
    case Millilitre = 'ml';
    case Piece = 'piece';
    case Portion = 'portion';
    case CuillereSoupe = 'cas';
    case CuillereCafe = 'cac';
    case Verre = 'verre';
    case Bol = 'bol';
    case Assiette = 'assiette';
    case Poignee = 'poignee';
    case Tranche = 'tranche';

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public function labelShort(): string
    {
        return self::shortLabels()[$this->value];
    }

    /**
     * Pas de saisie conseillé pour cette unité (brief §3.3).
     */
    public function step(): float
    {
        return match ($this) {
            self::Gramme, self::Millilitre => 10.0,
            self::Piece, self::Portion, self::Tranche => 0.5,
            default => 1.0,
        };
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Gramme->value => 'gramme',
            self::Millilitre->value => 'millilitre',
            self::Piece->value => 'pièce',
            self::Portion->value => 'portion',
            self::CuillereSoupe->value => 'cuillère à soupe',
            self::CuillereCafe->value => 'cuillère à café',
            self::Verre->value => 'verre',
            self::Bol->value => 'bol',
            self::Assiette->value => 'assiette',
            self::Poignee->value => 'poignée',
            self::Tranche->value => 'tranche',
        ];
    }

    /** @return array<string, string> */
    public static function shortLabels(): array
    {
        return [
            self::Gramme->value => 'g',
            self::Millilitre->value => 'ml',
            self::Piece->value => 'pièce',
            self::Portion->value => 'portion',
            self::CuillereSoupe->value => 'c. à s.',
            self::CuillereCafe->value => 'c. à c.',
            self::Verre->value => 'verre',
            self::Bol->value => 'bol',
            self::Assiette->value => 'assiette',
            self::Poignee->value => 'poignée',
            self::Tranche->value => 'tranche',
        ];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
