<?php

/*
|--------------------------------------------------------------------------
| Poids par défaut d'une « pièce » ou d'une « portion » (grammes)
|--------------------------------------------------------------------------
|
| Utilisé par App\Support\Portions quand l'aliment n'a pas de serving_size_g.
| La clé est recherchée (sans accent, minuscules) dans la catégorie de l'aliment
| puis dans son nom. Toute conversion issue de cette table est une estimation
| (confidence « moyenne ») ; sans correspondance → 100 g (confidence « faible »).
|
*/

return [
    'oeuf' => 55,
    'fruit' => 150,
    'legume' => 120,
    'yaourt' => 125,
    'biscuit' => 10,
    'pain' => 30,
    'jambon' => 40,
    'viande' => 125,
    'poisson' => 130,
    'fromage' => 30,

    // Compléments courants (mêmes règles de correspondance)
    'pomme' => 150,
    'banane' => 120,
    'orange' => 150,
    'poire' => 160,
    'kiwi' => 75,
    'tomate' => 120,
    'carotte' => 80,
    'pomme de terre' => 150,
    'oignon' => 100,
    'courgette' => 200,
    'poivron' => 150,
    'avocat' => 150,
    'tranche' => 30,
    'steak' => 125,
    'escalope' => 130,
    'filet' => 130,
    'saucisse' => 60,
    'galette' => 50,
    'crepe' => 60,
    'barre' => 25,
    'carre' => 10,
    'chocolat' => 10,
    'compote' => 100,
    'dessert' => 125,
    'boisson' => 250,
    'canette' => 330,
    'bouteille' => 500,
    'sachet' => 30,
    'portion' => 100,
];
