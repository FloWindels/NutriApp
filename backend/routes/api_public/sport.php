<?php

use App\Http\Controllers\Api\Sport\ExerciseController;
use Illuminate\Support\Facades\Route;

// Module M8 — catalogue public des exercices (chargé hors auth, sous throttle:30,1).
// Réponse paginée à la main : {data, meta} plafonné à 50 exercices par page.
Route::get('/sport/exercises', [ExerciseController::class, 'index']);
