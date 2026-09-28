<?php

namespace App\Services\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;
use App\Contracts\LlmRecipeClient;
use App\Exceptions\LlmUnavailableException;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Rédaction de recette par l'API Claude. */
class AnthropicRecipeClient implements LlmRecipeClient
{
    private ?Client $client = null;

    public function proposeRecipe(string $demande, array $context, array $schema): array
    {
        $timeout = (int) config('services.anthropic.timeout', 90);
        LlmExecutionTime::allow($timeout);

        $payload = json_encode(['demande' => $demande] + $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\n".RecipePrompt::CLOSING;

        try {
            $message = $this->client()->messages->create(
                maxTokens: (int) config('services.anthropic.max_tokens', 8000),
                messages: [['role' => 'user', 'content' => $payload]],
                model: (string) config('services.anthropic.model', 'claude-opus-5'),
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
                system: [['type' => 'text', 'text' => RecipePrompt::SYSTEM, 'cacheControl' => ['type' => 'ephemeral']]],
                requestOptions: RequestOptions::with(timeout: (float) $timeout, maxRetries: 1),
            );
        } catch (RateLimitException|AuthenticationException|APIStatusException|APIConnectionException $e) {
            $this->fail($e);
        } catch (Throwable $e) {
            $this->fail($e);
        }

        foreach ($message->content as $bloc) {
            $texte = is_array($bloc) ? ($bloc['text'] ?? null) : ($bloc->text ?? null);

            if (is_string($texte)) {
                $decode = json_decode($texte, true);

                if (is_array($decode)) {
                    return $decode;
                }
            }
        }

        throw new LlmUnavailableException('Réponse IA sans contenu exploitable.');
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('services.anthropic.api_key'));
    }

    private function fail(Throwable $e): never
    {
        Log::warning('Anthropic : génération de recette indisponible.', [
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ]);

        throw new LlmUnavailableException('Génération de recette indisponible.', $e);
    }
}
