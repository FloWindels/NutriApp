<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HistoryController;
use Illuminate\Support\Facades\Route;

// Module M9 — tableau de bord & historique (chargé dans le groupe auth:sanctum).
Route::get('/dashboard', [DashboardController::class, 'show']);
Route::get('/history', [HistoryController::class, 'index']);
