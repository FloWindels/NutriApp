# Mavi'oh — Modèle de données

Référence exhaustive du schéma de la base, générée à partir de `backend/database/migrations/*`
et des modèles Eloquent de `backend/app/Models/*`.

- **SGBD** : PostgreSQL en production, SQLite en développement et pour les tests (`:memory:`).
  **MySQL n'est pas supporté.**
- **Fuseau** : tout ce qui est `timestamp` est stocké en **UTC**. Les colonnes `date` (`Y-m-d`)
  représentent une journée dans le fuseau de l'utilisateur (`user_settings.timezone`,
  défaut `Europe/Paris`) — voir `App\Support\Clock`.
- **Colonnes énumérées** : jamais de type `enum` SQL. Ce sont des `string(16)` (statuts) ou
  `string(32)` (autres), validées côté application par les énumérations de `App\Enums`.
  Les valeurs sont en français, en `snake_case`.
- **Clés étrangères** : déclarées **uniquement à l'intérieur de `Schema::create()`**. Une colonne
  ajoutée à une table existante (`Schema::table()`) n'a **pas** de contrainte FK sous SQLite
  (limitation Laravel 10) : c'est le cas de `users.household_id`, `stocks.household_id`,
  `shopping_items.user_id`, `shopping_items.household_id`, `meal_plans.household_id`,
  `workout_sessions.sport_plan_id`. La suppression métier est donc toujours explicite
  (`AccountDeletionService`, `HouseholdService`) dans une transaction : on ne s'appuie jamais
  sur une cascade SQL pour la correction fonctionnelle.
- **Rollback** : sous SQLite, `down()` n'appelle jamais `dropForeign` / `dropConstrainedForeignId` ;
  la remise à zéro se fait avec `php artisan migrate:fresh`.
- **JSON** : les colonnes `json` sont créées avec `$table->json(...)` (type `text` sous SQLite,
  `json` sous PostgreSQL) et castées en `array` par le modèle.

Conventions de lecture des tableaux : « Null » = la colonne accepte `NULL` ; « Défaut » = valeur
par défaut SQL ; les tailles sont celles déclarées dans la migration.

---

## Vue d'ensemble

| Table | Module | Rôle |
|---|---|---|
| `users` | M1 | Comptes |
| `password_reset_tokens` | M1 | Jetons de réinitialisation (Laravel) |
| `personal_access_tokens` | M1 | Jetons Sanctum |
| `failed_jobs` | — | Files d'attente en échec (Laravel) |
| `user_settings` | M1 | Préférences, fuseau, rappels |
| `notification_reads` | M1 | Notifications marquées comme lues |
| `profiles` | M2 | Profil nutrition & sport, cibles |
| `weight_logs` | M2 | Journal de poids |
| `daily_targets` | M4 | Cibles figées du jour |
| `food` | M3 | Catalogue d'aliments (singulier) |
| `food_favorites` | M3 | Favoris d'aliments (pivot) |
| `recipes` | M3 | Recettes |
| `meals` | M4 | Repas d'une journée |
| `meal_items` | M4 | Éléments d'un repas (instantanés) |
| `stocks` | M5 | Lieux de stock (Frigo, Placard…) |
| `stock_items` | M5 | Articles en stock |
| `households` | M6 | Foyers |
| `household_members` | M6 | Membres d'un foyer |
| `shopping_items` | M7 | Liste de courses |
| `meal_plans` | M7 | Planificateur de la semaine |
| `recommendations` | M9 | Coach du jour |
| `exercises` | M8 | Catalogue d'exercices |
| `sports` | M8 | Catalogue de sports |
| `workout_sessions` | M8 | Séances et activités |
| `workout_exercises` | M8 | Exercices d'une séance |
| `sport_plans` | M8 | Calendrier sportif |

---

## M1 — Compte, paramètres, notifications

### `users`

