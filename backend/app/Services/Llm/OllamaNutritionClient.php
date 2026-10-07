<?php

namespace App\Services\Llm;

use App\Contracts\LlmNutritionClient;
use App\Exceptions\LlmBusyException;
use App\Exceptions\LlmUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Lecture des valeurs nutritionnelles par le modèle local : rien ne quitte le serveur. */
class OllamaNutritionClient implements LlmNutritionClient
{
    public function extraireValeurs(array $donnees, array $schema): array
    {
        $base = rtrim((string) config('services.ollama.base_url'), '/');
        $model = (string) config('services.ollama.model');
        $timeout = (int) config('services.ollama.timeout', 180);

        LlmExecutionTime::allow($timeout);

        try {
            $response = OllamaGate::run($timeout, fn () => Http::timeout($timeout)->acceptJson()->asJson()->post($base.'/api/chat', [
                'model' => $model,
                'stream' => false,
                'format' => $schema,
                'options' => [
                    'temperature' => 0.1,
                    'num_ctx' => (int) config('services.ollama.num_ctx', 8192),
                    'num_predict' => 400,
                ],
                'messages' => [
                    ['role' => 'system', 'content' => NutritionPrompt::SYSTEM],
                    [
                        'role' => 'user',
                        'content' => json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            ."\n\n".NutritionPrompt::CLOSING,
                    ],
                ],
            ]));
        } catch (LlmBusyException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Valeurs nutritionnelles (Ollama) : modèle injoignable.', ['exception' => get_class($e)]);

            throw new LlmUnavailableException('Ollama injoignable.');
        }

        if ($response->failed()) {
            throw new LlmUnavailableException(sprintf('Ollama a répondu %d.', $response->status()));
        }

        $decode = json_decode((string) data_get($response->json(), 'message.content', ''), true);

        if (! is_array($decode)) {
            throw new LlmUnavailableException('Ollama a renvoyé un JSON illisible.');
        }

        return $decode;
    }
}
