<?php

namespace App\Support;

use App\Enums\Unit;
use App\Models\Food;
use Illuminate\Support\Str;

/**
 * Vocabulaire des unités de portion et conversion en grammes (brief §3.3).
 *
 * Unités canoniques (App\Enums\Unit) : g, ml, piece, portion, cas, cac, verre, bol,
 * assiette, poignee, tranche. Les alias (unité, pièce, pc, cl, l, kg, cs, cc…) sont
 * normalisés avant toute recherche ; cl/l/kg portent un facteur multiplicatif.
 */
final class Portions
{
    /** Grammes des mesures ménagères fixes. */
    public const HOUSEHOLD_GRAMS = [
        'cas' => 15.0,
        'cac' => 5.0,
        'verre' => 200.0,
        'bol' => 300.0,
        'assiette' => 350.0,
        'poignee' => 30.0,
        'tranche' => 30.0,
    ];

    /** Poids par défaut quand rien n'est connu pour une pièce/portion. */
    public const DEFAULT_PIECE_GRAMS = 100.0;

    /** Pas conseillé au client par unité. */
    private const STEPS = [
        'g' => 10,
        'ml' => 10,
        'piece' => 0.5,
        'portion' => 0.5,
        'tranche' => 0.5,
    ];

    /** Libellés français (long / court). */
    private const LABELS = [
        'g' => ['gramme', 'g'],
        'ml' => ['millilitre', 'ml'],
        'piece' => ['pièce', 'pièce'],
        'portion' => ['portion', 'portion'],
        'cas' => ['cuillère à soupe', 'c. à s.'],
        'cac' => ['cuillère à café', 'c. à c.'],
        'verre' => ['verre', 'verre'],
        'bol' => ['bol', 'bol'],
        'assiette' => ['assiette', 'assiette'],
        'poignee' => ['poignée', 'poignée'],
        'tranche' => ['tranche', 'tranche'],
    ];

    /**
     * Alias → [unité canonique, facteur]. Les clés sont comparées après
     * normalisation (minuscules, accents retirés, espaces/points/tirets retirés).
     */
    private const ALIASES = [
        // grammes
        'g' => ['g', 1.0], 'gr' => ['g', 1.0], 'gramme' => ['g', 1.0], 'grammes' => ['g', 1.0],
        'kg' => ['g', 1000.0], 'kilo' => ['g', 1000.0], 'kilogramme' => ['g', 1000.0], 'kilogrammes' => ['g', 1000.0],
        'mg' => ['g', 0.001],
        // millilitres
        'ml' => ['ml', 1.0], 'millilitre' => ['ml', 1.0], 'millilitres' => ['ml', 1.0],
        'cl' => ['ml', 10.0], 'centilitre' => ['ml', 10.0], 'centilitres' => ['ml', 10.0],
        'dl' => ['ml', 100.0],
        'l' => ['ml', 1000.0], 'litre' => ['ml', 1000.0], 'litres' => ['ml', 1000.0],
        // pièce
        'piece' => ['piece', 1.0], 'pieces' => ['piece', 1.0], 'pc' => ['piece', 1.0], 'pcs' => ['piece', 1.0],
        'unite' => ['piece', 1.0], 'unites' => ['piece', 1.0], 'u' => ['piece', 1.0],
        // portion
        'portion' => ['portion', 1.0], 'portions' => ['portion', 1.0], 'part' => ['portion', 1.0], 'parts' => ['portion', 1.0],
        // cuillère à soupe
        'cas' => ['cas', 1.0], 'cs' => ['cas', 1.0], 'cuilleresoupe' => ['cas', 1.0], 'cuillereasoupe' => ['cas', 1.0],
        'cuilleressoupe' => ['cas', 1.0], 'cuilleresasoupe' => ['cas', 1.0], 'tbsp' => ['cas', 1.0],
        // cuillère à café
        'cac' => ['cac', 1.0], 'cc' => ['cac', 1.0], 'cuillerecafe' => ['cac', 1.0], 'cuillereacafe' => ['cac', 1.0],
        'cuillerescafe' => ['cac', 1.0], 'cuilleresacafe' => ['cac', 1.0], 'tsp' => ['cac', 1.0],
        // mesures ménagères
        'verre' => ['verre', 1.0], 'verres' => ['verre', 1.0],
        'bol' => ['bol', 1.0], 'bols' => ['bol', 1.0],
        'assiette' => ['assiette', 1.0], 'assiettes' => ['assiette', 1.0],
        'poignee' => ['poignee', 1.0], 'poignees' => ['poignee', 1.0],
        'tranche' => ['tranche', 1.0], 'tranches' => ['tranche', 1.0],
    ];

