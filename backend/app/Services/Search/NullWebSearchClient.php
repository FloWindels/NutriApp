<?php

namespace App\Services\Search;

use App\Contracts\WebSearchClient;

/** Recherche désactivée : aucune requête ne sort, et la génération se poursuit sans sources. */
class NullWebSearchClient implements WebSearchClient
{
    public function search(string $requete, int $max = 4): array
    {
        return [];
    }
}
