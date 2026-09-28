<?php

use App\Http\Controllers\Api\ShoppingListController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M7 — Liste de courses (brief §11)
|--------------------------------------------------------------------------
| Chargé dans le groupe auth:sanctum de routes/api.php (monté sous /api et /api/v1).
| Routes littérales avant les routes paramétrées.
*/

Route::get('/shopping-list', [ShoppingListController::class, 'index'])->middleware('offre:courses');
Route::post('/shopping-list/items', [ShoppingListController::class, 'store'])->middleware('offre:courses');
Route::post('/shopping-list/generate', [ShoppingListController::class, 'generate'])->middleware('offre:courses');
Route::delete('/shopping-list/checked', [ShoppingListController::class, 'clearChecked'])->middleware('offre:courses');

Route::put('/shopping-list/items/{item}', [ShoppingListController::class, 'update'])->whereNumber('item')->middleware('offre:courses');
Route::delete('/shopping-list/items/{item}', [ShoppingListController::class, 'destroy'])->whereNumber('item')->middleware('offre:courses');
Route::post('/shopping-list/items/{item}/to-stock', [ShoppingListController::class, 'toStock'])->whereNumber('item')->middleware('offre:stock');
