# API Mavi'oh — référence

API REST JSON servie par Laravel. Elle est la source de vérité : tous les calculs
nutritionnels et sportifs y sont faits, les clients (site web Next.js et application
Flutter) se contentent d'afficher.

Référence générée depuis le code : `routes/api.php`, `routes/api/*.php`,
`routes/api_public/*.php` et les contrôleurs correspondants.

---

## Conventions

### Préfixes

Chaque route est servie deux fois, sous `/api` et sous `/api/v1`, avec un corps identique.
`/api` existe pour les clients historiques, `/api/v1` est le préfixe à utiliser pour tout
nouveau développement.

### Authentification

Jetons Laravel Sanctum. `POST /api/register` et `POST /api/login` renvoient
`{token, user}` ; toutes les routes protégées attendent ensuite :

```
Authorization: Bearer <token>
Accept: application/json
```

Les jetons expirent au bout de 30 jours par défaut (`SANCTUM_EXPIRATION`, en minutes) et
`POST /api/logout` révoque celui de l'appareil courant. Une purge quotidienne supprime les
jetons expirés.

### Enveloppes

| Cas | Forme |
|---|---|
| Ressource ou collection | `{"data": ...}` |
| Écriture | `{"message": "…", "data": ...}` |
| Liste paginée | `{"data": [...], "meta": {"current_page", "last_page", "per_page", "total"}}` |
| Suppression | `{"message": "…"}` |

Trois endpoints historiques ne sont pas enveloppés et ne le seront pas, pour ne pas casser
les applications déjà installées : `POST /register`, `POST /login` (`{token, user}`),
`GET /me` et `GET /profile` renvoient leurs champs à la racine.

### Erreurs

| Code | Corps | Quand |
|---|---|---|
| 401 | `{"message": "Non authentifié."}` | jeton absent, invalide ou expiré |
| 403 | `{"message": "Action non autorisée."}` | action réservée au créateur ou au propriétaire du foyer |
| 404 | `{"message": "Introuvable."}` | ressource inexistante **ou appartenant à quelqu'un d'autre** |
| 422 | `{"message": "…", "errors": {"champ": ["…"]}}` | validation, messages en français |
| 429 | `{"message": "Trop de requêtes, réessaie dans une minute."}` | limitation de débit |
| 502 | `{"message": "Open Food Facts indisponible."}` | source externe injoignable |

L'isolation est volontairement opaque : demander la ressource d'un autre utilisateur renvoie
404 et non 403, pour ne pas révéler son existence.

### Limitation de débit

10 requêtes par minute sur la connexion, l'inscription et la réinitialisation de mot de passe ;
30 sur les catalogues publics ; 120 par minute et par utilisateur ailleurs.

### Formats

Dates de jour au format `YYYY-MM-DD`, horodatages en ISO 8601 UTC, heures en `HH:MM:SS`.
Les décimaux sont des nombres JSON. Les valeurs déduites d'une conversion de portion, d'une
estimation MET ou d'une donnée manquante portent `is_estimate: true`.

### Pagination

`?page=` et `?per_page=` (50 maximum). `meta` est ajouté sans retirer `data`, donc les
clients qui ignorent la pagination continuent de fonctionner.

---

## Endpoints

### Compte et authentification

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| DELETE | `/api/account` | oui | AccountController@destroy |
| PUT | `/api/account` | oui | AccountController@update |
| GET | `/api/account/export` | oui | AccountController@export |
| PUT | `/api/account/password` | oui | AccountController@updatePassword |
| POST | `/api/forgot-password` | non | AuthController@forgotPassword |
| POST | `/api/login` | non | AuthController@login |
| POST | `/api/logout` | oui | AuthController@logout |
| GET | `/api/me` | oui | AuthController@me |
| POST | `/api/register` | non | AuthController@register |
| POST | `/api/reset-password` | non | AuthController@resetPassword |

### Profil nutritionnel

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/profile` | oui | ProfileController@show |
| PUT | `/api/profile` | oui | ProfileController@update |
| POST | `/api/profile/preview` | oui | ProfileController@preview |
| GET | `/api/weights` | oui | WeightController@index |
| POST | `/api/weights` | oui | WeightController@store |
| DELETE | `/api/weights/{date}` | oui | WeightController@destroy |

### Aliments et portions

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| POST | `/api/foods` | oui | FoodController@store |
| GET | `/api/foods/barcode/{barcode}` | non | FoodController@showByBarcode |
| GET | `/api/foods/favorites` | oui | FoodController@favorites |
| GET | `/api/foods/search` | non | FoodController@search |
| GET | `/api/foods/{food}` | oui | FoodController@show |
| PUT | `/api/foods/{food}` | oui | FoodController@update |
| DELETE | `/api/foods/{food}/favorite` | oui | FoodController@unfavorite |
| POST | `/api/foods/{food}/favorite` | oui | FoodController@favorite |
| GET | `/api/portions` | non | PortionController@index |

### Recettes

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/recipes` | oui | RecipeController@index |
| POST | `/api/recipes` | oui | RecipeController@store |
| POST | `/api/recipes/estimate` | oui | RecipeController@estimate |
| DELETE | `/api/recipes/{recipe}` | oui | RecipeController@destroy |
| GET | `/api/recipes/{recipe}` | oui | RecipeController@show |
| PUT | `/api/recipes/{recipe}` | oui | RecipeController@update |

