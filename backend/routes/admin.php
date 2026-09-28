<?php

use App\Http\Controllers\Api\Admin\AdminModerationController;
use App\Http\Controllers\Api\Admin\AdminStatsController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Espace d'administration
|--------------------------------------------------------------------------
| Monté une seule fois sous /api/admin par RouteServiceProvider, délibérément hors de
| routes/api.php : celui-ci est chargé deux fois (sous /api et /api/v1) et place tout son
| contenu derrière auth:sanctum, ce qui ferait répondre 401 à un visiteur anonyme et
| révélerait ainsi l'existence du préfixe.
|
| Le middleware `admin` résout lui-même l'utilisateur et renvoie 404 à quiconque n'est pas
| administrateur — y compris à un visiteur sans jeton.
*/

Route::middleware(['admin', 'throttle:60,1'])->group(function () {
    Route::get('/stats', [AdminStatsController::class, 'index']);

    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->whereNumber('user');
    Route::post('/users/{user}/restore', [AdminUserController::class, 'restore'])->whereNumber('user');

    Route::get('/moderation/foods', [AdminModerationController::class, 'foods']);
    Route::get('/moderation/recipes', [AdminModerationController::class, 'recipes']);
    Route::post('/moderation/foods/{food}/hide', [AdminModerationController::class, 'hideFood'])->whereNumber('food');
    Route::post('/moderation/foods/{food}/show', [AdminModerationController::class, 'showFood'])->whereNumber('food');
    Route::post('/moderation/recipes/{recipe}/hide', [AdminModerationController::class, 'hideRecipe'])->whereNumber('recipe');
    Route::post('/moderation/recipes/{recipe}/show', [AdminModerationController::class, 'showRecipe'])->whereNumber('recipe');

    Route::get('/journal', [AdminModerationController::class, 'journal']);
});
