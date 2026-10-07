<?php

use App\Http\Controllers\Api\ApplicationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mise à jour de l'application Android (hors Play Store)
|--------------------------------------------------------------------------
| Chargé dans le groupe auth:sanctum de routes/api.php : l'APK n'est servi qu'aux comptes.
*/

Route::get('/app/android', [ApplicationController::class, 'android']);
Route::get('/app/android/apk', [ApplicationController::class, 'apk'])->middleware('throttle:6,1');
