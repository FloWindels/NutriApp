<?php

namespace Tests\Feature\Sport;

use App\Contracts\LlmWorkoutClient;
use App\Services\Llm\AnthropicWorkoutClient;
use App\Services\Llm\NullWorkoutClient;
use App\Services\Llm\OllamaWorkoutClient;
use App\Support\LlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Coach IA local (Ollama) : choix du fournisseur, appel HTTP, et repli sur les règles
 * Mavi'oh dès que la génération échoue.
 */
class OllamaCoachTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://ollama.test:11434';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.ollama.base_url', self::BASE);
        config()->set('services.ollama.model', 'llama3.2');
        config()->set('services.ollama.timeout', 5);
    }

    private function useOllama(): void
    {
        config()->set('services.anthropic.api_key', null);
        config()->set('services.ollama.enabled', true);
        config()->set('services.llm.provider', 'auto');
    }

    public function test_auto_choisit_ollama_quand_anthropic_na_pas_de_cle(): void
    {
        $this->useOllama();

        $this->assertSame(LlmProvider::OLLAMA, LlmProvider::current());
        $this->assertTrue(LlmProvider::isConfigured());
        $this->assertSame('llama3.2', LlmProvider::modelName());
        $this->assertInstanceOf(OllamaWorkoutClient::class, app(LlmWorkoutClient::class));
    }

    public function test_auto_prefere_anthropic_quand_une_cle_est_configuree(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        config()->set('services.ollama.enabled', true);
        config()->set('services.llm.provider', 'auto');

        $this->assertSame(LlmProvider::ANTHROPIC, LlmProvider::current());
        $this->assertInstanceOf(AnthropicWorkoutClient::class, app(LlmWorkoutClient::class));
    }

    public function test_provider_force_a_ollama_ignore_la_cle_anthropic(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        config()->set('services.ollama.enabled', true);
        config()->set('services.llm.provider', 'ollama');

        $this->assertSame(LlmProvider::OLLAMA, LlmProvider::current());
        $this->assertInstanceOf(OllamaWorkoutClient::class, app(LlmWorkoutClient::class));
    }

    public function test_sans_fournisseur_configure_le_client_nul_est_utilise(): void
    {
        config()->set('services.anthropic.api_key', null);
        config()->set('services.ollama.enabled', false);
        config()->set('services.llm.provider', 'auto');

        $this->assertSame(LlmProvider::NONE, LlmProvider::current());
        $this->assertFalse(LlmProvider::isConfigured());
        $this->assertNull(LlmProvider::modelName());
        $this->assertInstanceOf(NullWorkoutClient::class, app(LlmWorkoutClient::class));
    }

    public function test_provider_none_desactive_lia_malgre_une_cle(): void
    {
        config()->set('services.anthropic.api_key', 'sk-ant-test');
        config()->set('services.ollama.enabled', true);
        config()->set('services.llm.provider', 'none');

        $this->assertSame(LlmProvider::NONE, LlmProvider::current());
    }

    public function test_generate_envoie_le_schema_et_decode_la_proposition(): void
    {
        $this->useOllama();

        $proposal = [
            'title' => 'Séance haut du corps',
            'duration_min' => 40,
            'calories_estimate' => 260,
            'explication' => ['Focus haut du corps.'],
            'warnings' => [],
            'blocks' => [
                ['key' => 'echauffement', 'name' => 'Échauffement', 'exercises' => []],
                ['key' => 'principal', 'name' => 'Circuit principal', 'exercises' => [
                    ['exercise_id' => 4, 'name' => 'Rowing haltère un bras', 'sets' => 3, 'reps' => 10],
                ]],
                ['key' => 'retour_au_calme', 'name' => 'Retour au calme', 'exercises' => []],
            ],
        ];

        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => json_encode($proposal)],
                'done' => true,
            ]),
        ]);

        $result = app(LlmWorkoutClient::class)->generate(
            ['profil' => ['age' => 32]],
            ['objectif' => 'perte_de_gras', 'duree_min' => 40],
            [['exercise_id' => 4, 'name' => 'Rowing haltère un bras']],
        );

        $this->assertSame('Séance haut du corps', $result['title']);
        $this->assertCount(3, $result['blocks']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === self::BASE.'/api/chat'
                && $body['model'] === 'llama3.2'
                && $body['stream'] === false
                && is_array($body['format'])                       // schéma JSON transmis
                && $body['messages'][0]['role'] === 'system'
                && str_contains($body['messages'][1]['content'], 'perte_de_gras');
        });
    }

    public function test_une_erreur_http_leve_lexception_de_repli(): void
    {
        $this->useOllama();
        Http::fake([self::BASE.'/api/chat' => Http::response('boom', 500)]);

        $this->expectException(\App\Exceptions\LlmUnavailableException::class);

        app(LlmWorkoutClient::class)->generate([], [], []);
    }

    public function test_un_json_illisible_leve_lexception_de_repli(): void
    {
        $this->useOllama();
        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'message' => ['content' => 'Voici ta séance : fais des pompes.'],
            ]),
        ]);

        $this->expectException(\App\Exceptions\LlmUnavailableException::class);

        app(LlmWorkoutClient::class)->generate([], [], []);
    }

    public function test_une_reponse_vide_leve_lexception_de_repli(): void
    {
        $this->useOllama();
        Http::fake([self::BASE.'/api/chat' => Http::response(['message' => ['content' => '   ']])]);

        $this->expectException(\App\Exceptions\LlmUnavailableException::class);

        app(LlmWorkoutClient::class)->generate([], [], []);
    }

    public function test_le_plan_hebdomadaire_utilise_le_schema_dedie(): void
    {
        $this->useOllama();

        Http::fake([
            self::BASE.'/api/chat' => Http::response([
                'message' => ['content' => json_encode([
                    'days' => [['weekday' => 1, 'sport' => 'course_a_pied', 'duration_min' => 30]],
                    'explication' => ['Deux séances suffisent pour démarrer.'],
                ])],
            ]),
        ]);

        $result = app(LlmWorkoutClient::class)->generateWeekPlan([], ['jours' => [1, 4]]);

        $this->assertSame(1, $result['days'][0]['weekday']);
        Http::assertSentCount(1);
    }
}
