<?php

namespace App\Services\Sport;

use App\Models\Sport;
use Database\Seeders\SportSeeder;
use Illuminate\Support\Str;

/**
 * Vocabulaires du module Sport sans enum dédiée (addendum §A.4 / §C.4) : zones à éviter,
 * focus, objectifs sportifs, types de sport de la génération, et leurs libellés français.
 */
final class SportVocab
{
    public const ZONES = [
        'genoux' => 'Genoux',
        'dos' => 'Dos',
        'epaules' => 'Épaules',
        'poignets' => 'Poignets',
        'hanches' => 'Hanches',
        'cou' => 'Cou',
        'chevilles' => 'Chevilles',
        'coudes' => 'Coudes',
    ];

    public const FOCUS = [
        'perte_de_gras' => 'Perte de gras',
        'prise_de_muscle' => 'Prise de muscle',
        'endurance' => 'Endurance',
        'force' => 'Force',
        'mobilite' => 'Mobilité',
        'gainage' => 'Gainage',
        'haut_du_corps' => 'Haut du corps',
        'bas_du_corps' => 'Bas du corps',
        'fessiers' => 'Fessiers',
        'abdos' => 'Abdominaux',
        'dos' => 'Dos',
        'bras' => 'Bras',
        'pectoraux' => 'Pectoraux',
        'epaules' => 'Épaules',
        'jambes' => 'Jambes',
        'cardio' => 'Cardio',
    ];

    /** Focus historiques du brief §13.3, toujours acceptés. */
    public const FOCUS_LEGACY = ['bas', 'haut', 'full_body'];

    public const OBJECTIFS = [
        'perte_de_gras' => 'Perte de gras',
        'prise_de_muscle' => 'Prise de muscle',
        'endurance' => 'Endurance',
        'forme' => 'Forme',
        'force' => 'Force',
    ];

    public const SPORT_TYPES = [
        'musculation' => 'Musculation',
        'course_a_pied' => 'Course à pied',
        'velo' => 'Vélo',
        'natation' => 'Natation',
        'hiit' => 'HIIT',
        'yoga' => 'Yoga / mobilité',
        'marche' => 'Marche',
        'rameur' => 'Rameur',
        'autre' => 'Autre',
    ];

    /** Types de sport traités comme du cardio pur (bloc principal en fractionné ou continu). */
    public const CARDIO_TYPES = ['course_a_pied', 'velo', 'natation', 'marche', 'rameur'];

    /** Type de sport → slug du catalogue `sports`. */
    public const SPORT_TYPE_SLUGS = [
        'musculation' => 'musculation',
        'course_a_pied' => 'course-a-pied',
        'velo' => 'velo',
        'natation' => 'natation',
        'hiit' => 'hiit',
        'yoga' => 'yoga',
        'marche' => 'marche',
        'rameur' => 'rameur',
        'autre' => 'autre',
    ];

    /** Slug du catalogue → type de sport de la génération. */
    private const SLUG_TO_TYPE = [
        'course-a-pied' => 'course_a_pied',
        'trail' => 'course_a_pied',
        'velo' => 'velo',
        'vtt' => 'velo',
        'natation' => 'natation',
        'marche' => 'marche',
        'marche-nordique' => 'marche',
        'randonnee' => 'marche',
        'rameur' => 'rameur',
        'aviron-kayak' => 'rameur',
        'musculation' => 'musculation',
        'crossfit' => 'musculation',
        'hiit' => 'hiit',
        'yoga' => 'yoga',
        'pilates' => 'yoga',
        'stretching' => 'yoga',
    ];

    /**
     * Mots-clés (comparés sans accents ni majuscules) qui trahissent une sollicitation d'une zone,
     * pour les exercices hors catalogue (exercise_id null) proposés par l'IA.
     */
    public const ZONE_KEYWORDS = [
        'genoux' => ['squat', 'fente', 'saut', 'course', 'jump', 'burpee', 'step'],
        'dos' => ['deadlift', 'souleve', 'rowing', 'good morning', 'superman'],
        'epaules' => ['developpe', 'pompe', 'traction', 'dips', 'elevation', 'militaire'],
        'poignets' => ['pompe', 'planche sur les mains', 'burpee', 'mountain climber'],
        'hanches' => ['fente', 'squat profond', 'hip thrust', 'pont'],
        'cou' => ['pont', 'releve de jambes lourd', 'chandelle'],
        'chevilles' => ['saut', 'course', 'corde', 'jump', 'burpee'],
        'coudes' => ['traction', 'dips', 'curl', 'extension triceps'],
    ];

