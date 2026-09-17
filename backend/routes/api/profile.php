<?php

use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\WeightController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M2 — Profil & poids (chargé dans le groupe auth:sanctum)
|--------------------------------------------------------------------------
| GET/PUT /profile sont déclarés dans routes/api.php (contrat historique).
*/

Route::post('/profile/preview', [ProfileController::class, 'preview']);

Route::get('/weights', [WeightController::class, 'index']);
Route::post('/weights', [WeightController::class, 'store']);
Route::delete('/weights/{date}', [WeightController::class, 'destroy'])->where('date', '\d{4}-\d{2}-\d{2}');