Comptes Mavi'oh. `household_id` est un **cache** du foyer courant, écrit uniquement par
`App\Services\Household\HouseholdService` dans la même transaction que l'adhésion.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | Clé primaire |
| `name` | string(255) | non | — | Prénom / nom affiché |
| `email` | string(255) | non | — | Normalisé en minuscules à l'inscription |
| `email_verified_at` | timestamp | oui | `null` | Vérification de l'adresse |
| `password` | string(255) | non | — | Hachage bcrypt |
| `remember_token` | string(100) | oui | `null` | Laravel |
| `household_id` | bigint | oui | `null` | Foyer courant (sans FK, voir préambule) |
| `consentement_sante_at` | timestamp | oui | `null` | Horodatage de l'accord santé (§2 du cahier des charges) |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `users_email_unique` (unique sur `email`), `users_household_id_index`.

**Relations** : `profile` (hasOne), `settings` (hasOne), `householdMembership` (hasOne),
`household` (belongsTo), `ownedHouseholds` (hasMany), `meals`, `weightLogs`, `dailyTargets`,
`shoppingItems`, `mealPlans`, `recommendations`, `foods`, `recipes`, `stocks`,
`workoutSessions`, `sportPlans`, `sports`, `notificationReads` (hasMany),
`favoriteFoods` (belongsToMany `food` via `food_favorites`).

### `user_settings`

Une ligne par utilisateur, créée à l'inscription puis paresseusement (`firstOrCreate`).

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | Unique |
| `notif_peremption` | boolean | non | `true` | Alerte de péremption |
| `notif_rappel_repas` | boolean | non | `false` | Rappel des repas planifiés |
| `notif_rappel_sport` | boolean | non | `false` | Rappel des séances |
| `heure_rappel` | time | oui | `null` | Heure des rappels (`HH:MM:SS`) |
| `jours_alerte_peremption` | tinyint | non | `3` | Fenêtre d'alerte (1–14) |
| `unites` | string(16) | non | `metrique` | `metrique` \| `imperial` |
| `theme` | string(16) | non | `systeme` | `systeme` \| `clair` \| `sombre` (`App\Enums\Theme`) |
| `langue` | string(8) | non | `fr` | `fr` \| `en` |
| `timezone` | string(64) | non | `Europe/Paris` | Fuseau utilisé par `Clock` |
| `ia_seances` | boolean | non | `true` | Autorise la génération de séances par l'IA |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `user_settings_user_id_unique`.

> `partage_profil_foyer` n'est **pas** une colonne : l'API le lit et l'écrit dans
> `household_members.share_profile` (null si l'utilisateur n'a pas de foyer).

### `notification_reads`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `user_id` | bigint FK `users` cascade | non | — | Partie de la clé primaire |
| `key` | string(64) | non | — | Clé stable de la notification (`{type}:{id}`) |
| `read_at` | timestamp | oui | `null` | Date de lecture |

**Clé primaire composite** : (`user_id`, `key`).

### `password_reset_tokens` / `personal_access_tokens` / `failed_jobs`

Tables standard de Laravel et Sanctum, non modifiées.
`personal_access_tokens.expires_at` est exploité par `SANCTUM_EXPIRATION` (43 200 minutes = 30 jours)
et par la commande planifiée `sanctum:prune-expired --hours=24`.

---

## M2 — Profil & poids

### `profiles`

