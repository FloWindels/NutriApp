# routes/api — routes authentifiées des modules

Chaque module possède **exactement un fichier** ici (créé seulement si nécessaire) :

| Module | Fichier |
|---|---|
| M1 Compte / paramètres | `account.php`, `settings.php` |
| M2 Profil / poids | `profile.php` |
| M3 Aliments / recettes | `foods.php`, `recipes.php` |
| M4 Repas | `meals.php` |
| M5 Stock | `stocks.php` |
| M6 Foyer | `household.php` |
| M7 Courses / planificateur | `shopping.php`, `planner.php` |
| M8 Sport | `sport.php` |
| M9 Dashboard / coach / régimes | `dashboard.php`, `recommendations.php`, `diets.php` |

Ces fichiers sont `require`s **à l'intérieur** du groupe `auth:sanctum` de `routes/api.php`
(et donc montés sous `/api` et `/api/v1`). Ne pas y redéclarer `Route::middleware('auth:sanctum')`.

Règles : routes littérales avant les routes paramétrées, paramètres contraints
(`->whereNumber('meal')`, `->where('key', '[a-z_]+')`), jamais de `route('nom')`.

## M8 Sport — `sport.php` (+ `../api_public/sport.php`)

| Méthode | Chemin | Contrôleur | Rôle |
|---|---|---|---|
| GET | `/sport/config` | `Sport\SportConfigController` | disponibilité de l'IA, coefficient calories, vocabulaires français |
| GET | `/sport/summary` | `Sport\SportSummaryController` | tuiles du jour / semaine / série, bonus, conseils, prochaine séance |
| GET | `/sport/sports` | `Sport\SportCatalogController@index` | catalogue visible (publics + sports personnalisés) |
| POST | `/sport/sports` | `Sport\SportCatalogController@store` | créer un sport personnalisé (MET déduits de la catégorie) |
| PUT | `/sport/sports/{sport}` | `Sport\SportCatalogController@update` | modifier **son** sport personnalisé |
| DELETE | `/sport/sports/{sport}` | `Sport\SportCatalogController@destroy` | supprimer, refusé 422 si le sport est utilisé |
| GET | `/sport/calendar` | `Sport\SportCalendarController@index` | fenêtre de 62 jours max (mois en cours par défaut) |
| POST | `/sport/calendar` | `Sport\SportCalendarController@store` | planifier une séance |
| POST | `/sport/calendar/recurring` | `Sport\SportCalendarController@storeRecurring` | série hebdomadaire (1–12 semaines, `recurrence_id`) |
| POST | `/sport/calendar/plan-week` | `Sport\SportCalendarController@planWeek` | répartition de la semaine (IA ou règles) |
| POST | `/sport/calendar/{plan}/log` | `Sport\SportCalendarController@log` | « j'ai fait cette séance » → séance terminée + bonus |
| POST | `/sport/calendar/{plan}/propose` | `Sport\SportCalendarController@propose` | proposition (IA ou règles) à partir du plan |
| PUT | `/sport/calendar/{plan}` | `Sport\SportCalendarController@update` | modifier une entrée |
| DELETE | `/sport/calendar/{plan}` | `Sport\SportCalendarController@destroy` | supprimer (`?serie=1` = toute la suite de la série) |
| POST | `/sport/activities` | `Sport\SportActivityController@store` | activité libre déjà réalisée |
| POST | `/sport/calories/estimate` | `Sport\SportActivityController@estimate` | aperçu MET du champ « Calories » |
| GET | `/sport/sessions` | `Sport\WorkoutSessionController@index` | historique filtrable (`from`, `to`, `status`) |
| POST | `/sport/sessions/generate` | `Sport\WorkoutSessionController@generate` | **avant** `/{session}` : proposition non persistée |
| POST | `/sport/sessions` | `Sport\WorkoutSessionController@store` | enregistrer une proposition telle quelle |
| POST | `/sport/sessions/{session}/start` | `Sport\WorkoutSessionController@start` | démarrer |
| POST | `/sport/sessions/{session}/complete` | `Sport\WorkoutSessionController@complete` | terminer → calories, bonus, `reco_post` |
| POST | `/sport/sessions/{session}/cancel` | `Sport\WorkoutSessionController@cancel` | annuler |
| GET | `/sport/sessions/{session}` | `Sport\WorkoutSessionController@show` | détail |
| PUT | `/sport/sessions/{session}` | `Sport\WorkoutSessionController@update` | modifier (`calories_burned: null` ⇒ recalcul auto) |
| DELETE | `/sport/sessions/{session}` | `Sport\WorkoutSessionController@destroy` | supprimer (le plan lié repasse à « prévu ») |
| GET | `/sport/exercises` | `Sport\ExerciseController@index` | **public** (`routes/api_public/sport.php`), `{data, meta}` |
