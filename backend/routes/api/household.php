<?php

use App\Http\Controllers\Api\HouseholdController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M6 — Foyer / Famille (brief §10)
|--------------------------------------------------------------------------
| Chargé dans le groupe auth:sanctum de routes/api.php (monté sous /api et /api/v1).
| Routes littérales avant les routes paramétrées ; paramètres contraints.
*/

Route::get('/household', [HouseholdController::class, 'show']);
Route::get('/household/preview', [HouseholdController::class, 'preview']);
Route::post('/household', [HouseholdController::class, 'store']);
Route::post('/household/join', [HouseholdController::class, 'join']);
Route::put('/household', [HouseholdController::class, 'update']);
Route::post('/household/regenerate-code', [HouseholdController::class, 'regenerateCode']);
Route::post('/household/transfer', [HouseholdController::class, 'transfer']);
Route::post('/household/leave', [HouseholdController::class, 'leave']);
Route::delete('/household', [HouseholdController::class, 'destroy']);

Route::put('/household/members/me', [HouseholdController::class, 'updateMe']);
Route::delete('/household/members/{user}', [HouseholdController::class, 'removeMember'])->whereNumber('user');

Route::post('/household/common-meal/preview', [HouseholdController::class, 'commonMealPreview']);
Route::post('/household/common-meal', [HouseholdController::class, 'commonMealStore']);