### Repas et suivi du jour

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/meals` | oui | MealController@index |
| POST | `/api/meals` | oui | MealController@store |
| POST | `/api/meals/copy` | oui | MealController@copy |
| GET | `/api/meals/frequent` | oui | MealController@frequent |
| GET | `/api/meals/history` | oui | MealController@history |
| DELETE | `/api/meals/{meal}` | oui | MealController@destroy |
| PUT | `/api/meals/{meal}` | oui | MealController@update |
| POST | `/api/meals/{meal}/items` | oui | MealController@storeItem |
| DELETE | `/api/meals/{meal}/items/{item}` | oui | MealController@destroyItem |
| PUT | `/api/meals/{meal}/items/{item}` | oui | MealController@updateItem |

### Stock

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/stocks` | oui | StockController@index |
| POST | `/api/stocks` | oui | StockController@storeLocation |
| GET | `/api/stocks/alerts` | oui | StockController@alerts |
| POST | `/api/stocks/items` | oui | StockController@storeItem |
| DELETE | `/api/stocks/items/{item}` | oui | StockController@destroyItem |
| PUT | `/api/stocks/items/{item}` | oui | StockController@updateItem |
| POST | `/api/stocks/items/{item}/consume` | oui | StockController@consume |
| DELETE | `/api/stocks/{stock}` | oui | StockController@destroyLocation |
| PUT | `/api/stocks/{stock}` | oui | StockController@updateLocation |

### Tableau de bord et historique

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/dashboard` | oui | DashboardController@show |
| GET | `/api/history` | oui | HistoryController@index |

### Coach

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/recommendations` | oui | RecommendationController@index |
| PUT | `/api/recommendations/{recommendation}` | oui | RecommendationController@update |

### Régimes

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/diets` | non | DietController@index |
| GET | `/api/diets/evaluate` | oui | DietController@evaluate |
| GET | `/api/diets/{key}` | non | DietController@show |

### Foyer

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| DELETE | `/api/household` | oui | HouseholdController@destroy |
| GET | `/api/household` | oui | HouseholdController@show |
| POST | `/api/household` | oui | HouseholdController@store |
| PUT | `/api/household` | oui | HouseholdController@update |
| POST | `/api/household/common-meal` | oui | HouseholdController@commonMealStore |
| POST | `/api/household/common-meal/preview` | oui | HouseholdController@commonMealPreview |
| POST | `/api/household/join` | oui | HouseholdController@join |
| POST | `/api/household/leave` | oui | HouseholdController@leave |
| PUT | `/api/household/members/me` | oui | HouseholdController@updateMe |
| DELETE | `/api/household/members/{user}` | oui | HouseholdController@removeMember |
| GET | `/api/household/preview` | oui | HouseholdController@preview |
| POST | `/api/household/regenerate-code` | oui | HouseholdController@regenerateCode |
| POST | `/api/household/transfer` | oui | HouseholdController@transfer |

### Liste de courses

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/shopping-list` | oui | ShoppingListController@index |
| DELETE | `/api/shopping-list/checked` | oui | ShoppingListController@clearChecked |
| POST | `/api/shopping-list/generate` | oui | ShoppingListController@generate |
| POST | `/api/shopping-list/items` | oui | ShoppingListController@store |
| DELETE | `/api/shopping-list/items/{item}` | oui | ShoppingListController@destroy |
| PUT | `/api/shopping-list/items/{item}` | oui | ShoppingListController@update |
| POST | `/api/shopping-list/items/{item}/to-stock` | oui | ShoppingListController@toStock |