Une ligne par utilisateur (`user_id` unique). Les colonnes historiques `poids_a_perdre_kg`,
`delai_objectif_semaines` et `bien_etre` sont conservées mais **inutilisées**.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | oui | `null` | Unique |
| `poids` | decimal(6,2) | oui | `null` | Poids actuel (kg) |
| `poids_souhaite_kg` | decimal(6,2) | oui | `null` | Poids visé |
| `delai_objectif_jours` | smallint | oui | `null` | Délai demandé (jours) |
| `taille` | decimal(6,2) | oui | `null` | Taille (cm) |
| `age` | tinyint | oui | `null` | 12–120 |
| `sexe` | string(20) | oui | `null` | `homme` \| `femme` |
| `objectif` | string(100) | oui | `null` | Texte libre |
| `objectif_type` | string(20) | oui | `null` | `perdre` \| `maintenir` \| `prendre` |
| `niveau_activite` | string(100) | oui | `null` | `sedentaire`, `leger`, `modere`, `eleve`, `tres_eleve` |
| `regime_alimentaire` | string(100) | oui | `null` | 14 valeurs (voir `config/diets.php`) |
| `calories_cibles` | smallint | oui | `null` | Cible du jour (calculée ou saisie) |
| `proteines_cibles` | smallint | oui | `null` | g |
| `glucides_cibles` | smallint | oui | `null` | g |
| `lipides_cibles` | smallint | oui | `null` | g |
| `allergenes` | json | oui | `null` | Liste de libellés |
| `aliments_exclus` | json | oui | `null` | Liste de libellés |
| `preferences` | json | oui | `null` | `{aime: [], evite: []}` |
| `objectif_calcul_auto` | boolean | non | `true` | `false` = cibles saisies par l'utilisateur |
| `objectif_date_debut` | date | oui | `null` | Début de l'objectif |
| `objectif_date_fin` | date | oui | `null` | Fin visée |
| `poids_reference` | decimal(6,2) | oui | `null` | Poids ayant servi au dernier calcul |
| `cibles_calculees_le` | date | oui | `null` | Date du dernier calcul |
| `situation_particuliere` | string(32) | non | `aucune` | `aucune`, `grossesse`, `allaitement`, `suivi_medical` |
| `consentement_parental` | boolean | non | `false` | Requis avant 15 ans |
| `sport_niveau` | string(16) | oui | `null` | `debutant`, `intermediaire`, `avance` |
| `sport_objectif` | string(32) | oui | `null` | `perte_de_gras`, `prise_de_muscle`, `endurance`, `forme`, `force` |
| `sport_materiel` | json | oui | `null` | Sous-ensemble de `App\Enums\Equipment` |
| `sport_temps_dispo_min` | smallint | oui | `null` | 5–600 |
| `sport_jours_semaine` | tinyint | oui | `null` | 0–7 |
| `sport_lieu` | string(16) | oui | `null` | `maison`, `exterieur`, `salle_publique`, `salle_privee` |
| `sport_zones_a_eviter` | json | oui | `null` | `genoux, dos, epaules, poignets, hanches, cou, chevilles, coudes` |
| `sport_focus` | json | oui | `null` | 16 valeurs (voir `UpdateProfileRequest::FOCUS`) |
| `sport_notes` | text | oui | `null` | Texte libre (douleurs, contraintes) |
| `sport_coef_calories` | tinyint | non | `100` | % des calories brûlées réintégrées (50 \| 75 \| 100) |
| `poids_a_perdre_kg` | decimal(6,2) | oui | `null` | **Hérité, inutilisé** |
| `delai_objectif_semaines` | smallint | oui | `null` | **Hérité, inutilisé** |
| `bien_etre` | string(20) | oui | `null` | **Hérité, inutilisé** |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `profiles_user_id_unique`.

**Casts** : décimales en `float`, dates en `date:Y-m-d`, `allergenes`/`aliments_exclus`/
`preferences`/`sport_materiel`/`sport_zones_a_eviter`/`sport_focus` en `array`, booléens en `boolean`.

### `weight_logs`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | — |
| `date` | date | non | — | Jour de la pesée |
| `weight_kg` | decimal(6,2) | non | — | 20–500 |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `weight_logs_user_id_date_unique` (unique sur `user_id`, `date`).

### `daily_targets`

Cibles **figées** au premier élément de repas d'une journée, pour que l'historique reste fidèle.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | — |
| `date` | date | non | — | Jour concerné |
| `calories` | smallint | non | — | kcal |
| `proteins` | smallint | non | — | g |
| `carbs` | smallint | non | — | g |
| `fat` | smallint | non | — | g |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `daily_targets_user_id_date_unique`.

---

## M3 — Aliments & recettes

### `food`

