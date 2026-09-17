<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M1 — Compte (routes authentifiées, brief §1)
|--------------------------------------------------------------------------
| Chargé dans le groupe auth:sanctum de routes/api.php (montés sous /api et /api/v1).
| GET /me reste déclaré dans routes/api.php ; /forgot-password et /reset-password sont publics.
*/

Route::post('/logout', [AuthController::class, 'logout']);

Route::get('/account/export', [AccountController::class, 'export']);
Route::put('/account/password', [AccountController::class, 'updatePassword']);
Route::put('/account', [AccountController::class, 'update']);
Route::delete('/account', [AccountController::class, 'destroy']);
