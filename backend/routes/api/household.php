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

// Voir son foyer, le quitter, le dissoudre, retirer le partage de son profil : jamais derrière
// une offre. Quand l'offre Foyer du propriétaire expire, chacun doit pouvoir reprendre la main
// sur ses données. Tout ce qui fait vivre le foyer reste réservé à l'offre Foyer.
Route::get('/household', [HouseholdController::class, 'show']);
// Aperçu et adhésion : pas de verrou d'offre ici. Un invité gratuit rejoint un foyer dont le
// propriétaire détient l'offre Foyer ; le contrôleur le vérifie et répond 402 sinon.
Route::get('/household/preview', [HouseholdController::class, 'preview']);
Route::post('/household', [HouseholdController::class, 'store'])->middleware('offre:foyer');
Route::post('/household/join', [HouseholdController::class, 'join']);
Route::put('/household', [HouseholdController::class, 'update'])->middleware('offre:foyer');
Route::post('/household/regenerate-code', [HouseholdController::class, 'regenerateCode'])->middleware('offre:foyer');
Route::post('/household/transfer', [HouseholdController::class, 'transfer'])->middleware('offre:foyer');
Route::post('/household/leave', [HouseholdController::class, 'leave']);
Route::delete('/household', [HouseholdController::class, 'destroy']);

Route::put('/household/members/me', [HouseholdController::class, 'updateMe']);
Route::delete('/household/members/{user}', [HouseholdController::class, 'removeMember'])->whereNumber('user')->middleware('offre:foyer');

Route::post('/household/common-meal/preview', [HouseholdController::class, 'commonMealPreview'])->middleware('offre:foyer');
Route::post('/household/common-meal', [HouseholdController::class, 'commonMealStore'])->middleware('offre:foyer');
