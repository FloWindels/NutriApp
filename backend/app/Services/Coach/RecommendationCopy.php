<?php

namespace App\Services\Coach;

use Carbon\CarbonImmutable;

/**
 * Toutes les chaînes françaises du coach (brief §8) en un seul endroit.
 * Chaque méthode retourne [titre, message]. Le titre entre dans la clé de dédoublonnage
 * (sha1(type|titre)) : il doit rester stable pour une même situation.
 *
 * Interdits (test RecommendationCopyTest) : guér*, soign*, trait(e|ement), maladie, médical,
 * brûle, détox, garanti.
 */
final class RecommendationCopy
{
    /** Types dont les messages portent un nombre issu d'une estimation (cibles, budget, MET, poids). */
    public const TYPES_ESTIMES = [
        'sous_plancher', 'alerte_budget', 'budget_restant', 'manque_proteines', 'suggestion_repas',
        'ajustement_portions', 'sport_pre', 'sport_post', 'ddm_depassee',
    ];

    /** Libellés des types de repas (accusatif : « ton déjeuner »). */
    public const REPAS = [
        'petit_dejeuner' => 'petit-déjeuner',
        'dejeuner' => 'déjeuner',
        'diner' => 'dîner',
        'collation' => 'collation',
    ];

    public const EXEMPLE_COLLATION = 'un yaourt nature et un fruit';
    public const EXEMPLE_PROTEINES = 'un yaourt nature, des œufs ou du fromage blanc';
    public const EXEMPLE_GLUCIDES = 'une banane ou une tranche de pain';

