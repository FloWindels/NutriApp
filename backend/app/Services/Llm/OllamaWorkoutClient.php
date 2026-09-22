<?php

namespace App\Services\Llm;

use App\Contracts\LlmWorkoutClient;
use App\Exceptions\LlmUnavailableException;
use App\Services\Sport\WorkoutProposalSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Coach sportif IA — implémentation Ollama (modèle exécuté en local, aucune donnée ne sort
 * de la machine et aucun coût par appel).
 *
 * Même contrat que le client Anthropic : un appel par génération, prompt système identique
 * (les règles de coaching sont partagées, une seule source de vérité), sortie contrainte par
 * un schéma JSON via le paramètre `format` de l'API Ollama. Toute erreur devient une
 * LlmUnavailableException que le générateur attrape pour basculer sur les règles Mavi'oh.
 *
 * Testé avec llama3.2 (3 B) : réponse conforme en 3 à 9 s sur un catalogue d'exercices déjà
 * filtré par matériel disponible.
 */
class OllamaWorkoutClient implements LlmWorkoutClient
{
    public function generate(array $context, array $request, array $catalog): array
    {
        return $this->call(
            AnthropicWorkoutClient::SYSTEM_PROMPT,
            $context + ['demande' => $request, 'catalogue' => $catalog],
            WorkoutProposalSchema::json(),
        );
    }

    public function generateWeekPlan(array $context, array $request): array
    {
        return $this->call(
            AnthropicWorkoutClient::WEEK_SYSTEM_PROMPT,
            $context + ['demande' => $request],
            WorkoutProposalSchema::weekJson(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function call(string $systemPrompt, array $payload, array $schema): array
    {
        $base = rtrim((string) config('services.ollama.base_url'), '/');
        $model = (string) config('services.ollama.model');
        $timeout = (int) config('services.ollama.timeout', 180);

        // Un modèle local met souvent plus de 30 s (la limite PHP par défaut) à répondre :
        // sans cela, la requête meurt en erreur fatale avant même l'expiration du délai HTTP.
        LlmExecutionTime::allow($timeout);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($base.'/api/chat', [
                    'model' => $model,
                    'stream' => false,
                    'format' => $schema,
                    'options' => [
                        'temperature' => (float) config('services.ollama.temperature', 0.3),
                        'num_ctx' => (int) config('services.ollama.num_ctx', 8192),
                    ],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        [
                            'role' => 'user',
                            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                ."\n\n".AnthropicWorkoutClient::CLOSING_SENTENCE,
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            $this->fail('Ollama injoignable', $e);
        }

        if ($response->failed()) {
            throw new LlmUnavailableException(
                sprintf('Ollama a répondu %d pour le modèle %s.', $response->status(), $model)
            );
        }

        $content = (string) data_get($response->json(), 'message.content', '');

        if (trim($content) === '') {
            throw new LlmUnavailableException('Ollama a renvoyé une réponse vide.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            Log::warning('Coach IA (Ollama) : JSON illisible.', [
                'model' => $model,
                'extrait' => mb_substr($content, 0, 200),
            ]);

            throw new LlmUnavailableException('Ollama a renvoyé un JSON illisible.');
        }

        return $decoded;
    }

    private function fail(string $reason, Throwable $e): never
    {
        Log::warning('Coach IA (Ollama) indisponible : '.$reason, [
            'exception' => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 200),
        ]);

        throw new LlmUnavailableException($reason.'.');
    }
}
