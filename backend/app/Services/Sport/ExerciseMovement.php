<?php

namespace App\Services\Sport;

/**
 * Motif de mouvement d'un exercice, pour l'illustrer côté client.
 *
 * Deux sources d'exercices coexistent : le catalogue (table exercises, motif stocké en colonne)
 * et les exercices fabriqués à la volée, sans identifiant de catalogue — par le générateur à
 * règles pour le cardio, et par le modèle de langage qui a le droit de proposer un nom libre.
 * La déduction ci-dessous couvre les seconds, et sert de filet aux premiers.
 *
 * Elle procède en trois passes : mots-clés sur le slug, puis sur le nom normalisé (sans accents
 * ni majuscules, comme SportVocab::ZONE_KEYWORDS), puis repli sur le matériel et la catégorie.
 */
class ExerciseMovement
{
    public const GENERIQUE = 'generique';

    /**
     * Motifs connus, dans l'ordre d'affichage de la documentation.
     *
     * @var list<string>
     */
    public const MOVEMENTS = [
        // Force — bas du corps
        'squat', 'fente', 'charniere_hanche', 'pont_hanche', 'isolation_jambe',
        // Force — haut du corps
        'poussee_horizontale', 'poussee_verticale', 'tirage_horizontal', 'tirage_vertical',
        'elevation_bras', 'flexion_coude', 'extension_coude',
        // Tronc
        'gainage_statique', 'gainage_dynamique', 'flexion_tronc', 'extension_dorsale',
        // Cardio
        'course', 'marche', 'velo', 'rameur', 'nage', 'saut', 'appuis_sur_place', 'frappes',
        // Mobilité
        'etirement_statique', 'cercles_articulaires', 'mobilite_colonne',
        // Repli
        self::GENERIQUE,
    ];

    /**
     * Mots-clés par motif, comparés sur le slug puis sur le nom normalisé.
     * L'ordre compte : le premier motif dont un mot-clé apparaît l'emporte.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        // Les libellés composés passent avant les verbes génériques : « fentes marchées » est
        // une fente, pas de la marche.
        'fente' => ['fente', 'lunge', 'step up', 'montee sur banc', 'plus grand etirement'],

        // Puis le cardio : le générateur et le modèle produisent surtout ces libellés-là,
        // et « course fractionnée » ne doit pas être happée par un mot-clé de force.
        'nage' => ['nage', 'natation', 'crawl', 'brasse', 'aquagym'],
        'rameur' => ['rameur', 'rowing machine', 'aviron'],
        'velo' => ['velo', 'cyclisme', 'elliptique', 'spinning', 'vtt'],
        'course' => ['course', 'courir', 'jogging', 'footing', 'sprint', 'fractionne', 'trail'],
        // Avant « marche » : « marche du fermier » et « marche sur place » ne sont pas de la marche.
        'appuis_sur_place' => ['montees de genoux', 'talons fesses', 'pas chasses', 'step touch', 'sur place', 'marche du fermier'],
        'marche' => ['marche rapide', 'marche inclinee', 'marche nordique', 'randonnee', 'marche'],
        'saut' => ['saut', 'jumping', 'burpee', 'box jump'],
        'frappes' => ['boxe', 'shadow', 'frappe', 'uppercut', 'crochet'],

        // Force — bas du corps
        'squat' => ['squat', 'chaise murale', 'chaise contre le mur', 'presse a cuisses', 'leg press', 'goblet'],
        'charniere_hanche' => ['souleve de terre', 'deadlift', 'good morning', 'swing', 'romanian'],
        'pont_hanche' => ['pont', 'hip thrust', 'planche inversee', 'bridge'],
        'isolation_jambe' => ['leg curl', 'leg extension', 'mollets', 'abduction', 'adduction', 'ischio machine'],

        // Force — haut du corps
        // Horizontal avant vertical : « tractions australiennes » est un tirage horizontal.
        'tirage_horizontal' => ['rowing', 'tirage horizontal', 'face pull', 'australienne'],
        'tirage_vertical' => ['traction', 'tirage vertical', 'pull up', 'lat pulldown'],
        'poussee_verticale' => ['developpe militaire', 'developpe epaules', 'overhead press', 'developpe nuque'],
        'poussee_horizontale' => ['pompe', 'developpe couche', 'developpe incline', 'developpe barre', 'push up', 'bench'],
        'extension_coude' => ['triceps', 'dips', 'kickback', 'extension coude'],
        'flexion_coude' => ['curl', 'biceps'],
        'elevation_bras' => ['elevation', 'oiseau', 'ecarte', 'papillon', 'butterfly'],

        // Tronc
        'gainage_dynamique' => ['dead bug', 'bird dog', 'pallof', 'mountain climber', 'toucher epaule', 'planche dynamique'],
        'gainage_statique' => ['planche', 'gainage', 'hollow', 'suspension', 'chaise romaine'],
        'flexion_tronc' => ['crunch', 'releve de jambes', 'sit up', 'abdo', 'bicyclette'],
        'extension_dorsale' => ['superman', 'extension lombaire', 'hyperextension'],

        // Mobilité
        'cercles_articulaires' => ['cercle', 'bassin', 'balancement', 'mobilite cheville', 'echauffement articulaire'],
        'mobilite_colonne' => ['chat vache', 'rotation thoracique', 'colonne'],
        'etirement_statique' => ['etirement', 'stretch', 'posture', 'ouverture', '90 90', 'yoga'],

    ];

    /** Motifs déduits du seul matériel, quand aucun mot-clé ne ressort. */
    private const EQUIPMENT_FALLBACK = [
        'velo' => 'velo',
        'tapis' => 'course',
        'barre_traction' => 'tirage_vertical',
    ];

