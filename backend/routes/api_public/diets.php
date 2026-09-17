<?php

use App\Http\Controllers\Api\DietController;
use Illuminate\Support\Facades\Route;

// Module M9 — catalogue public des régimes (chargé hors auth, sous throttle:30,1).
// `{key}` est contraint aux clés du catalogue ([a-z_]+ parmi config/diets.php) afin de laisser
// GET /diets/evaluate (authentifié) au module.
$dietKeys = array_keys((array) config('diets', []));

Route::get('/diets', [DietController::class, 'index']);
Route::get('/diets/{key}', [DietController::class, 'show'])
    ->where('key', $dietKeys !== [] ? implode('|', $dietKeys) : '[a-z_]+');
