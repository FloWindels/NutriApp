<?php

namespace App\Services\Llm;

use App\Contracts\LlmVisionClient;
use App\Exceptions\LlmUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconnaissance d'assiette par un modèle multimodal exécuté en local (llava, moondream…).
 *
 * L'image ne quitte pas la machine et l'appel ne coûte rien. Ollama attend l'image en base64
 * nu dans le champ `images` du message, sans préfixe `data:`.
 */
class OllamaVisionClient implements LlmVisionClient
{
    public function analyzePlate(string $base64Image, string $mediaType, array $context, array $schema): array
    {
        $base = rtrim((string) config('services.ollama.base_url'), '/');
        $model = (string) config('services.ollama.vision_model');
        $timeout = (int) config('services.ollama.vision_timeout', 180);

        if ($model === '') {
            throw new LlmUnavailableException('Aucun modèle de vision Ollama configuré.');
        }

        // Un modèle multimodal local dépasse souvent la limite PHP de 30 s.
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
                    ],
                    'messages' => [
                        ['role' => 'system', 'content' => PlateVisionPrompt::SYSTEM],
                        [
                            'role' => 'user',
                            'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                ."\n\n".PlateVisionPrompt::CLOSING,
                            'images' => [$base64Image],
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            $this->fail('Ollama injoignable', $e);
        }

        if ($response->failed()) {
            throw new LlmUnavailableException(
                sprintf('Ollama a répondu %d pour le modèle de vision %s.', $response->status(), $model)
            );
        }

        $content = (string) data_get($response->json(), 'message.content', '');

        if (trim($content) === '') {
            throw new LlmUnavailableException('Ollama a renvoyé une réponse vide.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            Log::warning('Reconnaissance photo (Ollama) : JSON illisible.', [
                'model' => $model,
                'extrait' => mb_substr($content, 0, 200),
            ]);

            throw new LlmUnavailableException('Ollama a renvoyé un JSON illisible.');
        }

        return $decoded;
    }

    private function fail(string $reason, Throwable $e): never
    {
        Log::warning('Reconnaissance photo (Ollama) indisponible : '.$reason, [
            'exception' => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 200),
        ]);

        throw new LlmUnavailableException($reason.'.');
    }
}
