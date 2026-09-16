<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\FoodController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\MealController;
use App\Http\Controllers\Api\StockController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/foods/search', [FoodController::class, 'search']);
Route::get('/foods/barcode/{barcode}', [FoodController::class, 'showByBarcode']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::post('/foods', [FoodController::class, 'store']);
    Route::put('/foods/{food}', [FoodController::class, 'update']);

    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::post('/recipes', [RecipeController::class, 'store']);
    Route::put('/recipes/{recipe}', [RecipeController::class, 'update']);
    Route::delete('/recipes/{recipe}', [RecipeController::class, 'destroy']);

    Route::get('/meals', [MealController::class, 'index']);
    Route::post('/meals', [MealController::class, 'store']);
    Route::post('/meals/{meal}/items', [MealController::class, 'addItem']);

    Route::get('/stocks', [StockController::class, 'index']);
    Route::post('/stocks', [StockController::class, 'storeLocation']);
    Route::post('/stocks/items', [StockController::class, 'storeItem']);
    Route::put('/stocks/items/{item}', [StockController::class, 'updateItem']);
    Route::delete('/stocks/items/{item}', [StockController::class, 'destroyItem']);
});