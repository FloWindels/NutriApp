<?php

namespace Tests\Support;

use App\Contracts\LlmVisionClient;
use App\Exceptions\LlmUnavailableException;

/**
 * Client vision factice : aucune requête réseau n'est possible pendant les tests, et le SDK
 * Anthropic ne passe pas par la façade Http (donc Http::preventStrayRequests ne le couvre pas).
 *
 * Utilisation : `$this->app->instance(LlmVisionClient::class, FakePlateVisionClient::canned([...]))`.
 */
class FakePlateVisionClient implements LlmVisionClient
{
    public int $calls = 0;

    public ?string $lastImage = null;

    public ?string $lastMediaType = null;

    public ?array $lastContext = null;

    /** @param array<string, mixed>|null $payload */
    public function __construct(
        private ?array $payload = null,
        private bool $throwing = false,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function canned(array $payload): self
    {
        return new self($payload);
    }

    public static function throwing(): self
    {
        return new self(null, true);
    }

    public function analyzePlate(string $base64Image, string $mediaType, array $context, array $schema): array
    {
        $this->calls++;
        $this->lastImage = $base64Image;
        $this->lastMediaType = $mediaType;
        $this->lastContext = $context;

        if ($this->throwing) {
            throw new LlmUnavailableException('Reconnaissance indisponible (test).');
        }

        return $this->payload ?? [
            'aliments' => [[
                'nom' => 'Riz blanc cuit',
                'marque' => null,
                'quantite' => 150,
                'unite' => 'g',
                'confiance' => 0.8,
                'calories' => 195,
                'proteines' => 4,
                'glucides' => 42,
                'lipides' => 0.5,
            ]],
            'description' => 'Une assiette de riz.',
            'confiance_globale' => 0.8,
            'avertissements' => [],
        ];
    }
}
