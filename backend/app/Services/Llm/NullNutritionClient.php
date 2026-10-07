<?php

namespace App\Services\Llm;

use App\Contracts\LlmNutritionClient;
use App\Exceptions\LlmUnavailableException;

/** Aucun modèle configuré : les valeurs ne peuvent pas être lues sur le web. */
class NullNutritionClient implements LlmNutritionClient
{
    public function extraireValeurs(array $donnees, array $schema): array
    {
        throw new LlmUnavailableException('Lecture des valeurs nutritionnelles non configurée.');
    }
}
