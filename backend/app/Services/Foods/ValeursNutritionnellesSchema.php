<?php

namespace App\Services\Foods;

/**
 * Forme de la réponse du modèle et sa remise en ordre : bornes, cohérence énergie/macros, sources.
 */
final class ValeursNutritionnellesSchema
{
    /** Écart toléré entre l'énergie annoncée et celle recalculée des macros (Atwater). */
    private const ECART_ENERGIE = 0.25;

    /** @return array<string, mixed> */
    public static function json(): array
    {
        $gramme = ['type' => 'number', 'minimum' => 0, 'maximum' => 100];
        $grammeOuNull = ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['calories', 'proteines', 'glucides', 'lipides', 'fibres', 'sucres', 'sel', 'portion_g', 'nature', 'estimation', 'confiance', 'sources_utilisees'],
            'properties' => [
                'calories' => ['type' => 'number', 'minimum' => 0, 'maximum' => 950, 'description' => 'kcal pour 100 g'],
                'proteines' => $gramme,
                'glucides' => $gramme,
                'lipides' => $gramme,
                'fibres' => $grammeOuNull,
                'sucres' => $grammeOuNull,
                'sel' => $grammeOuNull,
                'portion_g' => ['type' => ['number', 'null'], 'minimum' => 1, 'maximum' => 2000],
                'nature' => ['type' => 'string', 'enum' => ['produit', 'plat']],
                'estimation' => ['type' => 'boolean'],
                'confiance' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'sources_utilisees' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 5],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $brut
     * @param  list<array{n: int, titre: string, url: string, texte: string}>  $sources
     * @return array{proposition: array<string, mixed>, sources: list<array{titre: string, url: string}>, avertissements: list<string>}|null
     */
    public static function normaliser(array $brut, string $nom, ?string $marque, array $sources): ?array
    {
        $kcal = self::nombre($brut['calories'] ?? null, 0, 950);
        $p = self::nombre($brut['proteines'] ?? null, 0, 100);
        $g = self::nombre($brut['glucides'] ?? null, 0, 100);
        $l = self::nombre($brut['lipides'] ?? null, 0, 100);

        if ($kcal === null || $p === null || $g === null || $l === null) {
            return null;
        }
        // Rien du tout, ou plus de 100 g de macros dans 100 g : ce n'est pas une valeur.
        if (($kcal <= 0 && $p + $g + $l <= 0) || $p + $g + $l > 105) {
            return null;
        }

        $avertissements = [];
        $confiance = self::nombre($brut['confiance'] ?? null, 0, 1) ?? 0.5;

        // Garde-fou déterministe : les extraits disent souvent « 100 g … N kcal ». Un modèle qui
        // s'en écarte nettement a presque toujours repris une valeur par portion : on ramène
        // alors toutes ses valeurs à 100 g dans la même proportion, et on le dit.
        $reference = self::energiePour100gDansLesSources(self::pagesPertinentes($sources, $nom));
        if ($reference !== null && $kcal > 0 && abs($kcal - $reference) > 0.25 * $reference) {
            $macros = 4 * $p + 4 * $g + 9 * $l;
            if (abs($macros - $reference) <= 0.25 * $reference) {
                // Les macros collent à la source : seule l'énergie était fausse.
                $kcal = $reference;
            } else {
                // Tout était lu pour une portion : on ramène l'ensemble à 100 g.
                $facteur = $reference / $kcal;
                [$kcal, $p, $g, $l] = [$reference, $p * $facteur, $g * $facteur, $l * $facteur];
                foreach (['fibres', 'sucres', 'sel'] as $champ) {
                    if (is_numeric($brut[$champ] ?? null)) {
                        $brut[$champ] = (float) $brut[$champ] * $facteur;
                    }
                }
            }
            $avertissements[] = sprintf('Valeurs ramenées à 100 g d’après les sources (%d kcal pour 100 g).', (int) round($reference));
            $confiance = min($confiance, 0.6);
        }

        $calculee = 4 * $p + 4 * $g + 9 * $l;
        if ($calculee > 0 && abs($kcal - $calculee) > max(30, self::ECART_ENERGIE * $calculee)) {
            $avertissements[] = sprintf(
                'L’énergie annoncée (%d kcal) ne correspond pas aux macros (≈ %d kcal) : vérifie sur l’emballage.',
                (int) round($kcal),
                (int) round($calculee)
            );
            $confiance = min($confiance, 0.4);
        }

        // Seules les sources réellement citées et réellement fournies sont rendues.
        $parNumero = [];
        foreach ($sources as $source) {
            $parNumero[$source['n']] = ['titre' => $source['titre'], 'url' => $source['url']];
        }
        $citees = [];
        foreach ((array) ($brut['sources_utilisees'] ?? []) as $n) {
            if (is_numeric($n) && isset($parNumero[(int) $n])) {
                $citees[(int) $n] = $parNumero[(int) $n];
            }
        }
        // Une valeur reprise d'une source n'est pas une estimation, quoi qu'en dise le modèle.
        $estimation = $citees === [];
        if ($estimation) {
            $confiance = min($confiance, 0.5);
            $avertissements[] = 'Aucune source ne donnait ces valeurs : estimation d’après une recette type.';
        }

        return [
            'proposition' => [
                'nom' => $nom,
                'marque' => $marque,
                'per_unit' => '100g',
                'calories' => round($kcal, 1),
                'proteins' => round($p, 1),
                'carbs' => round($g, 1),
                'fat' => round($l, 1),
                'fiber' => self::arrondi(self::nombre($brut['fibres'] ?? null, 0, 100)),
                'sugar' => self::arrondi(self::nombre($brut['sucres'] ?? null, 0, 100)),
                'salt' => self::arrondi(self::nombre($brut['sel'] ?? null, 0, 100)),
                'serving_size_g' => self::arrondi(self::nombre($brut['portion_g'] ?? null, 1, 2000)),
                'nature' => $estimation && ($brut['nature'] ?? '') === 'plat' ? 'plat' : 'produit',
                'confiance' => round($confiance, 2),
                'estimation' => $estimation,
            ],
            'sources' => array_values($citees),
            'avertissements' => $avertissements,
        ];
    }

    /**
     * Pages qui parlent bien de l'aliment : un mot significatif de son nom figure dans le titre ou
     * l'adresse. Une recherche « granola Bjorg » ramène aussi le muesli Bjorg : ses valeurs ne
     * doivent pas servir de référence.
     *
     * @param  list<array{n: int, titre: string, url: string, texte: string}>  $sources
     * @return list<array{n: int, titre: string, url: string, texte: string}>
     */
    public static function pagesPertinentes(array $sources, string $nom): array
    {
        $vides = ['maison', 'avec', 'sans', 'pour', 'aux', 'des', 'les', 'une', 'bio', 'nature', 'light'];
        $mots = array_filter(
            preg_split('/[^a-z0-9]+/', self::sansAccents($nom)) ?: [],
            fn ($mot) => mb_strlen($mot) >= 4 && ! in_array($mot, $vides, true),
        );
        if ($mots === []) {
            return $sources;
        }

        return array_values(array_filter($sources, function ($source) use ($mots) {
            $lieu = self::sansAccents($source['titre'].' '.$source['url']);
            foreach ($mots as $mot) {
                if (str_contains($lieu, $mot)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private static function sansAccents(string $texte): string
    {
        $texte = mb_strtolower($texte);
        // Décomposition Unicode puis retrait des accents : « crème » → « creme ». (iconv sous
        // Alpine/musl translittère en « cr`eme », ce qui coupe les mots accentués.)
        $decompose = class_exists(\Normalizer::class) ? \Normalizer::normalize($texte, \Normalizer::FORM_D) : false;

        return is_string($decompose) ? (string) preg_replace('/\p{Mn}+/u', '', $decompose) : $texte;
    }

    /**
     * Énergie pour 100 g lue telle quelle dans les extraits (« 100 g … 148 kcal »), médiane des
     * mentions trouvées ; null s'il n'y en a aucune.
     *
     * @param  list<array{n: int, titre: string, url: string, texte: string}>  $sources
     */
    public static function energiePour100gDansLesSources(array $sources): ?float
    {
        $valeurs = [];
        foreach ($sources as $source) {
            $texte = mb_strtolower($source['texte'] ?? '');
            if (preg_match_all('/100\s?(?:g|gr|grammes|ml)\b(.{0,80}?)(\d{1,3}(?:[.,]\d)?)\s?kcal/u', $texte, $trouves, PREG_SET_ORDER)) {
                foreach ($trouves as $trouve) {
                    // Une autre quantité entre « 100 g » et la valeur : elle ne s'y rapporte pas.
                    if (preg_match('/\d+\s?(?:g|ml)\b/u', $trouve[1])) {
                        continue;
                    }
                    $kcal = (float) str_replace(',', '.', $trouve[2]);
                    if ($kcal > 0 && $kcal <= 950) {
                        $valeurs[] = $kcal;
                    }
                }
            }
        }
        if ($valeurs === []) {
            return null;
        }
        sort($valeurs);

        return $valeurs[intdiv(count($valeurs), 2)];
    }

    private static function nombre(mixed $valeur, float $min, float $max): ?float
    {
        if (! is_numeric($valeur)) {
            return null;
        }
        $valeur = (float) $valeur;

        return $valeur < $min || $valeur > $max ? null : $valeur;
    }

    private static function arrondi(?float $valeur): ?float
    {
        return $valeur === null ? null : round($valeur, 1);
    }
}
