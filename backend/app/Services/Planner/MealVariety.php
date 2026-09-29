<?php

namespace App\Services\Planner;

/**
 * Famille dominante d'un plat, devinée depuis son titre et ses ingrédients principaux.
 *
 * Sert au seul classement du planificateur. Une semaine peut n'avoir aucun plat en double et
 * sembler malgré tout répétitive : poulet lundi, poulet mardi, poulet mercredi. On regarde donc
 * l'ingrédient qui domine, pour éviter de le remettre deux jours d'affilée.
 *
 * Ce n'est pas une classification nutritionnelle — elle serait fausse aussi souvent qu'utile.
 * C'est un indice de ressemblance, et rien de ce qui touche à la sécurité (allergènes, régimes)
 * n'en dépend : cela reste l'affaire de CompatibilityFilter.
 */
final class MealVariety
{
    /**
     * Familles par ordre de priorité : la source de protéines l'emporte sur l'accompagnement,
     * parce que c'est elle qu'on a l'impression de remanger.
     *
     * @var array<string, list<string>>
     */
    private const FAMILLES = [
        'poisson' => ['poisson', 'saumon', 'thon', 'cabillaud', 'colin', 'hareng', 'truite', 'sardine', 'maquereau', 'dorade', 'anchois'],
        'fruits_de_mer' => ['crevette', 'moule', 'calamar', 'crustace', 'fruits de mer'],
        'volaille' => ['poulet', 'dinde', 'volaille', 'waterzooi'],
        'boeuf' => ['boeuf', 'steak', 'carbonade', 'bourguignon', 'hachis', 'bolognaise', 'boulette'],
        'porc' => ['porc', 'jambon', 'lardon', 'lard', 'bacon', 'saucisse', 'chorizo', 'reblochon', 'tartiflette'],
        'veau_agneau' => ['veau', 'agneau', 'blanquette'],
        'oeufs' => ['oeuf', 'omelette'],
        'legumineuses' => ['lentille', 'pois chiche', 'haricot rouge', 'dahl', 'houmous', 'edamame', 'feve'],
        'soja' => ['tofu', 'tempeh'],
        'fromage' => ['fromage', 'chevre', 'feta', 'mozzarella', 'ricotta', 'parmesan', 'raclette', 'emmental', 'skyr', 'yaourt'],
        'fruits_secs' => ['amande', 'noix', 'noisette', 'cacahuete', 'arachide'],
        'cereales' => ['riz', 'pate', 'quinoa', 'boulgour', 'semoule', 'couscous', 'avoine', 'pain', 'nouille', 'polenta', 'sarrasin', 'muesli', 'granola'],
        'legumes' => ['soupe', 'veloute', 'potage', 'salade', 'legume', 'ratatouille', 'gratin', 'poelee', 'chou', 'courgette', 'aubergine'],
        'fruits' => ['pomme', 'banane', 'fruit', 'compote', 'mangue', 'framboise', 'myrtille', 'clementine'],
    ];

    /** @var array<string, string|null> */
    private static array $cache = [];

    /**
     * Famille du plat, ou null si aucun mot-clé ne ressort : deux plats « inconnus » ne sont
     * alors pas tenus pour semblables, ce qui vaut mieux que de les confondre.
     *
     * @param  list<string>  $ingredients
     */
    public static function famille(string $title, array $ingredients = []): ?string
    {
        $texte = CompatibilityFilter::fold(implode(' | ', array_merge([$title], $ingredients)));
        if ($texte === '') {
            return null;
        }

        if (array_key_exists($texte, self::$cache)) {
            return self::$cache[$texte];
        }

        $trouvee = null;

        foreach (self::FAMILLES as $famille => $motsCles) {
            foreach ($motsCles as $mot) {
                if (self::contient($texte, $mot)) {
                    $trouvee = $famille;

                    break 2;
                }
            }
        }

        return self::$cache[$texte] = $trouvee;
    }

    /**
     * Mot entier, pluriel toléré : sans cela « chorizo » contiendrait « riz » et un plat de
     * charcuterie passerait pour un plat de céréales.
     */
    private static function contient(string $texte, string $mot): bool
    {
        return preg_match('/(?<![a-z])'.preg_quote($mot, '/').'(?:es|s|x)?(?![a-z])/u', $texte) === 1;
    }
}
