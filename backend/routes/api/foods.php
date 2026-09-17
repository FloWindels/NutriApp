<?php

use App\Http\Controllers\Api\FoodController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M3 — Aliments (routes authentifiées)
|--------------------------------------------------------------------------
|
| Chargé dans le groupe `auth:sanctum` de routes/api.php. Les routes publiques
| (GET /foods/search, GET /foods/barcode/{barcode}) ainsi que POST /foods et
| PUT /foods/{food} restent déclarées dans routes/api.php.
|
*/

Route::get('/foods/favorites', [FoodController::class, 'favorites']);
Route::get('/foods/{food}', [FoodController::class, 'show'])->whereNumber('food');
Route::post('/foods/{food}/favorite', [FoodController::class, 'favorite'])->whereNumber('food');
Route::delete('/foods/{food}/favorite', [FoodController::class, 'unfavorite'])->whereNumber('food');