### Planificateur

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/planner` | oui | PlannerController@index |
| POST | `/api/planner` | oui | PlannerController@store |
| POST | `/api/planner/generate` | oui | PlannerController@generate |
| DELETE | `/api/planner/{plan}` | oui | PlannerController@destroy |
| PUT | `/api/planner/{plan}` | oui | PlannerController@update |
| POST | `/api/planner/{plan}/log` | oui | PlannerController@log |

### Sport

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| POST | `/api/sport/activities` | oui | SportActivityController@store |
| GET | `/api/sport/calendar` | oui | SportCalendarController@index |
| POST | `/api/sport/calendar` | oui | SportCalendarController@store |
| POST | `/api/sport/calendar/plan-week` | oui | SportCalendarController@planWeek |
| POST | `/api/sport/calendar/recurring` | oui | SportCalendarController@storeRecurring |
| DELETE | `/api/sport/calendar/{plan}` | oui | SportCalendarController@destroy |
| PUT | `/api/sport/calendar/{plan}` | oui | SportCalendarController@update |
| POST | `/api/sport/calendar/{plan}/log` | oui | SportCalendarController@log |
| POST | `/api/sport/calendar/{plan}/propose` | oui | SportCalendarController@propose |
| POST | `/api/sport/calories/estimate` | oui | SportActivityController@estimate |
| GET | `/api/sport/config` | oui | SportConfigController |
| GET | `/api/sport/exercises` | non | ExerciseController@index |
| GET | `/api/sport/sessions` | oui | WorkoutSessionController@index |
| POST | `/api/sport/sessions` | oui | WorkoutSessionController@store |
| POST | `/api/sport/sessions/generate` | oui | WorkoutSessionController@generate |
| DELETE | `/api/sport/sessions/{session}` | oui | WorkoutSessionController@destroy |
| GET | `/api/sport/sessions/{session}` | oui | WorkoutSessionController@show |
| PUT | `/api/sport/sessions/{session}` | oui | WorkoutSessionController@update |
| POST | `/api/sport/sessions/{session}/cancel` | oui | WorkoutSessionController@cancel |
| POST | `/api/sport/sessions/{session}/complete` | oui | WorkoutSessionController@complete |
| POST | `/api/sport/sessions/{session}/start` | oui | WorkoutSessionController@start |
| GET | `/api/sport/sports` | oui | SportCatalogController@index |
| POST | `/api/sport/sports` | oui | SportCatalogController@store |
| DELETE | `/api/sport/sports/{sport}` | oui | SportCatalogController@destroy |
| PUT | `/api/sport/sports/{sport}` | oui | SportCatalogController@update |
| GET | `/api/sport/summary` | oui | SportSummaryController |

### Paramètres

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/settings` | oui | SettingsController@show |
| PUT | `/api/settings` | oui | SettingsController@update |

### Notifications

| Méthode | Chemin | Auth | Contrôleur |
|---|---|---|---|
| GET | `/api/notifications` | oui | NotificationController@index |
| POST | `/api/notifications/read-all` | oui | NotificationController@readAll |
| PUT | `/api/notifications/{key}/read` | oui | NotificationController@read |
---

## Détail des flux principaux

Les corps ci-dessous sont ceux réellement acceptés et renvoyés, vérifiés sur une instance
locale avec le compte de démonstration.

### Journée alimentaire — `GET /api/meals?date=YYYY-MM-DD`

```json
{"data": {
  "date": "2026-09-17",
  "meals": [{"id": 1, "type": "petit_dejeuner", "name": "Porridge du matin",
             "items": [{"id": 1, "label": "Flocons d'avoine", "quantity": 60, "unit": "g",
                        "calories": 227.4, "proteins": 8.2, "carbs": 35.4, "fat": 4.1,
                        "is_estimate": false}],
             "totals": {"calories": 512.3, "proteins": 21.4, "carbs": 68.2, "fat": 15.1}}],
  "totals":    {"calories": 1180.9, "proteins": 62.1, "carbs": 140.3, "fat": 38.7},
  "targets":   {"calories": 2290, "proteins": 148, "carbs": 275, "fat": 66},
  "remaining": {"calories": 1667.1, "proteins": 85.9, "carbs": 134.7, "fat": 27.3},
  "sport": {"calories_burned": 557.6, "calories_bonus": 558, "coefficient": 100,
            "explication": "557,6 kcal brûlées aujourd'hui …", "is_estimate": true},
  "next_meal_type": "diner",
  "plancher_kcal": 1500
}}
```

`remaining.calories = targets.calories + sport.calories_bonus − totals.calories` : les calories
brûlées sont bien des calories consommables en plus.

### Ajouter un aliment à un repas — `POST /api/meals`

```json
{"date": "2026-09-17", "type": "dejeuner",
 "items": [{"food_id": 12, "quantity": 150, "unit": "g",
            "stock_item_id": 4, "decrement_stock": true}]}
```

Un élément porte exactement l'une de ces trois clés : `food_id`, `recipe_id`, ou `custom`
(`{label, per_100g, calories, proteins, carbs, fat}`). L'appel est idempotent par
(date, type) : 201 à la création du repas, 200 si le repas existait déjà, les éléments étant
ajoutés dans les deux cas. La réponse contient `{message, data, day, stock_decrements}`, où
`day` est la journée recalculée et `stock_decrements` permet d'afficher une annulation.

### Recherche d'aliments — `GET /api/foods/search?q=&off=1&page=`