    /**
     * Normalise une unité saisie : retourne [unité canonique|null, facteur].
     * Ex. « cl » → ['ml', 10], « Pièce » → ['piece', 1], « c. à s. » → ['cas', 1].
     *
     * @return array{0: string|null, 1: float}
     */
    public static function normalize(?string $unit): array
    {
        $key = self::key($unit);

        if ($key === '') {
            return [null, 1.0];
        }

        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        return [null, 1.0];
    }

    /**
     * Unité canonique (ou null si inconnue), sans facteur.
     */
    public static function canonical(?string $unit): ?string
    {
        return self::normalize($unit)[0];
    }

    /**
     * Enum Unit correspondant à une saisie (null si inconnue).
     */
    public static function unit(?string $unit): ?Unit
    {
        $canonical = self::canonical($unit);

        return $canonical === null ? null : Unit::tryFrom($canonical);
    }

    /**
     * Vrai si l'unité (ou l'un de ses alias) est reconnue.
     */
    public static function isKnown(?string $unit): bool
    {
        return self::canonical($unit) !== null;
    }

    /**
     * Convertit une quantité dans une unité en grammes.
     * Toute conversion autre que g/ml (ou ml avec densité connue) est une estimation.
     */
    public static function toGrams(float $qty, string $unit, ?Food $food = null): Conversion
    {
        [$canonical, $factor] = self::normalize($unit);
        $qtyCanonical = $qty * $factor;

        if ($canonical === null) {
            return new Conversion(
                grams: null,
                is_estimate: true,
                confidence: Conversion::CONFIDENCE_INCONNUE,
                note: 'Unité inconnue : conversion impossible.',
                unit: $unit,
                quantity: $qty,
            );
        }

        switch ($canonical) {
            case 'g':
                return new Conversion(
                    grams: round($qtyCanonical, 2),
                    is_estimate: false,
                    confidence: Conversion::CONFIDENCE_EXACTE,
                    note: null,
                    unit: 'g',
                    quantity: $qtyCanonical,
                );

            case 'ml':
                $density = self::density($food);
                if ($density !== null) {
                    return new Conversion(
                        grams: round($qtyCanonical * $density, 2),
                        is_estimate: false,
                        confidence: Conversion::CONFIDENCE_BONNE,
                        note: 'Densité connue : '.self::fr($density).' g/ml.',
                        unit: 'ml',
                        quantity: $qtyCanonical,
                    );
                }

                return new Conversion(
                    grams: round($qtyCanonical, 2),
                    is_estimate: true,
                    confidence: Conversion::CONFIDENCE_MOYENNE,
                    note: 'Densité inconnue : 1 ml ≈ 1 g.',
                    unit: 'ml',
                    quantity: $qtyCanonical,
                );

            case 'piece':
            case 'portion':
                return self::pieceToGrams($qtyCanonical, $canonical, $food);

            default:
                $grams = self::HOUSEHOLD_GRAMS[$canonical];

                return new Conversion(
                    grams: round($qtyCanonical * $grams, 2),
                    is_estimate: true,
                    confidence: Conversion::CONFIDENCE_MOYENNE,
                    note: sprintf('1 %s ≈ %s g (mesure ménagère).', self::LABELS[$canonical][0], self::fr($grams)),
                    unit: $canonical,
                    quantity: $qtyCanonical,
                );
        }
    }

    /**
     * Grammes d'une pièce/portion pour un aliment (serving_size_g > catégorie > 100 g).
     *
     * @return array{0: float, 1: string, 2: string} [grammes, confiance, note]
     */
    public static function pieceGrams(?Food $food): array
    {
        $serving = $food?->serving_size_g;
        if ($serving !== null && is_numeric($serving) && (float) $serving > 0) {
            return [(float) $serving, Conversion::CONFIDENCE_BONNE, 'Portion indiquée sur le produit : '.self::fr((float) $serving).' g.'];
        }

        $default = self::defaultFor($food);
        if ($default !== null) {
            [$grams, $match] = $default;

            return [$grams, Conversion::CONFIDENCE_MOYENNE, sprintf('Portion type « %s » : %s g.', $match, self::fr($grams))];
        }

        return [self::DEFAULT_PIECE_GRAMS, Conversion::CONFIDENCE_FAIBLE, 'Poids inconnu : 100 g par pièce (estimation).'];
    }

