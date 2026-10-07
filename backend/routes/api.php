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
| Il ne contient que le socle : authentification + groupe `auth:sanctum`.
|
| Un visiteur sans compte n'atteint RIEN d'autre que l'inscription, la connexion et la
| réinitialisation du mot de passe : catalogues compris, tout le reste exige un jeton.
|
| Chaque module possède exactement un fichier : routes/api/{module}.php, chargé dans le
| groupe auth.
|
| Règles : routes littérales avant les routes paramétrées, paramètres contraints
| (`->whereNumber('meal')`), aucune route nommée utilisée via `route()`.
|
*/

// --- Authentification (publique, limitée à 10 requêtes/minute) -----------------------
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    // Plafond dédié en plus : par couple (e-mail, IP) et par e-mail (RouteServiceProvider).
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// --- Routes authentifiées ---------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Recherche d'aliments : peut interroger Open Food Facts, plafond dédié (30/min).
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/foods/search', [FoodController::class, 'search']);
        Route::get('/foods/barcode/{barcode}', [FoodController::class, 'showByBarcode']);
    });
    Route::get('/portions', [PortionController::class, 'index']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::post('/foods', [FoodController::class, 'store']);
    Route::put('/foods/{food}', [FoodController::class, 'update'])->whereNumber('food');

    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::post('/recipes', [RecipeController::class, 'store']);
    Route::put('/recipes/{recipe}', [RecipeController::class, 'update'])->whereNumber('recipe');
    Route::delete('/recipes/{recipe}', [RecipeController::class, 'destroy'])->whereNumber('recipe');

    Route::get('/stocks', [StockController::class, 'index'])->middleware('offre:stock');
    Route::post('/stocks', [StockController::class, 'storeLocation'])->middleware('offre:stock');
    Route::post('/stocks/items', [StockController::class, 'storeItem'])->middleware('offre:stock');
    Route::put('/stocks/items/{item}', [StockController::class, 'updateItem'])->whereNumber('item')->middleware('offre:stock');
    Route::delete('/stocks/items/{item}', [StockController::class, 'destroyItem'])->whereNumber('item')->middleware('offre:stock');

    // Routes authentifiées des modules (routes/api/*.php), un fichier par module.
    foreach (glob(__DIR__.'/api/*.php') ?: [] as $moduleRouteFile) {
        require $moduleRouteFile;
    }
});
