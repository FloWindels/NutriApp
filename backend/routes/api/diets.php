<?php

use App\Http\Controllers\Api\DietController;
use Illuminate\Support\Facades\Route;

// Module M9 — catalogue des régimes et évaluation (chargé dans le groupe auth:sanctum).
// `{key}` est contraint aux clés du catalogue ([a-z_]+ parmi config/diets.php) : « evaluate »
// n'entre pas en conflit.
$dietKeys = array_keys((array) config('diets', []));

Route::get('/diets', [DietController::class, 'index']);
Route::get('/diets/evaluate', [DietController::class, 'evaluate'])->middleware('offre:regimes');
Route::get('/diets/{key}', [DietController::class, 'show'])
    ->where('key', $dietKeys !== [] ? implode('|', $dietKeys) : '[a-z_]+');
