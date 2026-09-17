<?php

use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M5 — Stock (routes authentifiées, chargées dans le groupe auth:sanctum)
|--------------------------------------------------------------------------
|
| Les routes héritées (GET/POST /stocks, POST /stocks/items, PUT/DELETE /stocks/items/{item})
| restent déclarées dans routes/api.php. Ici : alertes, lieux (renommer/supprimer) et consommation.
| Routes littérales avant les routes paramétrées, paramètres numériques contraints.
|
*/

Route::get('/stocks/alerts', [StockController::class, 'alerts']);

Route::post('/stocks/items/{item}/consume', [StockController::class, 'consume'])->whereNumber('item');

Route::put('/stocks/{stock}', [StockController::class, 'updateLocation'])->whereNumber('stock');
Route::delete('/stocks/{stock}', [StockController::class, 'destroyLocation'])->whereNumber('stock');
