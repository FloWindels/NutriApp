<?php

namespace App\Services\Magasins;

use App\Services\Planner\CompatibilityFilter;

/**
 * Normalisation d'un libellé de produit, pour rattacher « Riz basmati 1 kg » de chez Lidl à la
 * ligne « riz basmati » de la liste de courses.
 *
 * Le rattachement est la pièce la plus fragile de tout le module : quand il se trompe, la
 * personne voit un prix qui n'est pas celui de son article. On préfère donc ne pas rattacher
 * plutôt que rattacher à peu près, et la normalisation est volontairement conservatrice : on
 * retire le conditionnement et les mots vides, jamais les mots qui distinguent deux produits.
 */
final class LibelleProduit
{
    /** Mots qui ne distinguent aucun produit d'un autre, et qu'un libellé de rayon ajoute. */
    private const MOTS_VIDES = [
        'de', 'du', 'des', 'le', 'la', 'les', 'l', 'd', 'au', 'aux', 'en', 'et', 'a',
        'bio', 'frais', 'fraiche', 'fraiches', 'nature', 'sachet', 'paquet', 'barquette',
        'bouteille', 'boite', 'pack', 'lot', 'ravier',
    ];

    /**
     * Forme comparable d'un libellé : minuscules sans accents, sans conditionnement, sans mots
     * vides, au singulier approximatif.
     */
    public static function normaliser(string $libelle): string
    {
        $plie = CompatibilityFilter::fold($libelle);

        // Conditionnements : « 1 kg », « 500g », « 6 x 33 cl », « 2x125 ml ».
        $plie = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:x\s*\d+(?:[.,]\d+)?\s*)?(?:kg|g|mg|l|cl|ml|dl|pieces?|pcs?)\b/u', ' ', $plie) ?? $plie;
        // Chiffres résiduels et ponctuation.
        $plie = preg_replace('/[^a-z ]+/u', ' ', $plie) ?? $plie;

        $mots = [];
        foreach (preg_split('/\s+/u', $plie) ?: [] as $mot) {
            $mot = self::singulier($mot);

            if ($mot === '' || in_array($mot, self::MOTS_VIDES, true)) {
                continue;
            }

            $mots[] = $mot;
        }

        return mb_substr(implode(' ', $mots), 0, 191);
    }

    /**
     * Deux libellés déjà normalisés désignent-ils le même produit ?
     *
     * L'inclusion porte sur des MOTS entiers, dans un sens ou dans l'autre. Une inclusion de
     * simples caractères rattacherait « riz » à « fricadelle » et « ail » à « volaille » — et ces
     * deux erreurs-là auraient mis un prix faux sur la liste de quelqu'un.
     */
    public static function correspond(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        return self::contient($a, $b) || self::contient($b, $a);
    }

    /** `$texte` contient-il tous les mots de `$mots` ? Sens utile pour départager deux candidats. */
    public static function contient(string $texte, string $mots): bool
    {
        return $texte !== '' && $mots !== '' && self::contientTousLesMots($texte, $mots);
    }

    private static function contientTousLesMots(string $texte, string $mots): bool
    {
        $presents = array_flip(explode(' ', $texte));

        foreach (explode(' ', $mots) as $mot) {
            if ($mot !== '' && ! isset($presents[$mot])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pluriel français le plus courant seulement. Pas de lemmatisation : « pommes » et « pomme »
     * doivent se rejoindre, « pommade » ne doit pas y être entraînée.
     */
    private static function singulier(string $mot): string
    {
        if (mb_strlen($mot) > 3 && str_ends_with($mot, 's') && ! str_ends_with($mot, 'ss')) {
            return mb_substr($mot, 0, -1);
        }

        return $mot;
    }
}
