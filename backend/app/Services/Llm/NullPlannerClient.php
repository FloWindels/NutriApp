<?php

namespace App\Services\Llm;

use App\Contracts\LlmPlannerClient;
use App\Exceptions\LlmUnavailableException;

/** Aucun modèle configuré : la semaine est composée par les règles Mavi'oh. */
class NullPlannerClient implements LlmPlannerClient
{
    public function composeWeek(string $demande, array $context, array $schema): array
    {
        throw new LlmUnavailableException('Organisation de la semaine par l’IA non configurée.');
    }
}
