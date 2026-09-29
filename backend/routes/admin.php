<?php

use App\Http\Controllers\Api\Admin\AdminCodeController;
use App\Http\Controllers\Api\Admin\AdminModerationController;
use App\Http\Controllers\Api\Admin\AdminStatsController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\PromotionController;
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
    Route::post('/users/{user}/offre', [AdminUserController::class, 'offre'])->whereNumber('user');

    Route::get('/moderation/foods', [AdminModerationController::class, 'foods']);
    Route::get('/moderation/recipes', [AdminModerationController::class, 'recipes']);
    Route::post('/moderation/foods/{food}/hide', [AdminModerationController::class, 'hideFood'])->whereNumber('food');
    Route::post('/moderation/foods/{food}/show', [AdminModerationController::class, 'showFood'])->whereNumber('food');
    Route::post('/moderation/recipes/{recipe}/hide', [AdminModerationController::class, 'hideRecipe'])->whereNumber('recipe');
    Route::post('/moderation/recipes/{recipe}/show', [AdminModerationController::class, 'showRecipe'])->whereNumber('recipe');

    Route::get('/codes', [AdminCodeController::class, 'index']);
    Route::post('/codes', [AdminCodeController::class, 'store']);
    Route::post('/codes/{code}/revoke', [AdminCodeController::class, 'revoke'])->whereNumber('code');

    // Le catalogue des promotions est partagé par tous les clients d'une enseigne : y écrire est
    // un geste d'administration, pas un geste de consommateur.
    Route::post('/magasins/{magasin}/promotions', [PromotionController::class, 'store'])->whereNumber('magasin');
    Route::put('/magasins/promotions/{promotion}', [PromotionController::class, 'update'])->whereNumber('promotion');
    Route::delete('/magasins/promotions/{promotion}', [PromotionController::class, 'destroy'])->whereNumber('promotion');

    Route::get('/journal', [AdminModerationController::class, 'journal']);
});
