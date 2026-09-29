<?php

namespace App\Services\Llm;

use App\Contracts\LlmPromotionsClient;
use App\Exceptions\LlmUnavailableException;

/** Aucun modèle configuré : le relevé des promotions est indisponible. */
class NullPromotionsClient implements LlmPromotionsClient
{
    public function extractPromotions(array $context, array $schema): array
    {
        throw new LlmUnavailableException('Relevé des promotions non configuré.');
    }
}
