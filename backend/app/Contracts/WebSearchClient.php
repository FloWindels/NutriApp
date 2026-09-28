<?php

namespace App\Contracts;

/**
 * Recherche sur Internet, pour nourrir une génération.
 *
 * Volontairement séparée du modèle : les résultats entrent dans le prompt comme DONNÉES, dans
 * une section délimitée, jamais comme instructions. Cette séparation est ce qui permet à la
 * fonction de marcher aussi avec un modèle local, et surtout ce qui limite l'injection de
 * consignes par une page web.
 *
 * Aucune donnée personnelle ne doit jamais partir dans une requête : l'appelant n'envoie que la
 * demande formulée par la personne, jamais son profil.
 */
interface WebSearchClient
{
    /**
     * @return list<array{titre: string, extrait: string, url: string, domaine: string}>
     *                                                                                  Liste vide quand la recherche est désactivée ou indisponible : ce n'est jamais une erreur bloquante.
     */
    public function search(string $requete, int $max = 4): array;
}
