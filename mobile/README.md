# Mavi'oh — application mobile

Application Flutter (Android, iOS) du coach nutrition et sport Mavi'oh. Elle consomme la même API
Laravel que le site web : voir le [README du dépôt](../README.md) et `docs/API.md`.

## Lancer l'application

Le backend doit écouter sur toutes les interfaces pour être joignable depuis un téléphone :

```bash
cd ../backend && php artisan serve --host 0.0.0.0 --port 8000
```

Puis, depuis ce dossier :

```bash
flutter pub get
flutter run --dart-define=API_BASE_URL=http://192.168.x.x:8000/api
```

Remplace `192.168.x.x` par l'adresse IP locale du PC qui fait tourner l'API. Les scripts
`scripts/start-mobile.ps1` (Windows) et `scripts/start-mobile.sh` (Linux, macOS) détectent cette
adresse automatiquement.

Sans `--dart-define`, l'application utilise `http://10.0.2.2:8000/api`, l'adresse de l'hôte vue
depuis l'émulateur Android.

## Compte de démonstration

`demo@mavioh.app` / `Demo1234!` (créé par `php artisan migrate --seed` côté backend).

## Build de production

```bash
flutter build apk --release --dart-define=API_BASE_URL=https://api.mondomaine.fr/api
```

Le fichier produit est `build/app/outputs/flutter-apk/app-release.apk`. Une diffusion sur le Play
Store nécessite au préalable une clé de signature : la configuration actuelle utilise encore la clé
de debug.

## Qualité

```bash
flutter analyze
flutter test
```

## Organisation du code

| Dossier | Contenu |
|---|---|
| `lib/core/` | client HTTP unique, session, formats français, libellés |
| `lib/models/` | modèles de données correspondant aux réponses de l'API |
| `lib/services/` | une classe par domaine métier, une méthode par endpoint |
| `lib/widgets/` | composants partagés (cartes, scanner, sélecteur de portions, ajout à un repas) |
| `lib/screens/` | écrans, un par section de navigation |
| `lib/theme/` | couleurs et thème Material 3 |
| `lib/navigation/` | table des 13 sections de l'application |
