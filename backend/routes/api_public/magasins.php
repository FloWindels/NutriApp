<?php

use App\Http\Controllers\Api\MagasinController;
use Illuminate\Support\Facades\Route;

// Module M7 — catalogue public des magasins (chargé hors auth, sous throttle:30,1).
// Public au même titre que le catalogue des exercices : un assortiment et des prix indicatifs
// sont des données de référence. Ce qui est payant, c'est de s'en servir sur SA liste.
Route::get('/magasins', [MagasinController::class, 'index']);
Route::get('/magasins/{magasin}/produits', [MagasinController::class, 'produits'])->whereNumber('magasin');