    /**
     * Catalogue pour GET /portions : {unit, label, label_short, grams|null, step, is_estimate}.
     *
     * @return array<int, array{unit: string, label: string, label_short: string, grams: float|null, step: float|int, is_estimate: bool}>
     */
    public static function catalog(): array
    {
        $rows = [];

        foreach (Unit::cases() as $unit) {
            $value = $unit->value;
            [$label, $short] = self::LABELS[$value] ?? [$value, $value];

            $grams = match ($value) {
                'g' => 1.0,
                'ml' => 1.0,
                'piece', 'portion' => null,
                default => self::HOUSEHOLD_GRAMS[$value] ?? null,
            };

            $rows[] = [
                'unit' => $value,
                'label' => $label,
                'label_short' => $short,
                'grams' => $grams,
                'step' => self::STEPS[$value] ?? 1,
                'is_estimate' => ! in_array($value, ['g', 'ml'], true),
            ];
        }

        return $rows;
    }

    /**
     * Alias exposés au client (saisie libre → unité canonique). Les alias portant
     * un facteur (cl, l, kg…) sont exposés sous la forme « ml×10 ».
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        $out = [
            'unite' => 'piece', 'unité' => 'piece', 'pièce' => 'piece', 'pc' => 'piece',
            'cl' => 'ml×10', 'l' => 'ml×1000', 'kg' => 'g×1000',
            'c. à s.' => 'cas', 'cs' => 'cas', 'cuillere_soupe' => 'cas', 'cuillère à soupe' => 'cas',
            'c. à c.' => 'cac', 'cc' => 'cac', 'cuillere_cafe' => 'cac', 'cuillère à café' => 'cac',
            'part' => 'portion', 'poignée' => 'poignee',
        ];

        return $out;
    }

    /**
     * Libellé long d'une unité canonique (ex. « cuillère à soupe »).
     */
    public static function label(string $unit, bool $short = false): string
    {
        $canonical = self::canonical($unit) ?? $unit;
        $labels = self::LABELS[$canonical] ?? [$unit, $unit];

        return $labels[$short ? 1 : 0];
    }

    // ------------------------------------------------------------------------------------

    private static function pieceToGrams(float $qty, string $canonical, ?Food $food): Conversion
    {
        [$grams, $confidence, $note] = self::pieceGrams($food);

        return new Conversion(
            grams: round($qty * $grams, 2),
            is_estimate: true,
            confidence: $confidence,
            note: $note,
            unit: $canonical,
            quantity: $qty,
        );
    }

    /**
     * Cherche une catégorie par défaut (config/portion_defaults.php) dans la catégorie
     * puis dans le nom de l'aliment. Retourne [grammes, clé trouvée] ou null.
     *
     * @return array{0: float, 1: string}|null
     */
    private static function defaultFor(?Food $food): ?array
    {
        if ($food === null) {
            return null;
        }

        /** @var array<string, int|float> $defaults */
        $defaults = config('portion_defaults', []);
        if ($defaults === []) {
            return null;
        }

        $haystacks = array_filter([
            self::fold((string) ($food->category ?? '')),
            self::fold((string) ($food->name ?? '')),
        ]);

        foreach ($haystacks as $haystack) {
            // Les clés les plus longues d'abord (« pomme de terre » avant « pomme »).
            $keys = array_keys($defaults);
            usort($keys, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));

            foreach ($keys as $key) {
                $folded = self::fold((string) $key);
                if ($folded !== '' && str_contains($haystack, $folded)) {
                    return [(float) $defaults[$key], (string) $key];
                }
            }
        }

        return null;
    }

    private static function density(?Food $food): ?float
    {
        $density = $food?->density_g_per_ml;

        if ($density === null || ! is_numeric($density) || (float) $density <= 0) {
            return null;
        }

        return (float) $density;
    }

    /**
     * Clé de recherche d'alias : minuscules, sans accents, sans espaces/points/tirets/underscores.
     */
    private static function key(?string $unit): string
    {
        if ($unit === null) {
            return '';
        }

        $folded = self::fold($unit);

        return preg_replace('/[\s\.\-_\'’]+/u', '', $folded) ?? '';
    }

    /**
     * Minuscules + accents retirés (« Pièce » → « piece »).
     */
    private static function fold(string $value): string
    {
        return mb_strtolower(trim(Str::ascii($value)));
    }

    private static function fr(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');

        return $formatted === '' ? '0' : $formatted;
    }
}
