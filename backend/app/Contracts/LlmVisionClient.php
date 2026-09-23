<?php

namespace App\Contracts;

use App\Exceptions\LlmUnavailableException;

/**
 * Client LLM capable de lire une image : reconnaissance d'une photo d'assiette.
 *
 * Contrat séparé de LlmWorkoutClient à dessein : lire une image n'a pas les mêmes prérequis
 * qu'écrire du texte — un Ollama configuré avec llama3.2 sait générer une séance mais ne sait
 * pas regarder une photo, il faut un modèle multimodal.
 *
 * Implémentations : AnthropicVisionClient, OllamaVisionClient, NullVisionClient (aucune vision
 * configurée), Tests\Support\FakePlateVisionClient (tests).
 */
interface LlmVisionClient
{
    /**
     * @param  string  $base64Image  Image encodée en base64, sans préfixe `data:`
     * @param  string  $mediaType  Type MIME réel de l'image (image/jpeg…)
     * @param  array<string, mixed>  $context  Contexte anonymisé (régime, allergènes, langue)
     * @param  array<string, mixed>  $schema  Schéma JSON imposé à la réponse
     * @return array<string, mixed>  Réponse brute décodée, normalisée ensuite par PlateRecognitionSchema
     *
     * @throws LlmUnavailableException quand l'analyse est impossible (pas de modèle, quota, réseau, réponse illisible)
     */
    public function analyzePlate(string $base64Image, string $mediaType, array $context, array $schema): array;
}
