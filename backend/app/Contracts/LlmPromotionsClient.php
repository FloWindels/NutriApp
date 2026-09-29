<?php

namespace App\Contracts;

use App\Exceptions\LlmUnavailableException;

/**
 * Client LLM qui met en forme les promotions d'une enseigne à partir de pages web.
 *
 * Contrat distinct parce que la tâche l'est : ici le modèle ne crée rien, il LIT. On lui donne
 * des extraits ramenés par la recherche web et on lui demande d'en sortir une liste structurée.
 * Tout ce qu'il rend est enregistré comme non vérifié, avec sa source : c'est une piste à
 * confirmer, pas un catalogue de prix.
 *
 * Même sélecteur de fournisseur que la recette et le planificateur (LlmProvider::current) : lire
 * un extrait de texte est de la génération de texte, pas de la vision.
 */
interface LlmPromotionsClient
{
    /**
     * @param  array<string, mixed>  $context  Enseigne, semaine visée et extraits web — aucune donnée personnelle
     * @param  array<string, mixed>  $schema  Schéma JSON imposé à la réponse
     * @return array<string, mixed>  Réponse brute décodée
     *
     * @throws LlmUnavailableException
     */
    public function extractPromotions(array $context, array $schema): array;
}
