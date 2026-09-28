<?php

namespace App\Contracts;

use App\Exceptions\LlmUnavailableException;

/**
 * Client LLM qui rédige une recette à partir de ce que la personne a chez elle.
 *
 * Contrat distinct de LlmWorkoutClient parce que le domaine l'est, mais sans sélecteur de
 * fournisseur propre : écrire une recette est de la génération de texte, exactement comme une
 * séance. LlmProvider::current() suffit — contrairement à la vision, qui exige un modèle
 * multimodal et justifiait son propre sélecteur.
 */
interface LlmRecipeClient
{
    /**
     * @param  array<string, mixed>  $context  Contexte anonymisé : stock, objectif, régime, allergènes
     * @param  array<string, mixed>  $schema  Schéma JSON imposé à la réponse
     * @return array<string, mixed>  Réponse brute décodée
     *
     * @throws LlmUnavailableException
     */
    public function proposeRecipe(string $demande, array $context, array $schema): array;
}
