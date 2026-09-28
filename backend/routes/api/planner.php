<?php

use App\Http\Controllers\Api\PlannerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M7 — Planificateur de la semaine (brief §12)
|--------------------------------------------------------------------------
| Chargé dans le groupe auth:sanctum de routes/api.php (monté sous /api et /api/v1).
| Routes littérales avant les routes paramétrées.
*/

Route::get('/planner', [PlannerController::class, 'index'])->middleware('offre:planificateur');
Route::post('/planner', [PlannerController::class, 'store'])->middleware('offre:planificateur');
Route::post('/planner/generate', [PlannerController::class, 'generate'])->middleware('offre:planificateur');

Route::put('/planner/{plan}', [PlannerController::class, 'update'])->whereNumber('plan')->middleware('offre:planificateur');
Route::delete('/planner/{plan}', [PlannerController::class, 'destroy'])->whereNumber('plan')->middleware('offre:planificateur');
Route::post('/planner/{plan}/log', [PlannerController::class, 'log'])->whereNumber('plan')->middleware('offre:planificateur');
