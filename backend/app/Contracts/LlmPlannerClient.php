<?php

namespace App\Contracts;

use App\Exceptions\LlmUnavailableException;

/**
 * Client LLM qui organise la semaine de repas selon une contrainte dite en français.
 *
 * Contrat distinct de LlmRecipeClient parce que le modèle ne rédige rien ici : il choisit parmi
 * des recettes qui existent déjà et ne renvoie que des identifiants. Pas de sélecteur de
 * fournisseur propre pour autant — organiser une semaine reste de la génération de texte, donc
 * LlmProvider::current() suffit, comme pour la recette et la séance.
 */
interface LlmPlannerClient
{
    /**
     * @param  string  $demande  Contrainte formulée par la personne, en français
     * @param  array<string, mixed>  $context  Créneaux à remplir et recettes autorisées, anonymisés
     * @param  array<string, mixed>  $schema  Schéma JSON imposé à la réponse
     * @return array<string, mixed>  Réponse brute décodée (validée ensuite par WeekProposalSchema)
     *
     * @throws LlmUnavailableException
     */
    public function composeWeek(string $demande, array $context, array $schema): array;
}
