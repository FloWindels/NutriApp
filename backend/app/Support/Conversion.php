<?php

namespace App\Support;

/**
 * Résultat d'une conversion de portion en grammes (App\Support\Portions::toGrams).
 *
 * - grams       : masse équivalente, null si l'unité est inconnue ;
 * - is_estimate : vrai pour toute conversion autre que g/ml (ou ml sans densité connue) ;
 * - confidence  : exacte | bonne | moyenne | faible | inconnue ;
 * - note        : explication courte en français (affichable).
 */
final class Conversion
{
    public const CONFIDENCE_EXACTE = 'exacte';
    public const CONFIDENCE_BONNE = 'bonne';
    public const CONFIDENCE_MOYENNE = 'moyenne';
    public const CONFIDENCE_FAIBLE = 'faible';
    public const CONFIDENCE_INCONNUE = 'inconnue';

    public function __construct(
        public readonly ?float $grams,
        public readonly bool $is_estimate,
        public readonly string $confidence,
        public readonly ?string $note = null,
        public readonly string $unit = 'g',
        public readonly float $quantity = 0.0,
    ) {
    }

    public function isConvertible(): bool
    {
        return $this->grams !== null;
    }

    /**
     * @return array{grams: float|null, is_estimate: bool, confidence: string, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'grams' => $this->grams,
            'is_estimate' => $this->is_estimate,
            'confidence' => $this->confidence,
            'note' => $this->note,
        ];
    }
}
