<?php

namespace App\Services\Planner;

use App\Models\Profile;
use Illuminate\Support\Str;

/**
 * Filtre léger de compatibilité d'une recette / idée de repas avec le profil (brief §12).
 *
 * Volontairement indépendant de DietEvaluator (module M9) : on ne regarde que le titre,
 * les tags et les libellés d'ingrédients, avec des mots-clés accent-foldés.
 *  - régime : exclusion par mots-clés sauf si un tag « garant » est présent (ex. tag vegan) ;
 *  - allergènes / aliments exclus : exclusion par mots-clés, sans échappatoire ;
 *  - mineurs : jamais de recette taguée keto ou low_carb.
 */
final class CompatibilityFilter
{
    private const MEAT = [
        'viande', 'poulet', 'boeuf', 'porc', 'dinde', 'jambon', 'steak', 'lard', 'bacon', 'chorizo',
        'saucisse', 'saucisson', 'merguez', 'agneau', 'veau', 'canard', 'gelatine', 'lardon',
    ];

    private const FISH = [
        'poisson', 'saumon', 'thon', 'cabillaud', 'crevette', 'sardine', 'maquereau', 'truite',
        'colin', 'dorade', 'moule', 'fruits de mer', 'calamar', 'anchois',
    ];

    private const ANIMAL_OTHER = [
        'oeuf', 'omelette', 'lait', 'fromage', 'beurre', 'miel', 'yaourt', 'feta', 'chevre',
        'creme', 'mozzarella', 'parmesan', 'emmental', 'fromage blanc', 'ricotta',
    ];

    private const GLUTEN = [
        'ble', 'seigle', 'orge', 'epeautre', 'pain', 'pates', 'boulgour', 'semoule', 'couscous',
        'wrap', 'tartine', 'muesli', 'pizza', 'brioche', 'biscotte', 'croissant', 'gnocchi',
    ];

    private const LACTOSE = [
        'lait', 'lactose', 'creme', 'beurre', 'fromage', 'yaourt', 'feta', 'chevre', 'mozzarella',
        'parmesan', 'emmental', 'fromage blanc', 'ricotta',
    ];

    private const HALAL = [
        'porc', 'jambon', 'lard', 'lardon', 'bacon', 'chorizo', 'saucisson', 'alcool', 'vin', 'biere',
    ];

    private const HIGH_CARB = [
        'riz', 'pates', 'pain', 'pomme de terre', 'pommes de terre', 'patate', 'quinoa', 'boulgour',
        'nouilles', 'flocons', 'muesli', 'banane', 'tartine', 'wrap', 'semoule', 'couscous',
        'porridge', 'sucre', 'confiture', 'frites', 'gnocchi',
    ];

    private const MONTIGNAC = [
        'sucre', 'pain blanc', 'baguette', 'pomme de terre', 'pommes de terre', 'frites', 'riz blanc',
        'farine blanche', 'soda', 'confiture',
    ];

    /** Tags interdits aux mineurs (brief §8 : jamais de recette keto/low_carb). */
    private const MINOR_FORBIDDEN_TAGS = ['keto', 'low_carb'];

    /**
     * Contexte de filtrage construit depuis le profil (tout est optionnel).
     *
     * @return array{regime: string|null, exclusions: list<string>, is_minor: bool}
     */
    public static function contextFor(?Profile $profile): array
    {
        if ($profile === null) {
            return ['regime' => null, 'exclusions' => [], 'is_minor' => false];
        }

        $exclusions = [];
        foreach ([$profile->allergenes, $profile->aliments_exclus] as $list) {
            foreach (is_array($list) ? $list : [] as $entry) {
                $entry = trim((string) $entry);
                if ($entry !== '') {
                    $exclusions[] = $entry;
                }
            }
        }

        return [
            'regime' => $profile->regime_alimentaire ?: null,
            'exclusions' => array_values(array_unique($exclusions)),
            'is_minor' => $profile->isMinor(),
        ];
    }

    /**
     * Vrai si le plat (titre, tags, libellés d'ingrédients) est compatible avec le contexte.
     *
     * @param  array{regime: string|null, exclusions: list<string>, is_minor: bool}  $context
     * @param  list<string>  $tags
     * @param  list<string>  $ingredientNames
     */
    public static function isCompatible(array $context, string $title, array $tags, array $ingredientNames = []): bool
    {
        $tags = array_map(fn ($t) => (string) $t, $tags);

        if ($context['is_minor'] && array_intersect($tags, self::MINOR_FORBIDDEN_TAGS) !== []) {
            return false;
        }

        $haystack = self::fold(implode(' | ', array_merge([$title], $ingredientNames)));

        foreach ($context['exclusions'] as $exclusion) {
            if (self::matches($haystack, self::fold($exclusion))) {
                return false;
            }
        }

        [$keywords, $guarantors] = self::rulesFor($context['regime']);
        if ($keywords === [] || array_intersect($tags, $guarantors) !== []) {
            return true;
        }

        foreach ($keywords as $keyword) {
            if (self::matches($haystack, $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Minuscules, accents retirés, espaces normalisés.
     */
    public static function fold(string $value): string
    {
        $folded = mb_strtolower(trim(Str::ascii($value)));

        return preg_replace('/\s+/u', ' ', $folded) ?? $folded;
    }

    /**
     * Mots-clés d'exclusion et tags « garants » par régime.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function rulesFor(?string $regime): array
    {
        return match ($regime) {
            'vegan' => [array_merge(self::MEAT, self::FISH, self::ANIMAL_OTHER), ['vegan']],
            'vegetarien' => [array_merge(self::MEAT, self::FISH), ['vegetarien', 'vegan']],
            'sans_gluten' => [self::GLUTEN, ['sans_gluten']],
            'sans_lactose' => [self::LACTOSE, ['sans_lactose', 'vegan']],
            'halal' => [self::HALAL, ['halal']],
            'keto' => [self::HIGH_CARB, ['keto']],
            'low_carb' => [self::HIGH_CARB, ['low_carb', 'keto']],
            'montignac' => [self::MONTIGNAC, ['montignac']],
            default => [[], []],
        };
    }

    /**
     * Le mot-clé (déjà foldé) apparaît-il comme mot entier (pluriel toléré) ?
     */
    private static function matches(string $haystack, string $keyword): bool
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return false;
        }

        // Radical sans pluriel pour tolérer « champignons » ↔ « champignon ».
        $stem = preg_replace('/(es|s|x)$/u', '', $keyword) ?? $keyword;
        if (mb_strlen($stem) < 3) {
            $stem = $keyword;
        }

        $pattern = '/(?<![a-z])'.preg_quote($stem, '/').'(?:es|s|x)?(?![a-z])/u';

        return preg_match($pattern, $haystack) === 1;
    }
}