    /** Focus → groupes musculaires ciblés (absent = pas de filtre). */
    private const FOCUS_MUSCLES = [
        'haut_du_corps' => ['dos', 'pectoraux', 'epaules', 'bras'],
        'haut' => ['dos', 'pectoraux', 'epaules', 'bras'],
        'bas_du_corps' => ['jambes', 'fessiers'],
        'bas' => ['jambes', 'fessiers'],
        'fessiers' => ['fessiers'],
        'abdos' => ['abdos'],
        'dos' => ['dos'],
        'bras' => ['bras'],
        'pectoraux' => ['pectoraux'],
        'epaules' => ['epaules'],
        'jambes' => ['jambes'],
    ];

    /** @return list<string> */
    public static function focusValues(): array
    {
        return array_merge(array_keys(self::FOCUS), self::FOCUS_LEGACY);
    }

    /**
     * Groupes musculaires ciblés par une liste de focus (vide = corps entier).
     *
     * @param  list<string>  $focus
     * @return list<string>
     */
    public static function musclesForFocus(array $focus): array
    {
        $muscles = [];
        foreach ($focus as $f) {
            foreach (self::FOCUS_MUSCLES[$f] ?? [] as $m) {
                $muscles[$m] = true;
            }
        }

        return array_keys($muscles);
    }

    public static function isCardioType(?string $type): bool
    {
        return $type !== null && in_array($type, self::CARDIO_TYPES, true);
    }

    public static function sportTypeSlug(?string $type): ?string
    {
        return $type === null ? null : (self::SPORT_TYPE_SLUGS[$type] ?? null);
    }

    /**
     * Type de sport de la génération déduit d'un sport du catalogue (ou d'un libellé libre).
     */
    public static function sportTypeFor(Sport|string|null $sport): string
    {
        if ($sport === null) {
            return 'musculation';
        }

        $slug = $sport instanceof Sport ? (string) $sport->slug : Str::slug($sport);
        $slug = preg_replace('/-u\d+$/', '', $slug) ?? $slug;

        if (isset(self::SLUG_TO_TYPE[$slug])) {
            return self::SLUG_TO_TYPE[$slug];
        }

        $category = $sport instanceof Sport ? $sport->category : null;

        return match ($category) {
            'force' => 'musculation',
            'bien_etre' => 'yoga',
            default => 'autre',
        };
    }

    /**
     * Triplet MET d'un type de sport (catalogue statique, sans base de données).
     *
     * @return array{faible: float, moderee: float, elevee: float}
     */
    public static function metTripletForType(string $type): array
    {
        $slug = self::sportTypeSlug($type) ?? 'autre';

        return self::metTripletForSlug($slug) ?? ['faible' => 4.0, 'moderee' => 6.0, 'elevee' => 8.0];
    }

    /**
     * @return array{faible: float, moderee: float, elevee: float}|null
     */
    public static function metTripletForSlug(string $slug): ?array
    {
        foreach (SportSeeder::catalog() as $row) {
            if (Str::slug($row[0]) === $slug) {
                return ['faible' => (float) $row[2], 'moderee' => (float) $row[3], 'elevee' => (float) $row[4]];
            }
        }

        return null;
    }

    /**
     * Vrai si le nom d'un exercice hors catalogue évoque une zone à éviter.
     *
     * @param  list<string>  $zones
     */
    public static function nameHitsZones(string $name, array $zones): bool
    {
        if ($zones === []) {
            return false;
        }

        $haystack = Str::lower(Str::ascii($name));

        foreach ($zones as $zone) {
            foreach (self::ZONE_KEYWORDS[$zone] ?? [] as $keyword) {
                if (str_contains($haystack, Str::lower(Str::ascii($keyword)))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string}>
     */
    public static function options(array $labels): array
    {
        $out = [];
        foreach ($labels as $key => $label) {
            $out[] = ['key' => (string) $key, 'label' => $label];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $labels
     */
    public static function label(array $labels, ?string $key, string $fallback = ''): string
    {
        return $key !== null ? ($labels[$key] ?? $fallback) : $fallback;
    }
}
