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
| LIRE les promotions et en lancer un relevé relèvent de la capacité `courses` : ce sont des
| gestes qu'on fait pour sa propre liste. Le relevé automatique exige EN PLUS la capacité `ia`,
| vérifiée dans le contrôleur : c'est la seule action du module qui consomme un modèle, et un
| second middleware sur la même route aurait rendu illisible laquelle des deux offres manque.
|
| ÉCRIRE, en revanche, ne relève d'aucune offre : une promotion n'appartient à personne, elle
| pèse sur le panier estimé de tous les clients de l'enseigne. La laisser derrière `offre:courses`
| permettait à n'importe quel abonné d'inscrire un prix marqué « vérifié » dans un catalogue
| partagé, ou d'effacer celui d'un autre. Les écritures partent donc dans routes/admin.php, avec
| le reste de ce qui s'administre — ce qui règle du même coup le cas de l'administrateur resté en
| offre gratuite, qui doit pouvoir tenir le catalogue sans souscrire quoi que ce soit.
*/

Route::get('/magasins/{magasin}/promotions', [PromotionController::class, 'index'])->whereNumber('magasin')->middleware('offre:courses');
Route::post('/magasins/{magasin}/promotions/recherche', [PromotionController::class, 'rechercher'])->whereNumber('magasin')->middleware('offre:courses');