Recherche locale sur le nom, la marque et le code-barres. Avec `off=1`, un utilisateur
authentifié dont la recherche locale renvoie moins de cinq résultats déclenche en plus une
requête Open Food Facts dont les produits sont normalisés puis enregistrés ; `meta.off_queried`
indique si cela a eu lieu.

### Code-barres — `GET /api/foods/barcode/{ean}`

Cherche en local, essaie les variantes du code (12 chiffres préfixés d'un zéro, 13 chiffres
débarrassés de leur zéro initial), puis interroge Open Food Facts et enregistre le produit avec
sa provenance et sa date de récupération. Une fiche importée depuis plus de 90 jours est
rafraîchie au passage. Renvoie 404 si le produit est inconnu partout, 502 si Open Food Facts
est injoignable et que rien n'existe en local.

### Profil — `GET` et `PUT /api/profile`

Les champs historiques restent à la racine et en français (`poids`, `taille`, `age`, `sexe`,
`objectif_type`, `niveau_activite`, `regime_alimentaire`, `calories_cibles`…). S'y ajoutent
`besoins` (métabolisme de base, dépense totale, cible, macros, variation hebdomadaire, IMC,
avertissements, mention) et `cibles_effectives` avec sa `source` (`calcul` ou `utilisateur`).

`PUT` exige l'ensemble des champs au premier enregistrement, puis accepte une mise à jour
partielle. `POST /api/profile/preview` applique les mêmes règles sans rien enregistrer, ce qui
permet l'aperçu en direct dans les formulaires.

Règles de calcul et garde-fous : voir `REGLES_NUTRITION.md`.

### Consommer un article du stock — `POST /api/stocks/items/{item}/consume`

```json
{"quantity": 100, "unit": "g", "meal_type": "dejeuner", "add_to_meal": true}
```

Décrémente le stock sous verrou, n'efface jamais l'article (il passe à zéro et devient
« épuisé »), crée l'élément de repas correspondant quand l'article est relié à un aliment, et
alimente la liste de courses lorsque le stock tombe à zéro. La réponse renvoie la quantité
précédente pour permettre l'annulation.

### Générer une séance — `POST /api/sport/sessions/generate`

```json
{"goal": "prise_de_muscle", "level": "intermediaire", "duration_min": 45,
 "lieu": "salle_publique", "equipment": ["halteres", "barre", "machine", "banc"],
 "focus": ["haut_du_corps"], "zones_a_eviter": ["genoux"],
 "notes": "j'ai mal au genou droit", "mode": "ia"}
```

Réponse : une proposition **non enregistrée** contenant `title`, `duration_min`,
`calories_estimate`, `generated_by` (`ia` ou `regles`), `llm_model`, `explication[]`,
`warnings[]` et `blocks[]` (échauffement, circuit principal, retour au calme) avec leurs
exercices détaillés. Sans clé `ANTHROPIC_API_KEY`, en cas d'erreur du modèle ou si
l'utilisateur a désactivé l'option, la séance est produite par les règles et un avertissement
le signale. Les exercices sollicitant une zone déclarée douloureuse sont systématiquement
écartés.

Pour la conserver : `POST /api/sport/sessions` avec la proposition telle quelle plus `date`.
Puis `/start`, `/complete` (qui calcule les calories et renvoie le bonus ajouté au budget),
`/cancel` ou `DELETE`.

### Calendrier sportif — `POST /api/sport/calendar`

```json
{"date": "2026-09-18", "sport_id": 7, "planned_duration_min": 60,
 "planned_at": "18:30", "lieu": "exterieur"}
```

`POST /api/sport/calendar/recurring` ajoute `weekday` (1 pour lundi) et `weeks` (1 à 12) et
crée autant d'entrées partageant un identifiant de série ; la suppression propose alors de
retirer toute la série. `POST /api/sport/calendar/{plan}/log` enregistre la séance réalisée :
les calories sont calculées automatiquement, ou reprises telles quelles si le corps contient
`calories_burned`, auquel cas la séance est marquée `calories_source: "manuel"`.

Un sport absent du catalogue se crée avec `POST /api/sport/sports` (`name`, `category`, et une
valeur MET facultative) : il reste privé à son auteur.

Règles de calcul des calories et de génération : voir `REGLES_SPORT.md`.

### Tableau de bord — `GET /api/dashboard?date=`

Une seule requête pour tout l'écran d'accueil : objectifs, consommé, restant, bonus sportif,
repas du jour, prochain repas, alertes de stock, séances et série, recommandations du coach,
poids et historique récent, nombre de notifications non lues.

---

## Compte de démonstration

`demo@mavioh.app` / `Demo1234!`, créé par `php artisan migrate --seed`. Le script
`scripts/smoke-api.sh` vérifie 25 routes clés avec ce compte.
