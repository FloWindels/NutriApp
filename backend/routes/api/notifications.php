<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M1 — Notifications in-app (brief §14)
|--------------------------------------------------------------------------
| La route littérale `read-all` précède la route paramétrée `{key}`.
*/

Route::get('/notifications', [NotificationController::class, 'index']);
Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
Route::put('/notifications/{key}/read', [NotificationController::class, 'read'])
    ->where('key', '[a-z0-9_]{1,64}');
