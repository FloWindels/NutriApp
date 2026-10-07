<?php

namespace Tests\Feature\Foods;

use App\Contracts\LlmNutritionClient;
use App\Contracts\WebSearchClient;
use App\Exceptions\LlmUnavailableException;
use App\Models\Food;
use App\Models\User;
use App\Services\Search\PageWebSure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RechercheNutritionEnLigneTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $donneesRecues = [];

    private function resultats(): void
    {
        $this->app->instance(WebSearchClient::class, new class implements WebSearchClient
        {
            public function search(string $requete, int $max = 4): array
            {
                return [
                    ['titre' => 'Crème chantilly — valeurs', 'extrait' => 'Pour 100 g : 257 kcal, protéines 2,1 g, glucides 12 g, lipides 22 g.', 'url' => 'https://exemple-nutrition.test/chantilly', 'domaine' => 'exemple-nutrition.test'],
                    ['titre' => 'Recette de chantilly', 'extrait' => 'Fouetter la crème…', 'url' => 'https://recettes.test/chantilly', 'domaine' => 'recettes.test'],
                ];
            }
        });

        // Les pages ne sont pas lues dans ces tests : résolution vers une adresse privée = refus.
        $pages = new PageWebSure;
        $pages->resoudre = fn () => ['10.0.0.1'];
        $this->app->instance(PageWebSure::class, $pages);
    }

    /** @param array<string, mixed>|null $reponse */
    private function modele(?array $reponse): void
    {
        $test = $this;
        $this->app->instance(LlmNutritionClient::class, new class($reponse, $test) implements LlmNutritionClient
        {
            public function __construct(private ?array $reponse, private $test)
            {
            }

            public function extraireValeurs(array $donnees, array $schema): array
            {
                $this->test->noterDonnees($donnees);
                if ($this->reponse === null) {
                    throw new LlmUnavailableException('indisponible');
                }

                return $this->reponse;
            }
        });
    }

    /** @param array<string, mixed> $donnees */
    public function noterDonnees(array $donnees): void
    {
        $this->donneesRecues = $donnees;
    }

    private function valeurs(array $surcharge = []): array
    {
        return array_replace([
            'calories' => 257, 'proteines' => 2.1, 'glucides' => 12, 'lipides' => 22,
            'fibres' => 0, 'sucres' => 11, 'sel' => 0.1, 'portion_g' => 20,
            'nature' => 'produit', 'estimation' => false, 'confiance' => 0.8, 'sources_utilisees' => [1],
        ], $surcharge);
    }

    public function test_reserve_aux_comptes_et_a_l_offre_qui_inclut_l_ia(): void
    {
        $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Chantilly'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->gratuit()->create(), ['*']);
        $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Chantilly'])->assertStatus(402);
    }

    public function test_propose_les_valeurs_trouvees_sans_rien_enregistrer(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->resultats();
        $this->modele($this->valeurs());
        $avant = Food::count();

        $data = $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Crème chantilly', 'marque' => null])
            ->assertOk()
            ->json('data');

        $this->assertSame('internet', $data['source']);
        $this->assertNull($data['food']);
        $this->assertSame(257, (int) $data['proposition']['calories']);
        $this->assertSame(22, (int) $data['proposition']['fat']);
        $this->assertSame('100g', $data['proposition']['per_unit']);
        $this->assertFalse($data['proposition']['estimation']);
        $this->assertSame([['titre' => 'Crème chantilly — valeurs', 'url' => 'https://exemple-nutrition.test/chantilly']], $data['sources']);
        $this->assertSame([], $data['avertissements']);
        $this->assertSame($avant, Food::count());
        // Seul le nom de l'aliment est transmis, avec les extraits : rien sur la personne.
        $this->assertSame(['aliment', 'marque', 'sources'], array_keys($this->donneesRecues));
    }

    public function test_une_energie_fausse_est_corrigee_d_apres_les_sources(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->resultats();
        // Les macros collent à « 100 g : 257 kcal », seule l'énergie est fausse.
        $this->modele($this->valeurs(['calories' => 552]));

        $data = $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Crème chantilly'])->assertOk()->json('data');

        $this->assertSame(257, (int) $data['proposition']['calories']);
        $this->assertSame(22, (int) $data['proposition']['fat']);
        $this->assertStringContainsString('ramenées à 100 g', $data['avertissements'][0]);
    }

    public function test_des_valeurs_lues_par_portion_sont_ramenees_a_100_g(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->app->instance(WebSearchClient::class, new class implements WebSearchClient
        {
            public function search(string $requete, int $max = 4): array
            {
                return [
                    ['titre' => 'Gratin', 'extrait' => 'Gratin dauphinois - 100g Calories 148 kcal Protides 3 g Lipides 8 g Glucides 16 g', 'url' => 'https://calories.test/gratin', 'domaine' => 'calories.test'],
                    ['titre' => 'Plats', 'extrait' => 'Calories (100 g) Gratin dauphinois 250 g 375 kcal', 'url' => 'https://plats.test/terroir', 'domaine' => 'plats.test'],
                ];
            }
        });
        $pages = new PageWebSure;
        $pages->resoudre = fn () => ['10.0.0.1'];
        $this->app->instance(PageWebSure::class, $pages);
        // Le modèle reprend la portion de 250 g : 375 kcal, 7,5 / 40 / 20 g.
        $this->modele($this->valeurs(['calories' => 375, 'proteines' => 7.5, 'glucides' => 40, 'lipides' => 20, 'sources_utilisees' => [2]]));

        $p = $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Gratin dauphinois'])->assertOk()->json('data.proposition');

        $this->assertSame(148, (int) $p['calories']);
        $this->assertEqualsWithDelta(8.0, $p['fat'], 0.1);
        $this->assertEqualsWithDelta(15.8, $p['carbs'], 0.1);
    }

    public function test_seules_les_pages_qui_parlent_de_l_aliment_servent_de_reference(): void
    {
        $sources = [
            ['n' => 1, 'titre' => 'Bjorg muesli sans sucres', 'url' => 'https://calories.test/bjorg-muesli', 'texte' => '100 g : 341 kcal'],
            ['n' => 2, 'titre' => 'Crème brûlée', 'url' => 'https://desserts.test/creme-brulee', 'texte' => '100 g : 290 kcal'],
        ];

        $this->assertSame([], \App\Services\Foods\ValeursNutritionnellesSchema::pagesPertinentes($sources, 'Granola chocolat'));
        $this->assertSame([$sources[1]], \App\Services\Foods\ValeursNutritionnellesSchema::pagesPertinentes($sources, 'Crème brûlée maison'));
    }

    public function test_une_energie_incoherente_avec_les_macros_est_signalee(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->app->instance(WebSearchClient::class, new class implements WebSearchClient
        {
            public function search(string $requete, int $max = 4): array
            {
                return [['titre' => 'Fiche', 'extrait' => 'Valeurs nutritionnelles sans chiffre d’énergie.', 'url' => 'https://fiche.test/x', 'domaine' => 'fiche.test']];
            }
        });
        $pages = new PageWebSure;
        $pages->resoudre = fn () => ['10.0.0.1'];
        $this->app->instance(PageWebSure::class, $pages);
        $this->modele($this->valeurs(['calories' => 552]));

        $data = $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Crème chantilly'])->assertOk()->json('data');

        $this->assertLessThanOrEqual(0.4, $data['proposition']['confiance']);
        $this->assertStringContainsString('ne correspond pas aux macros', $data['avertissements'][0]);
    }

    public function test_sans_source_citee_c_est_une_estimation(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->resultats();
        $this->modele($this->valeurs(['sources_utilisees' => [9], 'nature' => 'plat']));

        $data = $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Gratin maison'])->assertOk()->json('data');

        $this->assertTrue($data['proposition']['estimation']);
        $this->assertSame('plat', $data['proposition']['nature']);
        $this->assertSame([], $data['sources']);
    }

    public function test_un_aliment_deja_au_catalogue_est_rendu_tel_quel(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $food = Food::factory()->create(['name' => 'Crème chantilly', 'brand' => null]);
        $this->resultats();
        $this->modele(null);

        $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'crème Chantilly'])
            ->assertOk()
            ->assertJsonPath('data.source', 'catalogue')
            ->assertJsonPath('data.food.id', $food->id);
    }

    public function test_modele_indisponible_ou_valeurs_absurdes(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->resultats();

        $this->modele(null);
        $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Chantilly'])->assertOk()->assertJsonPath('data.source', 'indisponible');

        $this->modele($this->valeurs(['proteines' => 60, 'glucides' => 60]));
        $this->postJson('/api/foods/recherche-en-ligne', ['nom' => 'Chantilly'])->assertOk()->assertJsonPath('data.source', 'indisponible');
    }

    public function test_la_lecture_de_page_refuse_le_reseau_interne_et_garde_les_passages_utiles(): void
    {
        $pages = new PageWebSure;
        Http::fake(['*' => Http::response('<html><script>x</script><p>Bla</p><table><tr><td>Énergie</td><td>257 kcal</td></tr><tr><td>Protéines</td><td>2,1 g</td></tr></table></html>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $pages->resoudre = fn () => ['192.168.1.10'];
        $this->assertNull($pages->extraitNutrition('https://interne.test/page'));
        $pages->resoudre = fn () => ['127.0.0.1'];
        $this->assertNull($pages->extraitNutrition('https://localhost/page'));
        $pages->resoudre = fn () => ['93.184.216.34'];
        $this->assertNull($pages->extraitNutrition('http://exemple.test/page'));
        $this->assertNull($pages->extraitNutrition('https://exemple.test:8443/page'));

        $extrait = $pages->extraitNutrition('https://exemple.test/page');
        $this->assertNotNull($extrait);
        $this->assertStringContainsString('257 kcal', $extrait);
        $this->assertStringNotContainsString('<', $extrait);
    }

    public function test_une_redirection_n_est_jamais_suivie(): void
    {
        $pages = new PageWebSure;
        $pages->resoudre = fn () => ['93.184.216.34'];
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://10.0.0.1/'])]);

        $this->assertNull($pages->extraitNutrition('https://exemple.test/page'));
    }
}
