<?php

use App\Http\Controllers\Api\PromotionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module M7 — Promotions des enseignes
|--------------------------------------------------------------------------
| Chargé dans le groupe auth:sanctum de routes/api.php (monté sous /api et /api/v1).
| Routes littérales avant les routes paramétrées.
|
| Tout relève de la capacité `courses`. Le relevé automatique exige EN PLUS la capacité `ia`,
| vérifiée dans le contrôleur : c'est la seule action du module qui consomme un modèle, et un
| second middleware sur la même route aurait rendu illisible laquelle des deux offres manque.
*/

Route::put('/magasins/promotions/{promotion}', [PromotionController::class, 'update'])->whereNumber('promotion')->middleware('offre:courses');
Route::delete('/magasins/promotions/{promotion}', [PromotionController::class, 'destroy'])->whereNumber('promotion')->middleware('offre:courses');

Route::get('/magasins/{magasin}/promotions', [PromotionController::class, 'index'])->whereNumber('magasin')->middleware('offre:courses');
Route::post('/magasins/{magasin}/promotions', [PromotionController::class, 'store'])->whereNumber('magasin')->middleware('offre:courses');
Route::post('/magasins/{magasin}/promotions/recherche', [PromotionController::class, 'rechercher'])->whereNumber('magasin')->middleware('offre:courses');
