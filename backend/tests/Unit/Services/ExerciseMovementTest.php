<?php

namespace Tests\Unit\Services;

use App\Services\Sport\ExerciseMovement;
use Database\Seeders\ExerciseSeeder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Filet de couverture des illustrations : tout exercice doit obtenir un motif, ceux du catalogue
 * sans jamais tomber sur le repli générique, et ceux fabriqués à la volée par le générateur ou
 * par le modèle de langage à partir de leur seul nom.
 */
class ExerciseMovementTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        $method = new ReflectionMethod(ExerciseSeeder::class, 'catalog');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    public function test_chaque_exercice_du_catalogue_obtient_un_motif_connu(): void
    {
        $catalogue = $this->catalogue();
        $this->assertGreaterThanOrEqual(100, count($catalogue), 'Le catalogue doit rester fourni.');

        foreach ($catalogue as $exercice) {
            $motif = ExerciseMovement::guess(
                $exercice['slug'],
                $exercice['category'],
                $exercice['muscle_group'],
                $exercice['equipment'],
                $exercice['name'],
            );

            $this->assertContains($motif, ExerciseMovement::MOVEMENTS, "Motif inconnu pour {$exercice['slug']}.");
            $this->assertNotSame(
                ExerciseMovement::GENERIQUE,
                $motif,
                "L'exercice {$exercice['slug']} n'a pas d'illustration dédiée.",
            );
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nomsHorsCatalogue(): array
    {
        return [
            // Fabriqués par le générateur à règles, sans identifiant de catalogue.
            'course fractionnée' => ['Course fractionnée 4 × 400 m', 'course'],
            'nage continue' => ['Nage continue', 'nage'],
            'rameur' => ['Rameur tempo', 'rameur'],
            'marche extérieure' => ['Marche rapide en extérieur', 'marche'],
            'vélo' => ['Vélo route 45 min', 'velo'],
            // Formulations libres plausibles du modèle de langage.
            'gainage' => ['Gainage planche 45 s', 'gainage_statique'],
            'développé' => ['Développé couché haltères', 'poussee_horizontale'],
            'fentes marchées' => ['Fentes marchées', 'fente'],
            'corde' => ['Corde à sauter', 'saut'],
            'étirement' => ['Étirement des ischio-jambiers', 'etirement_statique'],
            // Aucun repère : repli assumé.
            'inconnu' => ['Mouvement totalement inventé', ExerciseMovement::GENERIQUE],
        ];
    }

    /**
     * @dataProvider nomsHorsCatalogue
     */
    public function test_les_exercices_sans_catalogue_sont_deduits_du_nom(string $nom, string $attendu): void
    {
        $this->assertSame($attendu, ExerciseMovement::guess(null, null, null, null, $nom));
    }

    public function test_le_nom_ne_piege_pas_les_mots_contenus(): void
    {
        // « déVELOppé » contient « vélo », « fentes marchées » contient « marche ».
        $this->assertSame('poussee_horizontale', ExerciseMovement::guess(null, null, null, null, 'Développé couché'));
        $this->assertSame('fente', ExerciseMovement::guess(null, null, null, null, 'Fentes marchées haltères'));
        $this->assertSame('appuis_sur_place', ExerciseMovement::guess(null, null, null, null, 'Marche du fermier'));
        $this->assertSame('tirage_horizontal', ExerciseMovement::guess(null, null, null, null, 'Tractions australiennes'));
    }

    public function test_le_repli_utilise_la_categorie_et_le_muscle(): void
    {
        $this->assertSame('squat', ExerciseMovement::guess(null, 'force', 'jambes', 'aucun', 'Exercice X'));
        $this->assertSame('gainage_statique', ExerciseMovement::guess(null, 'gainage', null, 'aucun', 'Exercice X'));
        $this->assertSame('etirement_statique', ExerciseMovement::guess(null, 'mobilite', null, 'aucun', 'Exercice X'));
        $this->assertSame('appuis_sur_place', ExerciseMovement::guess(null, 'cardio', null, 'aucun', 'Exercice X'));
        $this->assertSame('velo', ExerciseMovement::guess(null, 'cardio', 'cardio', 'velo', 'Exercice X'));
        $this->assertSame(ExerciseMovement::GENERIQUE, ExerciseMovement::guess(null, null, null, null, null));
    }
}
