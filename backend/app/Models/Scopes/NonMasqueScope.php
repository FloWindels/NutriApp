<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Cache les contenus retirés par la modération, sur TOUS les chemins de lecture.
 *
 * Un filtre posé au cas par cas laisserait forcément passer une route : les aliments, par
 * exemple, sont lisibles par la recherche, par le code-barres — tous deux sans compte —, par la
 * fiche détaillée et par les favoris. Un scope global ferme les quatre d'un coup, et tout
 * nouveau chemin de lecture en hérite sans qu'on ait à y penser.
 *
 * L'administration lève le scope explicitement avec `withoutGlobalScope(NonMasqueScope::class)`.
 */
class NonMasqueScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.masque_le');
    }
}