    /** Dernier repli, par catégorie. */
    private const CATEGORY_FALLBACK = [
        'mobilite' => 'etirement_statique',
        'gainage' => 'gainage_statique',
        'cardio' => 'appuis_sur_place',
    ];

    /** Repli par groupe musculaire pour la catégorie force, la plus nombreuse. */
    private const MUSCLE_FALLBACK = [
        'jambes' => 'squat',
        'fessiers' => 'pont_hanche',
        'dos' => 'tirage_horizontal',
        'pectoraux' => 'poussee_horizontale',
        'epaules' => 'poussee_verticale',
        'bras' => 'flexion_coude',
        'abdos' => 'flexion_tronc',
        'corps_entier' => 'saut',
    ];

    public static function guess(
        ?string $slug = null,
        ?string $category = null,
        ?string $muscle = null,
        ?string $equipment = null,
        ?string $name = null,
    ): string {
        foreach ([self::normalize($slug), self::normalize($name)] as $haystack) {
            if ($haystack === '') {
                continue;
            }
            $found = self::matchKeywords($haystack);
            if ($found !== null) {
                return $found;
            }
        }

        if ($equipment !== null && isset(self::EQUIPMENT_FALLBACK[$equipment])) {
            return self::EQUIPMENT_FALLBACK[$equipment];
        }

        if ($category === 'force' && $muscle !== null && isset(self::MUSCLE_FALLBACK[$muscle])) {
            return self::MUSCLE_FALLBACK[$muscle];
        }

        if ($category !== null && isset(self::CATEGORY_FALLBACK[$category])) {
            return self::CATEGORY_FALLBACK[$category];
        }

        return self::GENERIQUE;
    }

    private static function matchKeywords(string $haystack): ?string
    {
        foreach (self::KEYWORDS as $movement => $keywords) {
            foreach ($keywords as $keyword) {
                // Début de mot obligatoire : sans cela « développé » contiendrait « vélo ».
                if (preg_match('/\b'.preg_quote($keyword, '/').'/u', $haystack) === 1) {
                    return $movement;
                }
            }
        }

        return null;
    }

    /** Minuscules, sans accents, tirets ramenés à des espaces. */
    private static function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $ascii = strtr(
            mb_strtolower($value, 'UTF-8'),
            ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
                'ç' => 'c', '’' => "'"],
        );

        return trim(preg_replace('/\s+/', ' ', str_replace('-', ' ', $ascii)) ?? '');
    }
}
