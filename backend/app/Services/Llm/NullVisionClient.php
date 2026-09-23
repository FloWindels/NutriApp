<?php

namespace App\Services\Llm;

use App\Contracts\LlmVisionClient;
use App\Exceptions\LlmUnavailableException;

/**
 * Client utilisé quand aucun modèle capable de lire une image n'est configuré : la
 * reconnaissance de photo est indisponible et l'utilisateur saisit son repas à la main.
 */
class NullVisionClient implements LlmVisionClient
{
    public function analyzePlate(string $base64Image, string $mediaType, array $context, array $schema): array
    {
        throw new LlmUnavailableException('Reconnaissance de photo non configurée.');
    }
}
