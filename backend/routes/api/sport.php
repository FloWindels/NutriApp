<?php

use App\Http\Controllers\Api\Sport\SportActivityController;
use App\Http\Controllers\Api\Sport\SportCalendarController;
use App\Http\Controllers\Api\Sport\SportCatalogController;
use App\Http\Controllers\Api\Sport\SportConfigController;
use App\Http\Controllers\Api\Sport\SportSummaryController;
use App\Http\Controllers\Api\Sport\WorkoutSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M8 — Sport (routes authentifiées, chargées dans le groupe auth:sanctum)
|--------------------------------------------------------------------------
|
| Le catalogue public d'exercices vit dans routes/api_public/sport.php.
| Règle de déclaration : routes littérales AVANT les routes paramétrées
| (/sport/sessions/generate avant /sport/sessions/{session}), identifiants contraints
| par ->whereNumber(). Chaque ressource est chargée à travers l'utilisateur : un
| identifiant étranger donne 404 « Introuvable. ».
|
*/

// --- Configuration et vocabulaires ----------------------------------------------------
Route::get('/sport/config', SportConfigController::class);
Route::get('/sport/summary', SportSummaryController::class);

// --- Catalogue des sports (publics + personnalisés) ------------------------------------
Route::get('/sport/sports', [SportCatalogController::class, 'index']);
Route::post('/sport/sports', [SportCatalogController::class, 'store']);
Route::put('/sport/sports/{sport}', [SportCatalogController::class, 'update'])->whereNumber('sport');
Route::delete('/sport/sports/{sport}', [SportCatalogController::class, 'destroy'])->whereNumber('sport');

// --- Calendrier -------------------------------------------------------------------------
Route::get('/sport/calendar', [SportCalendarController::class, 'index']);
Route::post('/sport/calendar', [SportCalendarController::class, 'store']);
Route::post('/sport/calendar/recurring', [SportCalendarController::class, 'storeRecurring']);
Route::post('/sport/calendar/plan-week', [SportCalendarController::class, 'planWeek']);
Route::post('/sport/calendar/{plan}/log', [SportCalendarController::class, 'log'])->whereNumber('plan');
Route::post('/sport/calendar/{plan}/propose', [SportCalendarController::class, 'propose'])->whereNumber('plan');
Route::put('/sport/calendar/{plan}', [SportCalendarController::class, 'update'])->whereNumber('plan');
Route::delete('/sport/calendar/{plan}', [SportCalendarController::class, 'destroy'])->whereNumber('plan');

// --- Activités libres et estimation des calories ----------------------------------------
Route::post('/sport/activities', [SportActivityController::class, 'store']);
Route::post('/sport/calories/estimate', [SportActivityController::class, 'estimate']);

// --- Séances ------------------------------------------------------------------------------
Route::get('/sport/sessions', [WorkoutSessionController::class, 'index']);
Route::post('/sport/sessions/generate', [WorkoutSessionController::class, 'generate']);
Route::post('/sport/sessions', [WorkoutSessionController::class, 'store']);
Route::post('/sport/sessions/{session}/start', [WorkoutSessionController::class, 'start'])->whereNumber('session');
Route::post('/sport/sessions/{session}/complete', [WorkoutSessionController::class, 'complete'])->whereNumber('session');
Route::post('/sport/sessions/{session}/cancel', [WorkoutSessionController::class, 'cancel'])->whereNumber('session');
Route::get('/sport/sessions/{session}', [WorkoutSessionController::class, 'show'])->whereNumber('session');
Route::put('/sport/sessions/{session}', [WorkoutSessionController::class, 'update'])->whereNumber('session');
Route::delete('/sport/sessions/{session}', [WorkoutSessionController::class, 'destroy'])->whereNumber('session');
