<?php

namespace App\Services\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlockParam;
use Anthropic\RequestOptions;
use App\Contracts\LlmVisionClient;
use App\Exceptions\LlmUnavailableException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconnaissance d'assiette par l'API Claude, qui accepte l'image en base64.
 *
 * Même structure d'appel que le coach sportif : relèvement de la limite d'exécution PHP avant
 * l'appel, sortie contrainte par un schéma JSON, et toute erreur convertie en
 * LlmUnavailableException pour que l'appelant retombe sur la saisie manuelle.
 */
class AnthropicVisionClient implements LlmVisionClient
{
    private ?Client $client = null;

    public function analyzePlate(string $base64Image, string $mediaType, array $context, array $schema): array
    {
        $timeout = (int) config('services.anthropic.vision_timeout', 60);
        LlmExecutionTime::allow($timeout);

        $texte = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\n".PlateVisionPrompt::CLOSING;

        try {
            $message = $this->client()->messages->create(
                maxTokens: (int) config('services.anthropic.vision_max_tokens', 2000),
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ImageBlockParam::with(
                            source: Base64ImageSource::with(data: $base64Image, mediaType: $mediaType),
                        ),
                        TextBlockParam::with(text: $texte),
                    ],
                ]],
                model: (string) config('services.anthropic.model', 'claude-opus-5'),
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
                system: [[
                    'type' => 'text',
                    'text' => PlateVisionPrompt::SYSTEM,
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                requestOptions: RequestOptions::with(timeout: (float) $timeout, maxRetries: 1),
            );
        } catch (RateLimitException $e) {
            $this->fail('quota', $e);
        } catch (AuthenticationException $e) {
            $this->fail('authentification', $e);
        } catch (APIStatusException $e) {
            $this->fail('statut', $e);
        } catch (APIConnectionException $e) {
            $this->fail('reseau', $e);
        } catch (Throwable $e) {
            $this->fail('inconnu', $e);
        }

        return $this->decode($message);
    }

    /** @return array<string, mixed> */
    private function decode(Message $message): array
    {
        foreach ($message->content as $block) {
            $text = is_array($block) ? ($block['text'] ?? null) : ($block->text ?? null);

            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $decoded = json_decode($text, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new LlmUnavailableException('Réponse IA sans contenu exploitable.');
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('services.anthropic.api_key'));
    }

    /**
     * Journalise sans jamais écrire l'image ni le nom des aliments.
     *
     * @throws LlmUnavailableException
     */
    private function fail(string $reason, Throwable $e): never
    {
        Log::warning('Anthropic : reconnaissance de photo indisponible.', [
            'raison' => $reason,
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ]);

        throw new LlmUnavailableException('Reconnaissance indisponible ('.$reason.').', $e);
    }
}
