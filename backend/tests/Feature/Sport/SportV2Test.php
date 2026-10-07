<?php

namespace Tests\Feature\Sport;

use App\Enums\Offre;
use App\Models\Profile;
use App\Models\SportLieu;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use Database\Seeders\ExerciseSeeder;
use Database\Seeders\SportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Contracts\LlmWorkoutClient;
use App\Services\Sport\WorkoutAiGenerator;
use App\Services\Sport\WorkoutProposalSchema;
use Tests\Support\FakeLlmWorkoutClient;
use Tests\TestCase;

/**
 * Sport v2 (docs/SPORT_V2.md) : lieux d'entraînement, génération pour un lieu, prescription
 * structurée de bout en bout (proposition → enregistrement → lecture), reprise des profils.
 */
class SportV2Test extends TestCase
{
    use RefreshDatabase;

    private function membre(?int $age = 40): User
    {
        $user = User::factory()->create();
        $user->forceFill(['offre' => Offre::Complet])->save();
        Profile::factory()->for($user)->create(['age' => $age, 'sport_niveau' => 'intermediaire']);
        config(['llm.provider' => 'none', 'services.llm.provider' => 'none']);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function catalogues(): void
    {
        $this->seed(SportSeeder::class);
        $this->seed(ExerciseSeeder::class);
    }

    // ------------------------------------------------------------------------------------
    // Lieux
    // ------------------------------------------------------------------------------------

    public function test_crud_des_lieux_et_lieu_par_defaut(): void
    {
        $this->membre();

        $maison = $this->postJson('/api/sport/lieux', ['nom' => 'Chez moi', 'type' => 'maison'])
            ->assertCreated()
            ->assertJsonPath('message', 'Lieu ajouté.')
            ->assertJsonPath('data.par_defaut', true)
            ->assertJsonPath('data.type_libelle', 'Maison')
            ->assertJsonPath('data.materiel', ['aucun', 'tapis'])
            ->json('data');
        $this->assertSame(['id', 'nom', 'type', 'type_libelle', 'materiel', 'par_defaut'], array_keys($maison));

        $salle = $this->postJson('/api/sport/lieux', [
            'nom' => 'Salle du quartier', 'type' => 'salle_publique', 'materiel' => ['halteres', 'velo', 'halteres'],
        ])->assertCreated()->assertJsonPath('data.par_defaut', false)->json('data');
        $this->assertSame(['aucun', 'halteres', 'velo'], $salle['materiel'], '« aucun » toujours présent, sans doublon.');

        // Désigner un autre lieu par défaut retire le drapeau au précédent.
        $this->putJson("/api/sport/lieux/{$salle['id']}", ['par_defaut' => true, 'nom' => 'Ma salle'])
            ->assertOk()
            ->assertJsonPath('data.par_defaut', true)
            ->assertJsonPath('data.nom', 'Ma salle');

        $liste = $this->getJson('/api/sport/lieux')->assertOk()->json('data');
        $this->assertSame(['Ma salle', 'Chez moi'], array_column($liste, 'nom'), 'Le lieu par défaut en premier.');
        $this->assertSame([true, false], array_column($liste, 'par_defaut'));

        // Supprimer le lieu par défaut le transmet au plus ancien des restants.
        $this->deleteJson("/api/sport/lieux/{$salle['id']}")->assertOk()->assertJsonPath('message', 'Lieu supprimé.');
        $this->getJson('/api/sport/lieux')->assertJsonPath('data.0.id', $maison['id'])->assertJsonPath('data.0.par_defaut', true);
    }

    public function test_validation_des_lieux(): void
    {
        $this->membre();

        $this->postJson('/api/sport/lieux', ['nom' => '', 'type' => 'plage', 'materiel' => ['jetpack']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nom', 'type', 'materiel.0']);
    }

    public function test_le_lieu_dun_autre_compte_est_introuvable(): void
    {
        $autre = User::factory()->create();
        $lieu = SportLieu::query()->create(['user_id' => $autre->id, 'nom' => 'Sa salle', 'type' => 'salle_privee', 'materiel' => ['aucun'], 'par_defaut' => true]);

        $this->membre();

        $this->putJson("/api/sport/lieux/{$lieu->id}", ['nom' => 'Volé'])->assertNotFound()->assertJsonPath('message', 'Introuvable.');
        $this->deleteJson("/api/sport/lieux/{$lieu->id}")->assertNotFound();
        $this->getJson('/api/sport/lieux')->assertJsonCount(0, 'data');

        // Et il ne peut servir ni à générer ni à enregistrer une séance.
        $this->postJson('/api/sport/sessions/generate', ['duration_min' => 30, 'lieu_id' => $lieu->id])
            ->assertStatus(422)->assertJsonValidationErrors(['lieu_id']);
        $this->assertSame('Sa salle', $lieu->fresh()->nom);
    }

    public function test_la_configuration_expose_le_catalogue_du_materiel(): void
    {
        $this->membre();

        $data = $this->getJson('/api/sport/config')->assertOk()->json('data');

        $catalogue = collect($data['materiel_catalogue'])->keyBy('valeur');
        $this->assertSame(['valeur' => 'tapis', 'libelle' => 'Tapis de sol', 'categorie' => 'accessoire'], $catalogue['tapis']);
        $this->assertSame('Vélo d’appartement / home trainer', $catalogue['velo']['libelle']);
        $this->assertSame('cardio', $catalogue['rameur']['categorie']);
        $this->assertSame('aucun', $catalogue['aucun']['categorie']);
        $this->assertCount(21, $data['materiel_catalogue']);

        $this->assertSame(['aucun', 'tapis'], $data['materiel_par_type']['maison']);
        $this->assertSame(['aucun'], $data['materiel_par_type']['exterieur']);
        $this->assertNotContains('piscine', $data['materiel_par_type']['salle_publique']);
        $this->assertContains('piscine', $data['materiel_par_type']['salle_privee']);
        $this->assertNotContains('velo_route', $data['materiel_par_type']['salle_privee']);

        // Les vocabulaires historiques restent là, enrichis.
        $this->assertContains('elliptique', array_column($data['vocab']['types_sport'], 'key'));
        $this->assertContains('tapis_course', array_column($data['vocab']['materiel'], 'key'));
    }

    // ------------------------------------------------------------------------------------
    // Génération
    // ------------------------------------------------------------------------------------

    public function test_la_generation_utilise_le_materiel_du_lieu(): void
    {
        $this->catalogues();
        $this->membre();

        $lieu = $this->postJson('/api/sport/lieux', ['nom' => 'Garage', 'type' => 'maison', 'materiel' => ['kettlebell']])->json('data');

        $data = $this->postJson('/api/sport/sessions/generate', [
            'duration_min' => 45, 'mode' => 'regles', 'sport_type' => 'musculation', 'goal' => 'prise_de_muscle',
            // Le lieu l'emporte sur lieu / equipment envoyés en même temps.
            'lieu_id' => $lieu['id'], 'lieu' => 'salle_privee', 'equipment' => ['barre', 'machine'],
        ])->assertOk()->json('data');

        $this->assertSame($lieu['id'], $data['lieu_id']);
        $this->assertSame('Garage', $data['lieu_nom']);
        $this->assertSame('maison', $data['lieu']);
        $this->assertSame(['aucun', 'kettlebell'], $data['equipment']);

        foreach ($data['blocks'] as $block) {
            foreach ($block['exercises'] as $ex) {
                $this->assertContains($ex['equipment'], ['aucun', 'kettlebell'], $ex['name']);
                $this->assertIsArray($ex['prescription'], $ex['name']);
                $this->assertNotSame('', $ex['resume']);
                $this->assertNotEmpty($ex['etapes']);
                if ($ex['category'] === 'force' && $ex['reps'] !== null) {
                    $this->assertSame(2, $ex['prescription']['rir'], 'Intermédiaire : 2 répétitions en réserve.');
                    $this->assertSame('3-0-1', $ex['prescription']['tempo']);
                    $this->assertNotNull($ex['prescription']['charge_conseil']);
                    $this->assertGreaterThanOrEqual(8, $ex['reps']);
                    $this->assertLessThanOrEqual(12, $ex['reps']);
                    $this->assertSame('travail', $ex['etapes'][0]['type']);
                    $this->assertNull($ex['etapes'][0]['duree_sec']);
                }
            }
        }
    }

    public function test_sans_lieu_le_lieu_par_defaut_sert_sinon_le_comportement_historique(): void
    {
        $this->catalogues();
        $this->membre();

        // Pas encore de lieu : comportement historique, pas de lieu attaché.
        $this->postJson('/api/sport/sessions/generate', ['duration_min' => 30, 'mode' => 'regles'])
            ->assertOk()->assertJsonPath('data.lieu_id', null)->assertJsonPath('data.lieu_nom', null);

        $lieu = $this->postJson('/api/sport/lieux', ['nom' => 'Parc', 'type' => 'exterieur'])->json('data');

        $this->postJson('/api/sport/sessions/generate', ['duration_min' => 30, 'mode' => 'regles'])
            ->assertOk()
            ->assertJsonPath('data.lieu_id', $lieu['id'])
            ->assertJsonPath('data.lieu', 'exterieur');

        // Une ancienne application qui envoie son matériel garde la main.
        $this->postJson('/api/sport/sessions/generate', ['duration_min' => 30, 'mode' => 'regles', 'equipment' => ['halteres']])
            ->assertOk()
            ->assertJsonPath('data.lieu_id', null);
    }

    public function test_cardio_impossible_au_lieu_propose_une_alternative_expliquee(): void
    {
        $this->catalogues();
        $this->membre();

        $lieu = $this->postJson('/api/sport/lieux', ['nom' => 'Salle du quartier', 'type' => 'salle_publique', 'materiel' => ['halteres', 'elliptique']])->json('data');

        $data = $this->postJson('/api/sport/sessions/generate', [
            'duration_min' => 40, 'mode' => 'regles', 'sport_type' => 'velo', 'lieu_id' => $lieu['id'],
        ])->assertOk()->json('data');

        $this->assertSame('elliptique', $data['sport_type']);
        $this->assertSame('Elliptique', $data['sport_name']);
        $this->assertStringContainsString('Pas de vélo dans « Salle du quartier »', implode(' ', $data['warnings']));
        $this->assertSame('elliptique', $data['blocks'][1]['exercises'][0]['equipment']);

        // Rien de cardio du tout : circuit au poids du corps.
        $vide = $this->postJson('/api/sport/lieux', ['nom' => 'Bureau', 'type' => 'salle_privee', 'materiel' => []])->json('data');
        $circuit = $this->postJson('/api/sport/sessions/generate', [
            'duration_min' => 30, 'mode' => 'regles', 'sport_type' => 'natation', 'lieu_id' => $vide['id'],
        ])->assertOk()->json('data');
        $this->assertStringContainsString('circuit au poids du corps', implode(' ', $circuit['warnings']));
        $this->assertSame('hiit', $circuit['sport_type']);
    }

    public function test_genoux_a_menager_pas_de_course_en_remplacement_du_velo(): void
    {
        $this->catalogues();
        $this->membre();

        $maison = $this->postJson('/api/sport/lieux', ['nom' => 'Maison', 'type' => 'maison', 'materiel' => ['aucun', 'tapis']])->json('data');

        $data = $this->postJson('/api/sport/sessions/generate', [
            'duration_min' => 30, 'mode' => 'regles', 'sport_type' => 'velo', 'lieu_id' => $maison['id'],
            'zones_a_eviter' => ['genoux'],
        ])->assertOk()->json('data');

        $this->assertNotSame('course_a_pied', $data['sport_type']);
        $this->assertSame('marche', $data['sport_type']);
        $this->assertStringContainsString('Pas de vélo dans « Maison » : séance de marche', implode(' ', $data['warnings']));
    }

    public function test_le_velo_fractionne_porte_tr_min_resistance_et_zone_personnalisee(): void
    {
        $this->catalogues();
        $this->membre(40);

        $lieu = $this->postJson('/api/sport/lieux', ['nom' => 'Salon', 'type' => 'maison', 'materiel' => ['velo']])->json('data');

        $data = $this->postJson('/api/sport/sessions/generate', [
            'duration_min' => 45, 'mode' => 'ia', 'sport_type' => 'velo', 'goal' => 'perte_de_gras', 'level' => 'intermediaire', 'lieu_id' => $lieu['id'],
        ])->assertOk()->json('data');

        $this->assertSame('regles', $data['generated_by']);
        $principal = end($data['blocks'][1]['exercises']);
        $this->assertSame('intervalles', $principal['prescription']['type']);
        $this->assertSame([100, 110], $principal['prescription']['cible']['cadence_rpm']);
        $this->assertSame([162, 180], $principal['prescription']['cible']['fc_bpm'], 'Zone 5 à 40 ans (FC max 180).');
        $this->assertStringContainsString('à 100–110 tr/min · résistance 7–8/10 · zone 5 (162–180 bpm) — récup 1 min 30 à 80–85 tr/min', $principal['resume']);
        $this->assertStringEndsWith('· 162–180 bpm', $principal['etapes'][0]['cible']);

        $total = 0;
        foreach ($data['blocks'] as $block) {
            foreach ($block['exercises'] as $ex) {
                foreach ($ex['etapes'] as $etape) {
                    $total += $etape['duree_sec'];
                }
            }
        }
        $this->assertEqualsWithDelta(45 * 60, $total, 45 * 6);
        $this->assertGreaterThan(0, $data['calories_estimate']);
    }

    // ------------------------------------------------------------------------------------
    // IA
    // ------------------------------------------------------------------------------------

    private function avecIa(?array $payload = null): FakeLlmWorkoutClient
    {
        config(['services.anthropic.api_key' => 'sk-ant-test', 'services.llm.provider' => 'anthropic', 'llm.provider' => 'anthropic']);
        $fake = FakeLlmWorkoutClient::canned($payload);
        $this->app->instance(LlmWorkoutClient::class, $fake);

        return $fake;
    }

    public function test_lia_choisit_les_exercices_et_le_serveur_complete_la_prescription(): void
    {
        $this->catalogues();
        $this->membre();
        $squat = \App\Models\Exercise::query()->where('slug', 'like', '%squat%')->where('equipment', 'aucun')->where('category', 'force')->firstOrFail();

        $fake = $this->avecIa([
            'title' => 'Jambes',
            'sport_type' => 'musculation',
            'intensity' => 'moderee',
            'calories_estimate' => 150,
            'explication' => ['Test.'],
            'warnings' => [],
            'blocks' => [
                ['key' => 'principal', 'exercises' => [
                    ['exercise_id' => $squat->id, 'name' => $squat->name, 'sets' => 3, 'reps' => 12, 'rest_sec' => 60, 'rir' => 1, 'tempo' => '3-1-1', 'consigne' => 'Pousse dans les talons.'],
                    ['exercise_id' => $squat->id, 'name' => $squat->name, 'sets' => 3, 'reps' => 12, 'rest_sec' => 60, 'tempo' => 'lentement', 'consigne' => '<b>ok</b>'],
                ]],
            ],
        ]);
        $this->assertTrue(app(WorkoutAiGenerator::class)->iaConfigured());

        $data = $this->postJson('/api/sport/sessions/generate', ['duration_min' => 30, 'mode' => 'ia', 'sport_type' => 'musculation', 'level' => 'intermediaire', 'goal' => 'forme'])
            ->assertOk()->json('data');

        $this->assertSame(1, $fake->generateCalls);
        $this->assertSame('ia', $data['generated_by']);
        [$a, $b] = $data['blocks'][1]['exercises'];
        $this->assertSame(1, $a['prescription']['rir']);
        $this->assertSame('3-1-1', $a['prescription']['tempo']);
        $this->assertSame('Pousse dans les talons.', $a['prescription']['consigne']);
        $this->assertSame('2-0-2', $b['prescription']['tempo'], 'Un tempo illisible est remplacé par celui de l’objectif.');
        $this->assertSame(2, $b['prescription']['rir'], 'Sans RIR de l’IA, celui du niveau.');
        $this->assertSame('ok', $b['prescription']['consigne']);
    }

    public function test_en_mode_ia_le_cardio_passe_par_les_modeles_sans_appeler_lia(): void
    {
        $this->catalogues();
        $this->membre();
        $fake = $this->avecIa();

        $data = $this->postJson('/api/sport/sessions/generate', ['duration_min' => 40, 'mode' => 'ia', 'sport_type' => 'rameur'])
            ->assertOk()->json('data');

        $this->assertSame(0, $fake->generateCalls);
        $this->assertSame('regles', $data['generated_by']);
        $this->assertSame(WorkoutAiGenerator::EXPLICATION_CARDIO, $data['explication'][0]);
        $this->assertNotNull($data['blocks'][1]['exercises'][0]['prescription']['cible']['cadence_coups_min']);
    }

    public function test_le_schema_compact_reste_minimal(): void
    {
        $exercise = WorkoutProposalSchema::compactJson()['properties']['blocks']['items']['properties']['exercises']['items'];

        $this->assertSame(['exercise_id', 'name', 'sets', 'reps', 'duration_sec', 'rest_sec'], $exercise['required'], 'rir / tempo / consigne restent facultatifs.');
        $this->assertEqualsCanonicalizing(['exercise_id', 'name', 'sets', 'reps', 'duration_sec', 'rest_sec', 'rir', 'tempo', 'consigne'], array_keys($exercise['properties']));
    }

    // ------------------------------------------------------------------------------------
    // Enregistrement et lecture
    // ------------------------------------------------------------------------------------

    public function test_la_prescription_est_enregistree_nettoyee_et_relue(): void
    {
        $this->catalogues();
        $this->membre(40);
        $lieu = $this->postJson('/api/sport/lieux', ['nom' => 'Salon', 'type' => 'maison', 'materiel' => ['velo']])->json('data');

        $proposal = $this->postJson('/api/sport/sessions/generate', [
            'duration_min' => 30, 'mode' => 'regles', 'sport_type' => 'velo', 'goal' => 'perte_de_gras', 'level' => 'intermediaire', 'lieu_id' => $lieu['id'],
        ])->json('data');

        // Un client malveillant ou périmé : battements inventés, clé inconnue.
        $proposal['blocks'][1]['exercises'][0]['prescription']['cible']['fc_bpm'] = [1, 2];
        $proposal['blocks'][1]['exercises'][0]['prescription']['pirate'] = 'x';

        $session = $this->postJson('/api/sport/sessions', array_merge($proposal, ['date' => now()->toDateString()]))
            ->assertCreated()
            ->assertJsonPath('data.lieu_id', $lieu['id'])
            ->assertJsonPath('data.lieu_nom', 'Salon')
            ->json('data');

        $ex = collect($session['exercises'])->firstWhere('block', 'principal');
        $this->assertNotSame([1, 2], $ex['prescription']['cible']['fc_bpm']);
        $this->assertArrayNotHasKey('pirate', $ex['prescription']);
        $this->assertSame($proposal['blocks'][1]['exercises'][0]['resume'], $ex['resume']);
        $this->assertSame($proposal['blocks'][1]['exercises'][0]['etapes'], $ex['etapes']);

        $this->getJson("/api/sport/sessions/{$session['id']}")->assertOk()->assertJsonPath('data.exercises.1.resume', $ex['resume']);
        $this->getJson('/api/sport/sessions')->assertOk()->assertJsonPath('data.0.lieu_nom', 'Salon');

        // Le lieu supprimé, la séance garde son type de lieu et perd seulement le lien.
        $this->deleteJson("/api/sport/lieux/{$lieu['id']}")->assertOk();
        $this->getJson("/api/sport/sessions/{$session['id']}")
            ->assertJsonPath('data.lieu_id', null)
            ->assertJsonPath('data.lieu_nom', null)
            ->assertJsonPath('data.lieu', 'maison');
    }

    public function test_une_seance_davant_la_v2_reste_lisible(): void
    {
        $user = $this->membre();
        $session = WorkoutSession::query()->create(['user_id' => $user->id, 'date' => now()->toDateString(), 'title' => 'Ancienne', 'duration_min' => 30]);
        WorkoutExercise::query()->create(['session_id' => $session->id, 'name' => 'Fractionné', 'sets' => 10, 'duration_sec' => 30, 'rest_sec' => 112, 'position' => 1]);
        WorkoutExercise::query()->create(['session_id' => $session->id, 'name' => 'Squat', 'sets' => 3, 'reps' => 12, 'rest_sec' => 90, 'position' => 2]);

        $data = $this->getJson("/api/sport/sessions/{$session->id}")->assertOk()->json('data');

        $this->assertNull($data['exercises'][0]['prescription']);
        $this->assertSame('10 séries de 30 s · repos 1 min 52', $data['exercises'][0]['resume']);
        $this->assertSame('3 séries de 12 répétitions · repos 1 min 30', $data['exercises'][1]['resume']);
        $this->assertSame('travail', $data['exercises'][1]['etapes'][0]['type']);
    }

    public function test_le_calendrier_accepte_un_lieu(): void
    {
        $this->membre();
        $lieu = $this->postJson('/api/sport/lieux', ['nom' => 'Piscine municipale', 'type' => 'salle_publique', 'materiel' => ['piscine']])->json('data');

        $this->postJson('/api/sport/calendar', [
            'date' => now()->addDay()->toDateString(), 'sport_name' => 'Natation', 'planned_duration_min' => 45, 'lieu_id' => $lieu['id'],
        ])->assertCreated()
            ->assertJsonPath('data.lieu_id', $lieu['id'])
            ->assertJsonPath('data.lieu_nom', 'Piscine municipale')
            ->assertJsonPath('data.lieu', 'salle_publique');
    }

    // ------------------------------------------------------------------------------------
    // Reprise des profils
    // ------------------------------------------------------------------------------------

    public function test_la_reprise_cree_un_lieu_par_defaut_depuis_le_profil(): void
    {
        $a = Profile::factory()->create(['sport_lieu' => 'salle_privee', 'sport_materiel' => ['halteres', 'tapis']]);
        $b = Profile::factory()->create(['sport_lieu' => 'exterieur', 'sport_materiel' => null]);
        $c = Profile::factory()->create(['sport_lieu' => null, 'sport_materiel' => null]);

        $migration = require database_path('migrations/2026_10_07_140010_reprendre_lieux_des_profils.php');
        $migration->up();
        $migration->up(); // idempotente

        $lieuA = SportLieu::query()->where('user_id', $a->user_id)->sole();
        $this->assertSame('Salle privée', $lieuA->nom);
        $this->assertTrue($lieuA->par_defaut);
        $this->assertEqualsCanonicalizing(['aucun', 'halteres', 'tapis', 'tapis_course'], $lieuA->materiel, 'L’ancien « tapis » était un tapis de course.');

        $this->assertSame(['aucun'], SportLieu::query()->where('user_id', $b->user_id)->sole()->materiel);
        $this->assertFalse(SportLieu::query()->where('user_id', $c->user_id)->exists());
    }
}
