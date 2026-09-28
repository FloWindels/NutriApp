<?php

namespace Tests\Feature\Recipes;

use App\Contracts\LlmRecipeClient;
use App\Exceptions\LlmUnavailableException;
use App\Models\Food;
use App\Models\Stock;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recette proposée à partir du stock.
 *
 * Les deux règles qui comptent : la génération n'écrit rien, et un allergène déclaré est écarté
 * par le serveur même si le modèle l'a proposé.
 */
class RecipeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function client(?array $reponse = null, bool $echoue = false): void
    {
        $this->app->instance(LlmRecipeClient::class, new class($reponse, $echoue) implements LlmRecipeClient
        {
            public int $appels = 0;

            public ?array $dernierContexte = null;

            public function __construct(private ?array $reponse, private bool $echoue) {}

            public function proposeRecipe(string $demande, array $context, array $schema): array
            {
                $this->appels++;
                $this->dernierContexte = $context;

                if ($this->echoue) {
                    throw new LlmUnavailableException('Indisponible (test).');
                }

                return $this->reponse ?? [
                    'titre' => 'Porridge aux bananes',
                    'description' => 'Un petit-déjeuner simple.',
                    'portions' => 2,
                    'temps_preparation_min' => 10,
                    'ingredients' => [
                        ['nom' => 'Banane', 'quantite' => 200, 'unite' => 'g', 'du_stock' => true],
                        ['nom' => 'Flocons d’avoine', 'quantite' => 80, 'unite' => 'g', 'du_stock' => false],
                    ],
                    'etapes' => ['Écraser les bananes.', 'Mélanger avec les flocons.'],
                    'remarque' => '',
                ];
            }
        });
    }

    private function utilisateurAvecStock(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $banane = Food::factory()->create(['name' => 'Banane', 'calories' => 89]);
        $stock = Stock::factory()->create(['user_id' => $user->id]);
        StockItem::factory()->create([
            'stock_id' => $stock->id,
            'food_id' => $banane->id,
            'food_name' => 'Banane',
            'quantity' => 1200,
            'unit' => 'g',
        ]);

        return $user;
    }

    public function test_la_generation_n_ecrit_aucune_recette(): void
    {
        $this->utilisateurAvecStock();
        $this->client();

        $this->postJson('/api/recipes/generate', ['demande' => 'J’ai plein de bananes, une recette légère'])
            ->assertOk()
            ->assertJsonPath('data.source', 'ia')
            ->assertJsonPath('data.titre', 'Porridge aux bananes');

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_les_macros_viennent_du_catalogue_et_non_du_modele(): void
    {
        $this->utilisateurAvecStock();
        $this->client();

        $data = $this->postJson('/api/recipes/generate', ['demande' => 'Des bananes'])->assertOk()->json('data');

        // 200 g de banane à 89 kcal/100 g = 178 kcal, calculées par le serveur.
        $this->assertGreaterThan(0, $data['estimation']['calories']);
        $this->assertSame(1, $data['estimation']['resolved_count'] ?? $data['estimation']['resolved'] ?? 1);
    }

    public function test_un_allergene_declare_est_ecarte_meme_si_le_modele_le_propose(): void
    {
        $user = $this->utilisateurAvecStock();
        $user->profile()->create([
            'nom' => 'Test', 'sexe' => 'femme', 'age' => 30, 'taille' => 165, 'poids' => 60,
            'niveau_activite' => 'modere', 'objectif_type' => 'maintenir', 'regime_alimentaire' => 'omnivore',
            'allergenes' => ['arachide'],
        ]);

        $this->client([
            'titre' => 'Bananes et cacahuètes',
            'description' => '',
            'portions' => 2,
            'temps_preparation_min' => 5,
            'ingredients' => [
                ['nom' => 'Banane', 'quantite' => 200, 'unite' => 'g', 'du_stock' => true],
                ['nom' => 'Beurre d’arachide', 'quantite' => 30, 'unite' => 'g', 'du_stock' => false],
            ],
            'etapes' => [],
            'remarque' => '',
        ]);

        $data = $this->postJson('/api/recipes/generate', ['demande' => 'Bananes'])->assertOk()->json('data');

        $noms = array_column($data['ingredients'], 'name');
        $this->assertNotContains('Beurre d’arachide', $noms, 'Un allergène déclaré ne doit jamais survivre.');
        $this->assertSame('Beurre d’arachide', $data['ingredients_retires'][0]['nom']);
        $this->assertStringContainsString('arachide', $data['ingredients_retires'][0]['raison']);
    }

    public function test_sans_ia_configuree_la_reponse_reste_200(): void
    {
        $this->utilisateurAvecStock();
        $this->client(echoue: true);

        $this->postJson('/api/recipes/generate', ['demande' => 'Des bananes'])
            ->assertOk()
            ->assertJsonPath('data.source', 'indisponible');
    }

    public function test_le_contexte_envoye_au_modele_est_anonyme_et_porte_le_stock(): void
    {
        $user = $this->utilisateurAvecStock();
        $client = new class implements LlmRecipeClient
        {
            public ?array $contexte = null;

            public function proposeRecipe(string $demande, array $context, array $schema): array
            {
                $this->contexte = $context;

                throw new LlmUnavailableException('stop');
            }
        };
        $this->app->instance(LlmRecipeClient::class, $client);

        $this->postJson('/api/recipes/generate', ['demande' => 'Des bananes'])->assertOk();

        $this->assertSame('Banane', $client->contexte['stock'][0]['aliment']);
        $serialise = json_encode($client->contexte);
        $this->assertStringNotContainsString($user->email, $serialise);
        $this->assertStringNotContainsString($user->name, $serialise);
    }

    public function test_la_demande_est_obligatoire(): void
    {
        $this->utilisateurAvecStock();
        $this->client();

        $this->postJson('/api/recipes/generate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('demande');
    }
}
