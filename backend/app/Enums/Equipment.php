<?php

namespace App\Enums;

/**
 * Matériel d'entraînement (Sport v2 §1). Les valeurs historiques sont conservées : `tapis`
 * désigne désormais le tapis de SOL (le tapis de course a sa propre valeur, `tapis_course`) et
 * `velo` le vélo d'intérieur (appartement ou home trainer), `velo_route` le vélo qu'on sort.
 */
enum Equipment: string
{
    case Aucun = 'aucun';
    case Halteres = 'halteres';
    case Barre = 'barre';
    case Kettlebell = 'kettlebell';
    case Elastiques = 'elastiques';
    case Banc = 'banc';
    case BarreTraction = 'barre_traction';
    case Machine = 'machine';
    case Tapis = 'tapis';
    case Velo = 'velo';
    case CordeASauter = 'corde_a_sauter';
    case Trx = 'trx';
    case SwissBall = 'swiss_ball';
    case MedecineBall = 'medecine_ball';
    case Step = 'step';
    case Poulie = 'poulie';
    case VeloRoute = 'velo_route';
    case TapisCourse = 'tapis_course';
    case Rameur = 'rameur';
    case Elliptique = 'elliptique';
    case Piscine = 'piscine';

    public const CATEGORIE_CARDIO = 'cardio';

    public const CATEGORIE_MUSCULATION = 'musculation';

    public const CATEGORIE_ACCESSOIRE = 'accessoire';

    public const CATEGORIE_AUCUN = 'aucun';

    /**
     * Ordre d'affichage du catalogue (par catégorie, du plus courant au plus spécialisé),
     * avec libellé et catégorie.
     */
    private const CATALOGUE = [
        'aucun' => ['Poids du corps', self::CATEGORIE_AUCUN],
        'tapis' => ['Tapis de sol', self::CATEGORIE_ACCESSOIRE],
        'elastiques' => ['Élastiques', self::CATEGORIE_ACCESSOIRE],
        'corde_a_sauter' => ['Corde à sauter', self::CATEGORIE_CARDIO],
        'trx' => ['Sangles de suspension (TRX)', self::CATEGORIE_ACCESSOIRE],
        'swiss_ball' => ['Swiss ball', self::CATEGORIE_ACCESSOIRE],
        'medecine_ball' => ['Médecine-ball', self::CATEGORIE_ACCESSOIRE],
        'step' => ['Step / box', self::CATEGORIE_ACCESSOIRE],
        'halteres' => ['Haltères', self::CATEGORIE_MUSCULATION],
        'kettlebell' => ['Kettlebell', self::CATEGORIE_MUSCULATION],
        'barre' => ['Barre et disques', self::CATEGORIE_MUSCULATION],
        'banc' => ['Banc', self::CATEGORIE_MUSCULATION],
        'barre_traction' => ['Barre de traction', self::CATEGORIE_MUSCULATION],
        'machine' => ['Machines guidées', self::CATEGORIE_MUSCULATION],
        'poulie' => ['Poulie / vis-à-vis', self::CATEGORIE_MUSCULATION],
        'velo' => ['Vélo d’appartement / home trainer', self::CATEGORIE_CARDIO],
        'velo_route' => ['Vélo (route, VTT)', self::CATEGORIE_CARDIO],
        'tapis_course' => ['Tapis de course', self::CATEGORIE_CARDIO],
        'rameur' => ['Rameur', self::CATEGORIE_CARDIO],
        'elliptique' => ['Vélo elliptique', self::CATEGORIE_CARDIO],
        'piscine' => ['Piscine', self::CATEGORIE_CARDIO],
    ];

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public function categorie(): string
    {
        return self::CATALOGUE[$this->value][1];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return array_map(static fn (array $row): string => $row[0], self::CATALOGUE);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Catalogue exposé par GET /sport/config (`materiel_catalogue`).
     *
     * @return list<array{valeur: string, libelle: string, categorie: string}>
     */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $valeur => [$libelle, $categorie]) {
            $out[] = ['valeur' => $valeur, 'libelle' => $libelle, 'categorie' => $categorie];
        }

        return $out;
    }

    /**
     * Préréglage proposé à la création d'un lieu de ce type (modifiable ensuite) :
     * maison = poids du corps + tapis de sol ; extérieur = poids du corps ; salle publique =
     * tout sauf vélo de route et piscine ; salle privée = tout sauf vélo de route.
     *
     * @return list<string>
     */
    public static function preset(string $lieu): array
    {
        $all = array_keys(self::CATALOGUE);

        return match ($lieu) {
            'maison' => ['aucun', 'tapis'],
            'exterieur' => ['aucun'],
            'salle_publique' => array_values(array_diff($all, ['velo_route', 'piscine'])),
            'salle_privee' => array_values(array_diff($all, ['velo_route'])),
            default => ['aucun'],
        };
    }

    /**
     * Préréglages de tous les types de lieu (`materiel_par_type`).
     *
     * @return array<string, list<string>>
     */
    public static function presets(): array
    {
        $out = [];
        foreach (Lieu::values() as $lieu) {
            $out[$lieu] = self::preset($lieu);
        }

        return $out;
    }
}