    // ------------------------------------------------------------------------------------
    // Sécurité (p1)
    // ------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} */
    public static function profilIncomplet(): array
    {
        return [
            'Complète ton profil',
            'Complète ton profil pour obtenir tes objectifs personnalisés.',
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function sousPlancher(int $consomme, int $plancher, string $exemple): array
    {
        return [
            'Tu es sous le minimum recommandé',
            sprintf(
                'Tu es à %s kcal, sous le minimum recommandé (%s kcal) : ajoute une vraie collation (%s depuis ton stock).',
                self::fr($consomme),
                self::fr($plancher),
                $exemple
            ),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function produitPerime(string $label, string $ymd): array
    {
        return [
            sprintf('%s est périmé', $label),
            sprintf('%s est périmé depuis le %s : vérifie-le avant toute consommation.', $label, self::dateFr($ymd)),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function ddmDepassee(string $label, string $ymd): array
    {
        return [
            sprintf('DDM dépassée pour %s', $label),
            sprintf('DDM dépassée depuis le %s pour %s : souvent encore consommable, vérifie l’aspect et l’odeur.', self::dateFr($ymd), $label),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function alerteBudget(int $depassement): array
    {
        return [
            'Budget du jour dépassé',
            sprintf(
                'Tu as dépassé ton budget de %s kcal. Une marche de 20–30 min ou un dîner plus léger suffit à compenser.',
                self::fr($depassement)
            ),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function depassementPrise(int $depassement): array
    {
        return [
            'Au-dessus du budget, cohérent avec ta prise de masse',
            sprintf(
                'Tu es %s kcal au-dessus de ton budget : dans un objectif de prise, c’est cohérent si tes protéines sont couvertes.',
                self::fr($depassement)
            ),
        ];
    }

    // ------------------------------------------------------------------------------------
    // Objectif du jour (p2)
    // ------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} */
    public static function budgetRestant(int $restant, string $exemple): array
    {
        return [
            'Il te reste du budget aujourd’hui',
            sprintf('Il te reste %s kcal aujourd’hui : une collation équilibrée (%s) est possible.', self::fr($restant), $exemple),
        ];
    }

    /**
     * @param  list<string>  $aliments
     * @return array{0: string, 1: string}
     */
    public static function manqueProteines(int $grammes, array $aliments): array
    {
        $message = sprintf('Il te manque environ %s g de protéines.', self::fr($grammes));
        if ($aliments !== []) {
            $message .= sprintf(' Par exemple : %s.', self::liste($aliments));
        }

        return ['Un peu plus de protéines', $message];
    }

    /** @return array{0: string, 1: string} */
    public static function antiGaspillageRecette(string $label, int $daysLeft, string $recette): array
    {
        return [
            sprintf('%s expire bientôt', $label),
            sprintf('%s expire %s : utilise-le dans %s.', $label, self::quand($daysLeft), $recette),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function antiGaspillageConsomme(string $label, string $ymd): array
    {
        return [
            sprintf('%s expire bientôt', $label),
            sprintf('Consomme %s avant le %s.', $label, self::dateFr($ymd)),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function suggestionRepas(string $type, int $budget, string $recette, int $kcalPortion, string $facteur, ?string $itemExpirant): array
    {
        $repas = self::REPAS[$type] ?? 'repas';
        $message = sprintf(
            'Pour ton %s (≈ %s kcal), essaie %s : %s kcal par portion, %s portion conseillée.',
            $repas,
            self::fr($budget),
            $recette,
            self::fr($kcalPortion),
            $facteur
        );
        if ($itemExpirant !== null) {
            $message .= sprintf(' Bonus : elle utilise %s, qui expire bientôt.', $itemExpirant);
        }

        return [sprintf('Idée pour ton %s', $repas), $message];
    }

    /** @return array{0: string, 1: string} */
    public static function suggestionIdee(string $type, int $budget, string $titre, int $kcal): array
    {
        $repas = self::REPAS[$type] ?? 'repas';

        return [
            sprintf('Idée pour ton %s', $repas),
            sprintf('Pour ton %s (≈ %s kcal), une idée simple : %s (≈ %s kcal).', $repas, self::fr($budget), $titre, self::fr($kcal)),
        ];
    }

    // ------------------------------------------------------------------------------------
    // Confort (p3)
    // ------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string} */
    public static function ajustementPortions(string $facteur, string $recette): array
    {
        return [
            'Ajuste ta portion',
            sprintf('Prends %s portion de %s pour rester dans ton budget.', $facteur, $recette),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function sportPre(string $seance, string $heure, int $grammes, float $gParKg, string $aliment): array
    {
        return [
            sprintf('Avant ta séance %s', $seance),
            sprintf(
                'Séance %s à %s : vise %s g de glucides 1–2 h avant (≈ %s g/kg), par ex. %s.',
                $seance,
                $heure,
                self::fr($grammes),
                self::fr($gParKg, 1),
                $aliment
            ),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function sportPreKeto(string $seance, string $heure): array
    {
        return [
            sprintf('Avant ta séance %s', $seance),
            sprintf('Séance %s à %s : une collation légère protéinée ou lipidique suffit avant ta séance.', $seance, $heure),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function sportPreJeune(string $seance, string $heure): array
    {
        return [
            sprintf('Avant ta séance %s', $seance),
            sprintf('Séance %s à %s, à jeun prévue : hydrate-toi et garde une collation pour le début de ta fenêtre.', $seance, $heure),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function sportPost(int $grammes, string $aliment): array
    {
        return [
            'Récupération après ta séance',
            sprintf('Dans les 2 h après ta séance : %s g de protéines (≈ 0,3 g/kg), par ex. %s.', self::fr($grammes), $aliment),
        ];
    }

    /**
     * @param  list<string>  $labels
     * @return array{0: string, 1: string}
     */
    public static function courses(array $labels): array
    {
        $n = count($labels);

        return [
            'À racheter',
            $n === 1
                ? sprintf('%s est presque épuisé : ajoute-le à ta liste de courses.', $labels[0])
                : sprintf('%d produits à racheter : %s.', $n, self::liste($labels)),
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function hydratation(): array
    {
        return [
            'Pense à boire',
            'Pense à boire régulièrement, surtout autour de ta séance.',
        ];
    }

    // ------------------------------------------------------------------------------------
    // Libellés d'actions
    // ------------------------------------------------------------------------------------

    public static function actionAjouterAliment(string $label, float $quantite, string $unite): string
    {
        return sprintf('Ajouter %s (%s %s)', $label, self::fr($quantite, 1), $unite);
    }

    public static function actionAjouterRecette(string $titre, string $facteur): string
    {
        return sprintf('Ajouter %s (%s portion)', $titre, $facteur);
    }

    public static function actionOuvrirRecette(string $titre): string
    {
        return sprintf('Voir la recette %s', $titre);
    }

    public static function actionOuvrirStock(): string
    {
        return 'Voir dans le stock';
    }

    public static function actionSupprimerStock(string $label): string
    {
        return sprintf('Retirer %s du stock', $label);
    }

    public static function actionAjouterCourses(string $label): string
    {
        return sprintf('Ajouter %s aux courses', $label);
    }

    public static function actionGenererSeance(): string
    {
        return 'Générer une séance';
    }

    // ------------------------------------------------------------------------------------
    // Formats
    // ------------------------------------------------------------------------------------

    /**
     * « aujourd’hui », « demain », « dans n jours ».
     */
    public static function quand(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 0 => 'aujourd’hui',
            $daysLeft === 1 => 'demain',
            default => sprintf('dans %d jours', $daysLeft),
        };
    }

    /**
     * « 16 septembre ».
     */
    public static function dateFr(string $ymd): string
    {
        return CarbonImmutable::parse($ymd)->locale('fr')->translatedFormat('j F');
    }

    /**
     * Nombre en français (virgule décimale, espace des milliers), sans zéros inutiles.
     */
    public static function fr(float|int $value, int $decimals = 0): string
    {
        $formatted = number_format((float) $value, $decimals, ',', ' ');
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * Facteur de portion lisible : « 1 », « 0,5 », « 1,5 ».
     */
    public static function facteur(float $factor): string
    {
        return self::fr($factor, 1);
    }

    /**
     * « a, b et c ».
     *
     * @param  list<string>  $items
     */
    public static function liste(array $items): string
    {
        $items = array_values(array_filter(array_map('strval', $items), fn ($s) => $s !== ''));
        if ($items === []) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);

        return implode(', ', $items).' et '.$last;
    }

    /**
     * Échantillon de tous les messages (pour les tests de vocabulaire) : [type, titre, message, is_estimate].
     *
     * @return list<array{type: string, title: string, message: string, is_estimate: bool}>
     */
    public static function samples(): array
    {
        $rows = [
            ['profil_incomplet', self::profilIncomplet(), false],
            ['sous_plancher', self::sousPlancher(900, 1500, 'Yaourt nature'), true],
            ['produit_perime', self::produitPerime('Lait demi-écrémé', '2026-09-10'), false],
            ['ddm_depassee', self::ddmDepassee('Pâtes complètes', '2026-09-01'), true],
            ['alerte_budget', self::alerteBudget(420), true],
            ['alerte_budget', self::depassementPrise(300), true],
            ['budget_restant', self::budgetRestant(800, 'un yaourt nature et un fruit'), true],
            ['manque_proteines', self::manqueProteines(30, ['Fromage blanc', 'Œufs', 'Thon']), true],
            ['anti_gaspillage', self::antiGaspillageRecette('Brocoli', 1, 'Gratin de brocolis'), false],
            ['anti_gaspillage', self::antiGaspillageConsomme('Brocoli', '2026-09-18'), false],
            ['suggestion_repas', self::suggestionRepas('dejeuner', 650, 'Poulet au curry et riz', 600, '1', 'Poulet'), true],
            ['suggestion_repas', self::suggestionIdee('diner', 500, 'Soupe de légumes maison et tartine de chèvre', 380), true],
            ['ajustement_portions', self::ajustementPortions('0,5', 'Pâtes au saumon'), true],
            ['sport_pre', self::sportPre('Circuit cardio', '18:30', 70, 1.0, 'une banane'), true],
            ['sport_pre', self::sportPreKeto('Circuit cardio', '18:30'), true],
            ['sport_pre', self::sportPreJeune('Circuit cardio', '10:00'), true],
            ['sport_post', self::sportPost(25, 'un yaourt nature'), true],
            ['courses', self::courses(['Lait', 'Œufs']), false],
            ['courses', self::courses(['Lait']), false],
            ['hydratation', self::hydratation(), false],
        ];

        return array_map(fn (array $r) => [
            'type' => $r[0],
            'title' => $r[1][0],
            'message' => $r[1][1],
            'is_estimate' => $r[2],
        ], $rows);
    }
}
