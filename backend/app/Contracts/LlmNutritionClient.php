<?php

namespace App\Contracts;

use App\Exceptions\LlmUnavailableException;

/**
 * Client LLM qui lit des extraits de pages web pour en tirer les valeurs nutritionnelles d'un
 * aliment absent du catalogue — ou, faute de source, les estime d'après une recette type.
 *
 * Les extraits entrent comme DONNÉES, jamais comme instructions (voir WebSearchClient).
 */
interface LlmNutritionClient
{
    /**
     * @param  array<string, mixed>  $donnees  { aliment, marque, sources: [{n, titre, url, texte}] }
     * @param  array<string, mixed>  $schema  Schéma JSON imposé à la réponse
     * @return array<string, mixed>  Réponse brute décodée
     *
     * @throws LlmUnavailableException
     */
    public function extraireValeurs(array $donnees, array $schema): array;
}
