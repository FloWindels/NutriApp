<?php

namespace Tests\Feature\Offres;

use App\Enums\Offre;
use App\Models\User;
use Database\Seeders\ExerciseSeeder;
use Database\Seeders\SportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le coach sportif est payant, quel que soit son moteur.
 *
 * Ce qui se paie ici, c'est la séance PROPOSÉE : que les exercices viennent de l'IA ou du
 * moteur de règles, le service rendu est le même, et le repli par règles ne doit pas servir
 * de porte dérobée vers une fonction payante.
 *
 * Ce qui reste gratuit, c'est le SUIVI : enregistrer une activité faite, tenir son calendrier,
 * consigner une séance à la main, relire son historique. Sans quoi l'offre gratuite perdrait
 * le suivi sportif tout entier, ce qui n'a jamais été l'intention.
 */
class VerrouCoachSportifTest extends TestCase
{
    use RefreshDatabase;

    /** Les routes qui font travailler le coach à la place de l'utilisateur. */
    private const ROUTES_PROPOSITION = [
        'séance libre' => ['postJson', '/api/sport/sessions/generate', ['duration_min' => 45]],
        'séance pour un jour planifié' => ['postJson', '/api/sport/calendar/1/propose', []],
        'semaine entière' => ['postJson', '/api/sport/calendar/plan-week', ['days' => [1, 3, 5]]],
    ];

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function routesProposition(): iterable
    {
        foreach (self::ROUTES_PROPOSITION as $nom => $appel) {
            yield $nom => $appel;
        }
    }

    /**
     * @param  array<string, mixed>  $corps
     *
     * @dataProvider routesProposition
     */
    public function test_un_compte_gratuit_ne_peut_pas_faire_proposer_de_seance(string $verbe, string $route, array $corps): void
    {
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $reponse = $this->{$verbe}($route, $corps)->assertStatus(402);

        $this->assertSame('seances', $reponse->json('capacite_requise'));
        $this->assertSame('gratuit', $reponse->json('offre_actuelle'));
    }

    /**
     * Le cœur de la décision : sans IA configurée, le moteur de règles prendrait le relais.
     * Le verrou doit tomber avant, sinon le repli ouvrirait gratuitement ce qui est payant.
     *
     * @param  array<string, mixed>  $corps
     *
     * @dataProvider routesProposition
     */
    public function test_le_repli_par_regles_ne_contourne_pas_le_verrou(string $verbe, string $route, array $corps): void
    {
        config(['llm.provider' => 'none']);
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        $this->{$verbe}($route, array_replace($corps, ['mode' => 'regles']))->assertStatus(402);
    }

    public function test_une_offre_payante_fait_proposer_une_seance_meme_sans_ia(): void
    {
        $this->seed(SportSeeder::class);
        $this->seed(ExerciseSeeder::class);
        config(['llm.provider' => 'none']);

        $user = User::factory()->create();
        $user->forceFill(['offre' => Offre::Complet])->save();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/sport/sessions/generate', ['duration_min' => 45, 'mode' => 'regles'])
            ->assertSuccessful()
            ->assertJsonPath('data.generated_by', 'regles');
    }

    public function test_le_suivi_sportif_reste_gratuit(): void
    {
        $this->seed(SportSeeder::class);
        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);

        // Consulter : le catalogue des sports, sa configuration, son résumé, son calendrier.
        $this->getJson('/api/sport/config')->assertSuccessful();
        $this->getJson('/api/sport/summary')->assertSuccessful();
        $this->getJson('/api/sport/sports')->assertSuccessful();
        $this->getJson('/api/sport/sessions')->assertSuccessful();

        // Estimer les calories d'une activité : un calcul, pas une proposition.
        $this->postJson('/api/sport/calories/estimate', [
            'sport_name' => 'Course à pied',
            'duration_min' => 30,
        ])->assertSuccessful();

        // Enregistrer ce qu'on a fait.
        $this->postJson('/api/sport/activities', [
            'sport_name' => 'Course à pied',
            'duration_min' => 30,
        ])->assertSuccessful();

        // Planifier un jour d'entraînement à la main.
        $plan = $this->postJson('/api/sport/calendar', [
            'date' => now()->addDay()->toDateString(),
            'sport_name' => 'Natation',
            'planned_duration_min' => 45,
        ])->assertSuccessful()->json('data.id');

        // Et dire qu'on l'a faite.
        $this->postJson("/api/sport/calendar/{$plan}/log", ['duration_min' => 45])->assertSuccessful();
    }

    /**
     * Garde-fou : une route de proposition ajoutée demain sans verrou fait échouer la suite.
     * On ne relit pas une liste recopiée, on interroge la table de routage réelle.
     */
    public function test_toute_route_qui_propose_une_seance_porte_son_verrou(): void
    {
        $oublis = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (! str_starts_with($uri, '/api/') || ! str_contains($uri, '/sport/')) {
                continue;
            }

            $propose = str_contains($uri, 'generate')
                || str_contains($uri, 'propose')
                || str_contains($uri, 'plan-week');

            if (! $propose) {
                continue;
            }

            if (! in_array('offre:seances', $route->gatherMiddleware(), true)) {
                $oublis[] = $uri;
            }
        }

        $this->assertSame(
            [],
            $oublis,
            "Ces routes font proposer une séance sans verrou d'offre :\n".implode("\n", $oublis),
        );
    }
}
