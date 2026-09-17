<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FoodController;
use App\Http\Controllers\Api\PortionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes API — Mavi'oh
|--------------------------------------------------------------------------
|
| Ce fichier est monté deux fois par RouteServiceProvider (`/api` et `/api/v1`).
| Il ne contient que le socle : routes publiques + groupe `auth:sanctum`.
|
| Chaque module possède exactement un fichier :
|   - routes/api_public/{module}.php → routes publiques (catalogues), chargées hors auth ;
|   - routes/api/{module}.php        → routes authentifiées, chargées dans le groupe auth.
|
| Règles : routes littérales avant les routes paramétrées, paramètres contraints
| (`->whereNumber('meal')`), aucune route nommée utilisée via `route()`.
|
*/

// --- Authentification (publique, limitée à 10 requêtes/minute) -----------------------
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// --- Catalogues publics (limités à 30 requêtes/minute) --------------------------------
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/foods/search', [FoodController::class, 'search']);
    Route::get('/foods/barcode/{barcode}', [FoodController::class, 'showByBarcode']);
    Route::get('/portions', [PortionController::class, 'index']);

    // Routes publiques des modules (routes/api_public/*.php), un fichier par module.
    foreach (glob(__DIR__.'/api_public/*.php') ?: [] as $publicRouteFile) {
        require $publicRouteFile;
    }
});

// --- Routes authentifiées ---------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::post('/foods', [FoodController::class, 'store']);
    Route::put('/foods/{food}', [FoodController::class, 'update'])->whereNumber('food');

    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::post('/recipes', [RecipeController::class, 'store']);
    Route::put('/recipes/{recipe}', [RecipeController::class, 'update'])->whereNumber('recipe');
    Route::delete('/recipes/{recipe}', [RecipeController::class, 'destroy'])->whereNumber('recipe');

    Route::get('/stocks', [StockController::class, 'index']);
    Route::post('/stocks', [StockController::class, 'storeLocation']);
    Route::post('/stocks/items', [StockController::class, 'storeItem']);
    Route::put('/stocks/items/{item}', [StockController::class, 'updateItem'])->whereNumber('item');
    Route::delete('/stocks/items/{item}', [StockController::class, 'destroyItem'])->whereNumber('item');

    // Routes authentifiées des modules (routes/api/*.php), un fichier par module.
    foreach (glob(__DIR__.'/api/*.php') ?: [] as $moduleRouteFile) {
        require $moduleRouteFile;
    }
});
