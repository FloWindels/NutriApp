<?php

namespace App\Services\Llm;

use App\Contracts\LlmPromotionsClient;
use App\Exceptions\LlmUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Relevé des promotions par un modèle exécuté en local : aucune donnée ne quitte la machine. */
class OllamaPromotionsClient implements LlmPromotionsClient
{
    public function extractPromotions(array $context, array $schema): array
    {
        $base = rtrim((string) config('services.ollama.base_url'), '/');
        $model = (string) config('services.ollama.model');
        $timeout = (int) config('services.ollama.timeout', 180);

        LlmExecutionTime::allow($timeout);

        try {
            $response = Http::timeout($timeout)->acceptJson()->asJson()->post($base.'/api/chat', [
                'model' => $model,
                'stream' => false,
                'format' => $schema,
                'options' => [
                    'temperature' => (float) config('services.ollama.temperature', 0.3),
                    'num_ctx' => (int) config('services.ollama.num_ctx', 8192),
                ],
                'messages' => [
                    ['role' => 'system', 'content' => PromotionsPrompt::SYSTEM],
                    [
                        'role' => 'user',
                        'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            ."\n\n".PromotionsPrompt::CLOSING,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Promotions IA (Ollama) injoignable.', ['exception' => get_class($e)]);

            throw new LlmUnavailableException('Ollama injoignable.');
        }

        if ($response->failed()) {
            throw new LlmUnavailableException(sprintf('Ollama a répondu %d.', $response->status()));
        }

        $contenu = (string) data_get($response->json(), 'message.content', '');
        $decode = json_decode($contenu, true);

        if (! is_array($decode)) {
            throw new LlmUnavailableException('Ollama a renvoyé un JSON illisible.');
        }

        return $decode;
    }
}
