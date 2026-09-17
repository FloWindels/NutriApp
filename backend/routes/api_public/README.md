# routes/api_public — routes publiques des modules (catalogues)

Un fichier par module, uniquement pour les catalogues sans authentification
(ex. `diets.php` pour `GET /diets`, `sport.php` pour `GET /sport/exercises`).

Ces fichiers sont `require`s **hors** du groupe `auth:sanctum`, à l'intérieur d'un groupe
`throttle:30,1`. Une route qui a besoin de l'utilisateur connecté n'a rien à faire ici :
utiliser `routes/api/{module}.php`.
