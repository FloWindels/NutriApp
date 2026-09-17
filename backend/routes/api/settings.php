<?php

use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M1 — Paramètres (brief §14)
|--------------------------------------------------------------------------
*/

Route::get('/settings', [SettingsController::class, 'show']);
Route::put('/settings', [SettingsController::class, 'update']);
