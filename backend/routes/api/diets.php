<?php

use App\Http\Controllers\Api\DietController;
use Illuminate\Support\Facades\Route;

// Module M9 — évaluation du régime (chargé dans le groupe auth:sanctum).
// NB : la route publique GET /diets/{key} est contrainte aux clés du catalogue, « evaluate » n'entre pas en conflit.
Route::get('/diets/evaluate', [DietController::class, 'evaluate']);
