<?php

namespace App\Services\Llm;

use App\Contracts\LlmWorkoutClient;
use App\Exceptions\LlmUnavailableException;

/**
 * Client utilisé quand aucune clé Anthropic n'est configurée : la génération IA
 * est indisponible et le générateur bascule sur les règles Mavi'oh.
 */
class NullWorkoutClient implements LlmWorkoutClient
{
    public function generate(array $context, array $request, array $catalog): array
    {
        throw new LlmUnavailableException('Génération IA non configurée (ANTHROPIC_API_KEY absente).');
    }

    public function generateWeekPlan(array $context, array $request): array
    {
        throw new LlmUnavailableException('Génération IA non configurée (ANTHROPIC_API_KEY absente).');
    }
}
