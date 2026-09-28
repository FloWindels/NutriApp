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

Route::get('/household', [HouseholdController::class, 'show'])->middleware('offre:foyer');
Route::get('/household/preview', [HouseholdController::class, 'preview'])->middleware('offre:foyer');
Route::post('/household', [HouseholdController::class, 'store'])->middleware('offre:foyer');
Route::post('/household/join', [HouseholdController::class, 'join'])->middleware('offre:foyer');
Route::put('/household', [HouseholdController::class, 'update'])->middleware('offre:foyer');
Route::post('/household/regenerate-code', [HouseholdController::class, 'regenerateCode'])->middleware('offre:foyer');
Route::post('/household/transfer', [HouseholdController::class, 'transfer'])->middleware('offre:foyer');
Route::post('/household/leave', [HouseholdController::class, 'leave'])->middleware('offre:foyer');
Route::delete('/household', [HouseholdController::class, 'destroy'])->middleware('offre:foyer');

Route::put('/household/members/me', [HouseholdController::class, 'updateMe'])->middleware('offre:foyer');
Route::delete('/household/members/{user}', [HouseholdController::class, 'removeMember'])->whereNumber('user')->middleware('offre:foyer');

Route::post('/household/common-meal/preview', [HouseholdController::class, 'commonMealPreview'])->middleware('offre:foyer');
Route::post('/household/common-meal', [HouseholdController::class, 'commonMealStore'])->middleware('offre:foyer');
