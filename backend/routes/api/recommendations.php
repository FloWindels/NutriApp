<?php

use App\Http\Controllers\Api\RecommendationController;
use Illuminate\Support\Facades\Route;

// Module M9 — coach du jour (chargé dans le groupe auth:sanctum).
Route::get('/recommendations', [RecommendationController::class, 'index']);
Route::put('/recommendations/{recommendation}', [RecommendationController::class, 'update'])->whereNumber('recommendation');
