<?php

use App\Http\Controllers\Api\RecipeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M3 — Recettes (routes authentifiées)
|--------------------------------------------------------------------------
|
| Chargé dans le groupe `auth:sanctum` de routes/api.php, qui déclare déjà
| GET/POST /recipes et PUT/DELETE /recipes/{recipe}.
|
*/

Route::post('/recipes/estimate', [RecipeController::class, 'estimate']);
Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])->whereNumber('recipe');