Table au nom **singulier** (`Food::$table = 'food'`). Toute FK vers elle utilise
`->constrained('food')` et toute règle de validation `exists:food,id`.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `barcode` | string(255) | oui | `null` | EAN 8–14 chiffres ; unique (les `NULL` restent distincts) |
| `name` | string(255) | non | — | Nom du produit |
| `brand` | string(255) | oui | `null` | Marque |
| `image_url` | text | oui | `null` | URL ou data-URI |
| `calories` | decimal | oui | `null` | kcal pour 100 g/ml |
| `fat` | decimal | oui | `null` | g |
| `carbs` | decimal | oui | `null` | g |
| `proteins` | decimal | oui | `null` | g |
| `fiber` | decimal(8,2) | oui | `null` | g |
| `sugar` | decimal(8,2) | oui | `null` | g |
| `salt` | decimal(8,2) | oui | `null` | g |
| `serving_size_g` | decimal(8,2) | oui | `null` | Poids d'une portion |
| `serving_label` | string(64) | oui | `null` | « 1 pot (125 g) » |
| `category` | string(100) | oui | `null` | Catégorie (issue d'Open Food Facts ou saisie) |
| `allergens` | json | oui | `null` | `allergens_tags` d'Open Food Facts |
| `density_g_per_ml` | decimal(5,3) | oui | `null` | Conversion ml → g |
| `per_unit` | string(8) | non | `100g` | `100g` \| `100ml` |
| `source_type` | string(255) | non | `manual` | `manual` \| `open_food_facts` \| `recipe` |
| `source_fetched_at` | timestamp | oui | `null` | Import Open Food Facts |
| `off_last_checked_at` | timestamp | oui | `null` | Dernier rafraîchissement OFF (90 jours) |
| `is_verified` | boolean | non | `false` | Fiche relue par un utilisateur |
| `created_by_user_id` | bigint FK `users` nullOnDelete | oui | `null` | Créateur (null pour une fiche OFF) |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `food_barcode_unique`.

### `food_favorites`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `user_id` | bigint FK `users` cascade | non | — | Clé primaire composite |
| `food_id` | bigint FK `food` cascade | non | — | Clé primaire composite |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

### `recipes`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `created_by_user_id` | bigint FK `users` nullOnDelete | oui | `null` | Auteur |
| `title` | string(255) | non | — | Titre |
| `description` | text | oui | `null` | — |
| `prep_time_minutes` | integer | oui | `null` | Requis pour publier |
| `calories` | decimal | non | — | Calories **totales** de la recette |
| `image_url` | text | oui | `null` | Jusqu'à 500 000 caractères (data-URI acceptée) |
| `ingredients` | json | oui | `null` | `[{name, ean, amount, unit}]` |
| `is_public` | boolean | non | `false` | Publication (photo + ≥ 1 ingrédient + temps requis) |
| `servings` | decimal(4,1) | non | `1` | Nombre de portions |
| `proteins` | decimal(8,2) | oui | `null` | g, total |
| `carbs` | decimal(8,2) | oui | `null` | g, total |
| `fat` | decimal(8,2) | oui | `null` | g, total |
| `tags` | json | oui | `null` | Vocabulaire fermé (14 étiquettes) |
| `meal_types` | json | oui | `null` | Sous-ensemble de `MealType` |
| `is_estimate` | boolean | non | `false` | Macros estimées depuis les ingrédients |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `recipes_created_by_user_id_is_public_index`.

---

## M4 — Repas & suivi quotidien

### `meals`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | — |
| `date` | date | non | — | Jour du repas |
| `type` | string(32) | non | — | `petit_dejeuner`, `dejeuner`, `diner`, `collation` |
| `name` | string(255) | oui | `null` | Nom libre (« Porridge du matin ») |
| `consumed_at` | timestamp | oui | `null` | Heure réelle (UTC) |
| `notes` | text | oui | `null` | — |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `meals_user_id_date_type_unique` (unique), `meals_user_id_date_index`.

### `meal_items`

Chaque élément stocke un **instantané** nutritionnel (`calories`… ) **et** sa base de calcul
(`ref_*`) : modifier un aliment du catalogue ne change jamais un repas déjà enregistré.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `meal_id` | bigint FK `meals` cascade | non | — | — |
| `food_id` | bigint FK `food` nullOnDelete | oui | `null` | Si source `food` |
| `recipe_id` | bigint FK `recipes` nullOnDelete | oui | `null` | Si source `recipe` |
| `stock_item_id` | bigint FK `stock_items` nullOnDelete | oui | `null` | Article consommé |
| `source_type` | string(16) | non | — | `food` \| `recipe` \| `custom` |
| `label` | string(255) | non | — | Libellé affiché |
| `quantity` | decimal(8,2) | non | — | Quantité saisie |
| `unit` | string(16) | non | — | Unité (`App\Enums\Unit` + alias) |
| `grams_equivalent` | decimal(8,2) | oui | `null` | Conversion en grammes (null pour une recette) |
| `calories` | decimal(8,2) | non | — | Instantané total |
| `proteins` | decimal(8,2) | non | — | g |
| `carbs` | decimal(8,2) | non | — | g |
| `fat` | decimal(8,2) | non | — | g |
| `fiber`, `sugar`, `salt` | decimal(8,2) | oui | `null` | g |
| `ref_basis` | string(16) | non | — | `per_100g` \| `per_serving` \| `absolute` |
| `ref_calories`, `ref_proteins`, `ref_carbs`, `ref_fat`, `ref_fiber`, `ref_sugar`, `ref_salt` | decimal(8,2) | oui | `null` | Valeurs de référence |
| `ref_serving_size_g` | decimal(8,2) | oui | `null` | Portion de référence |
| `is_estimate` | boolean | non | `false` | Conversion de portion ou donnée manquante |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `meal_items_meal_id_index`, `meal_items_food_id_index`, `meal_items_recipe_id_index`.

---

## M5 — Stock

### `stocks`

Un lieu de stock est **soit** personnel (`user_id` renseigné, `household_id` nul) **soit**
partagé par un foyer (`user_id` nul, `household_id` renseigné) — voir `App\Support\StockScope`.
Les trois lieux `Frigo`, `Congélateur`, `Placard` sont créés à la demande au premier `GET /stocks`.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint | oui | `null` | Propriétaire personnel |
| `household_id` | bigint | oui | `null` | Foyer propriétaire (sans FK, voir préambule) |
| `name` | string(255) | non | `Frigo` | Nom du lieu |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** :
- `stocks_user_name_unique` — `UNIQUE (user_id, LOWER(name)) WHERE household_id IS NULL` ;
- `stocks_household_name_unique` — `UNIQUE (household_id, LOWER(name)) WHERE household_id IS NOT NULL` ;
- `stocks_household_id_index`.

Les deux index partiels sont créés par `DB::statement` et s'écrivent à l'identique sous
PostgreSQL et SQLite.

### `stock_items`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `stock_id` | bigint | oui | `null` | Lieu de rangement |
| `food_id` | bigint | oui | `null` | Aliment relié (facultatif) |
| `food_name` | string(255) | oui | `null` | Libellé affiché |
| `food_barcode` | string(255) | oui | `null` | EAN |
| `food_brand` | string(255) | oui | `null` | Marque |
| `quantity` | decimal | non | `1` | Quantité restante (jamais négative) |
| `unit` | string(255) | non | `unite` | Unité de stock |
| `expires_at` | date | oui | `null` | DLC/DDM |
| `expiry_kind` | string(8) | non | `dlc` | `dlc` \| `ddm` |
| `min_quantity` | decimal(8,2) | oui | `null` | Seuil « stock bas » |
| `opened_at` | date | oui | `null` | Date d'ouverture |
| `depleted_at` | timestamp | oui | `null` | Épuisement (quantité ≤ 0) ; remis à `null` dès que la quantité repasse au-dessus de 0 |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `stock_items_stock_id_index`, `stock_items_food_id_index`,
`stock_items_food_barcode_index`, `stock_items_expires_at_index`.

> Un article n'est **jamais supprimé** par une consommation : `StockService::decrement()`
> le ramène à 0 et pose `depleted_at`, puis ajoute une ligne `shopping_items`
> (`source = auto_stock`) si aucun article non coché du même libellé n'existe déjà.

---

## M6 — Foyer

### `households`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `name` | string(255) | non | — | Nom du foyer (2–80 caractères côté API) |
| `owner_id` | bigint FK `users` **restrictOnDelete** | non | — | Propriétaire |
| `invite_code` | string(8) | non | — | Alphabet `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, comparé en majuscules |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `households_invite_code_unique`.

### `household_members`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `household_id` | bigint FK `households` cascade | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | **Unique** : un utilisateur n'appartient qu'à un foyer |
| `role` | string(16) | non | `membre` | `proprietaire` \| `membre` |
| `share_profile` | boolean | non | `true` | Partage des cibles et du régime |
| `joined_at` | timestamp | non | `CURRENT_TIMESTAMP` | Date d'adhésion (sert au transfert de propriété) |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `household_members_user_id_unique`.

---

## M7 — Courses & planificateur

### `shopping_items`

Portée personnelle ou foyer via `App\Support\OwnerScope` (exactement une des deux colonnes
de portée est renseignée).

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint | oui | `null` | Portée personnelle |
| `household_id` | bigint | oui | `null` | Portée foyer |
| `food_id` | bigint FK `food` nullOnDelete | oui | `null` | Aliment relié |
| `label` | string(255) | non | — | Libellé |
| `quantity` | decimal(8,2) | oui | `null` | — |
| `unit` | string(16) | oui | `null` | — |
| `checked` | boolean | non | `false` | Coché |
| `source` | string(16) | non | `manuel` | `manuel`, `auto_stock`, `planificateur`, `recommandation` |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `shopping_items_user_id_index`, `shopping_items_household_id_index`.

### `meal_plans`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | — |
| `household_id` | bigint | oui | `null` | Portée foyer (sans FK) |
| `date` | date | non | — | Jour planifié |
| `meal_type` | string(32) | non | — | `MealType` |
| `recipe_id` | bigint FK `recipes` nullOnDelete | oui | `null` | Recette planifiée |
| `food_id` | bigint FK `food` nullOnDelete | oui | `null` | Aliment planifié |
| `title` | string(255) | non | — | Titre affiché (repli sur la recette / l'aliment) |
| `servings` | decimal(4,1) | non | `1` | Portions |
| `notes` | text | oui | `null` | — |
| `status` | string(16) | non | `prevu` | `prevu` \| `realise` \| `annule` |
| `meal_id` | bigint FK `meals` nullOnDelete | oui | `null` | Repas créé lors de la réalisation |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `meal_plans_user_id_date_index`, `meal_plans_household_id_date_index`.

---

## M9 — Coach

### `recommendations`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | — |
| `date` | date | non | — | Jour de la recommandation |
| `type` | string(32) | non | — | `sous_plancher`, `produit_perime`, `alerte_budget`, `budget_restant`, `manque_proteines`, `anti_gaspillage`, `suggestion_repas`, `ajustement_portions`, `sport_pre`, `sport_post`, `courses`, `hydratation`, `profil_incomplet` |
| `dedupe_key` | string(64) | non | — | `sha1(type + '|' + title)` |
| `title` | string(255) | non | — | — |
| `message` | text | non | — | Copie en français, tutoiement |
| `factors` | json | oui | `null` | Chiffres ayant motivé la reco |
| `actions` | json | oui | `null` | `[{kind, …}]` |
| `priority` | tinyint | non | `3` | 1 sécurité, 2 objectif du jour, 3 confort |
| `status` | string(16) | non | `new` | `new` \| `acceptee` \| `ignoree` |
| `is_estimate` | boolean | non | `false` | — |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `recommendations_user_id_date_dedupe_key_unique` — la régénération est un `upsert`
qui **préserve** le `status` déjà choisi par l'utilisateur.

---

## M8 — Sport

### `exercises`

Catalogue public d'exercices, semé par `ExerciseSeeder` (upsert par `slug`, 103 entrées).

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `name` | string(255) | non | — | Nom français |
| `slug` | string(255) | non | — | Unique |
| `category` | string(16) | non | — | `force`, `cardio`, `mobilite`, `gainage` |
| `muscle_group` | string(32) | non | — | `jambes`, `fessiers`, `dos`, `pectoraux`, `epaules`, `bras`, `abdos`, `corps_entier`, `cardio`, `mobilite` |
| `equipment` | string(32) | non | — | `App\Enums\Equipment` |
| `level` | string(16) | non | — | `debutant`, `intermediaire`, `avance` |
| `met` | decimal(4,1) | non | — | MET de référence |
| `default_sets` | tinyint | oui | `null` | Séries par défaut |
| `default_reps` | tinyint | oui | `null` | Répétitions par défaut |
| `default_duration_sec` | smallint | oui | `null` | Durée par défaut |
| `instructions` | text | non | — | Consignes d'exécution |
| `contraindications` | json | oui | `null` | Zones à éviter concernées (`genoux`, `dos`, …) |
| `is_public` | boolean | non | `true` | — |
| `created_by_user_id` | bigint | oui | `null` | — |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `exercises_slug_unique`, `exercises_equipment_index`, `exercises_muscle_group_index`,
`exercises_category_level_index`.

### `sports`

Catalogue de sports, semé par `SportSeeder` (upsert par `slug`, 43 entrées publiques).
Un sport créé par un utilisateur a `is_public = false` et un `slug` suffixé `-u{userId}`.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `name` | string(80) | non | — | Nom affiché |
| `slug` | string(100) | non | — | Unique |
| `category` | string(32) | non | — | `endurance`, `force`, `collectif`, `raquette`, `aquatique`, `combat`, `bien_etre`, `glisse`, `autre` |
| `met_faible` | decimal(4,1) | non | — | MET intensité faible |
| `met_moderee` | decimal(4,1) | non | — | MET intensité modérée |
| `met_elevee` | decimal(4,1) | non | — | MET intensité élevée |
| `icon` | string(32) | oui | `null` | Nom d'icône Material (`directions_run`…) |
| `is_public` | boolean | non | `true` | `false` = sport personnel |
| `created_by_user_id` | bigint | oui | `null` | Créateur d'un sport personnel |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `sports_slug_unique`, `sports_category_index`, `sports_created_by_user_id_index`.

### `workout_sessions`

Une séance structurée (`kind = seance`) ou une activité déclarée (`kind = activite`).

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | — |
| `date` | date | non | — | Jour |
| `planned_at` | time | oui | `null` | Heure prévue |
| `title` | string(255) | non | — | Titre |
| `kind` | string(16) | non | `seance` | `seance` \| `activite` |
| `goal` | string(32) | oui | `null` | `perte_de_gras`, `prise_de_muscle`, `endurance`, `forme`, `force` |
| `level` | string(16) | oui | `null` | `debutant`, `intermediaire`, `avance` |
| `equipment` | json | oui | `null` | Matériel utilisé |
| `focus` | json | oui | `null` | Zones travaillées (liste) |
| `duration_min` | smallint | non | — | Durée |
| `calories_burned` | decimal(7,1) | oui | `null` | Calories nettes |
| `calories_source` | string(8) | non | `auto` | `auto` (estimation MET) \| `manuel` (saisie) |
| `status` | string(16) | non | `prevue` | `prevue`, `en_cours`, `terminee`, `annulee` |
| `rpe` | tinyint | oui | `null` | Ressenti 1–10 |
| `notes` | text | oui | `null` | — |
| `source` | string(16) | non | `manuelle` | `generee`, `manuelle`, `catalogue`, `activite` |
| `generated_by` | string(8) | oui | `null` | `regles` \| `ia` |
| `llm_model` | string(64) | oui | `null` | Modèle utilisé si `generated_by = ia` |
| `intensity` | string(16) | oui | `null` | `faible`, `moderee`, `elevee` |
| `distance_km` | decimal(6,2) | oui | `null` | Pour les activités d'endurance |
| `sport_id` | bigint FK `sports` nullOnDelete | oui | `null` | Sport pratiqué |
| `sport_name` | string(80) | oui | `null` | Instantané du nom |
| `lieu` | string(16) | oui | `null` | `maison`, `exterieur`, `salle_publique`, `salle_privee` |
| `zones_a_eviter` | json | oui | `null` | Zones douloureuses prises en compte |
| `sport_plan_id` | bigint | oui | `null` | Entrée de calendrier liée (**sans FK** : les plans référencent déjà les séances, on évite le cycle) |
| `started_at` | timestamp | oui | `null` | Démarrage |
| `completed_at` | timestamp | oui | `null` | Fin |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `workout_sessions_user_id_date_index`, `workout_sessions_sport_plan_id_index`.

> La colonne historique `activity_type` a été **supprimée** : le sport est porté par
> `sport_id` / `sport_name`.

### `workout_exercises`

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `session_id` | bigint FK `workout_sessions` cascade | non | — | — |
| `exercise_id` | bigint FK `exercises` nullOnDelete | oui | `null` | Exercice du catalogue |
| `block` | string(32) | non | `principal` | `echauffement`, `principal`, `retour_au_calme` |
| `position` | smallint | non | `0` | Ordre dans la séance |
| `name` | string(255) | non | — | Instantané du nom |
| `sets` | tinyint | oui | `null` | Séries |
| `reps` | tinyint | oui | `null` | Répétitions |
| `duration_sec` | smallint | oui | `null` | Durée |
| `weight_kg` | decimal(5,1) | oui | `null` | Charge |
| `rest_sec` | smallint | oui | `null` | Repos |
| `met` | decimal(4,1) | oui | `null` | MET retenu pour le calcul calorique |
| `completed` | boolean | non | `false` | Coché pendant la séance |
| `notes` | text | oui | `null` | — |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `workout_exercises_session_id_position_index`.

### `sport_plans`

Calendrier sportif : « quel sport, quel jour, combien de temps ».

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | — | — |
| `user_id` | bigint FK `users` cascade | non | — | — |
| `date` | date | non | — | Jour planifié |
| `sport_id` | bigint FK `sports` nullOnDelete | oui | `null` | Sport du catalogue |
| `sport_name` | string(80) | non | — | Libellé (instantané ou texte libre) |
| `planned_duration_min` | smallint | non | — | Durée prévue (5–600) |
| `planned_at` | time | oui | `null` | Heure prévue |
| `lieu` | string(16) | oui | `null` | `maison`, `exterieur`, `salle_publique`, `salle_privee` |
| `notes` | text | oui | `null` | — |
| `status` | string(16) | non | `prevu` | `prevu` \| `realise` \| `annule` |
| `session_id` | bigint FK `workout_sessions` nullOnDelete | oui | `null` | Séance qui a réalisé le plan |
| `recurrence_id` | string(36) | oui | `null` | UUID partagé par une série hebdomadaire |
| `created_at`, `updated_at` | timestamp | oui | `null` | — |

**Index** : `sport_plans_user_id_date_index`, `sport_plans_recurrence_id_index`.

---

## Tables supprimées

`families` et `family_members` (module Famille v0) sont **supprimées** par la migration
`2026_09_16_000020_drop_legacy_family_tables` et remplacées par `households` /
`household_members`.

---

## Suppression d'un compte

`App\Services\Account\AccountDeletionService::delete()` exécute, dans **une seule transaction** :

1. vérification du mot de passe ;
2. traitement du foyer (transfert de propriété, dissolution ou départ) ;
3. suppressions explicites : recettes privées, lieux de stock personnels et leurs articles,
   profil, pesées, cibles quotidiennes, repas et leurs éléments, séances et leurs exercices,
   plans sportifs, recommandations, paramètres, notifications lues, articles de courses
   personnels, repas planifiés ;
4. les recettes publiques et les aliments restent dans le catalogue, `created_by_user_id`
   étant mis à `null` ;
5. suppression des jetons puis de l'utilisateur.

`tests/Feature/Account/AccountDeletionTest` vérifie l'absence de ligne orpheline par comptage explicite.
