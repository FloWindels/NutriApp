<?php

use App\Http\Controllers\Api\MealController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M4 — Repas & suivi quotidien (brief §4)
|--------------------------------------------------------------------------
| Chargé dans le groupe auth:sanctum de routes/api.php (monté sous /api et /api/v1).
| Routes littérales avant les routes paramétrées ; paramètres numériques contraints.
*/

Route::get('/meals', [MealController::class, 'index']);
Route::post('/meals', [MealController::class, 'store']);
Route::post('/meals/copy', [MealController::class, 'copy']);
Route::get('/meals/history', [MealController::class, 'history']);
Route::get('/meals/frequent', [MealController::class, 'frequent']);

Route::put('/meals/{meal}', [MealController::class, 'update'])->whereNumber('meal');
Route::delete('/meals/{meal}', [MealController::class, 'destroy'])->whereNumber('meal');

Route::post('/meals/{meal}/items', [MealController::class, 'storeItem'])->whereNumber('meal');
Route::put('/meals/{meal}/items/{item}', [MealController::class, 'updateItem'])->whereNumber('meal')->whereNumber('item');
Route::delete('/meals/{meal}/items/{item}', [MealController::class, 'destroyItem'])->whereNumber('meal')->whereNumber('item');
