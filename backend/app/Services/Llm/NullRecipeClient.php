<?php

namespace App\Services\Llm;

use App\Contracts\LlmRecipeClient;
use App\Exceptions\LlmUnavailableException;

/** Aucun modèle configuré : la génération de recette est indisponible. */
class NullRecipeClient implements LlmRecipeClient
{
    public function proposeRecipe(string $demande, array $context, array $schema): array
    {
        throw new LlmUnavailableException('Génération de recette non configurée.');
    }
}
