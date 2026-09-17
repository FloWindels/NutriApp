# Mavi'oh

Coach nutrition et sport. Mavi'oh suit les repas, le stock du foyer, les objectifs personnalisés et
les séances de sport, puis propose des actions concrètes : quoi manger ensuite, quoi utiliser avant
péremption, quelle séance faire aujourd'hui.

Le produit se compose de trois applications qui partagent la même API :

| Dossier | Rôle | Technologies |
|---|---|---|
| `backend/` | API REST, règles métier, calculs, base de données | Laravel 10, Sanctum, PostgreSQL (SQLite en dev) |
| `web/` | site client sur ordinateur | Next.js 16, React 19, TypeScript, Tailwind, TanStack Query |
| `mobile/` | application Android et iOS | Flutter 3.47, Dio |
| `docs/` | documentation fonctionnelle et technique | Markdown |
| `scripts/` | lancement en local et déploiement | PowerShell, Bash |

Le cahier des charges d'origine est `Cahier_des_charges_NutriApp_v1.0.pdf`.

---

## Fonctionnalités

**Nutrition**
- Inscription, connexion, déconnexion avec révocation du jeton, réinitialisation du mot de passe.
- Profil nutritionnel complet et objectifs calculés côté serveur (Mifflin-St Jeor, facteurs
  d'activité, bornes de sécurité, règles adaptées aux mineurs et aux situations particulières).
- Base d'aliments avec recherche texte et code-barres, enrichie par Open Food Facts et mise en cache
  côté serveur, favoris, création d'aliments.
- Portions simples (gramme, pièce, bol, assiette, cuillère, verre…) avec conversion et signalement
  des estimations.
- Journal des repas par jour et par type, totaux et restants, historique, repas fréquents, copie du
  repas de la veille.
- Recettes avec photo, portions, macros et estimation depuis les ingrédients.
- Stock frigo / congélateur / placard : péremptions, alertes, consommation qui décrémente le stock et
  alimente la liste de courses.
- Coach à règles explicites : alertes de budget, manque de protéines, anti-gaspillage, suggestions de
  repas, ajustement des portions, conseils avant et après séance, chaque conseil expliquant ses
  facteurs déclencheurs.
- Régimes modélisés en règles (méditerranéen, DASH, flexitarien, low carb, keto, jeûne intermittent,
  végétarien, végan, sans gluten, sans lactose, Montignac, halal) avec évaluation du régime reconnu.
- Planificateur de la semaine, liste de courses partagée, mode famille avec repas commun et portions
  adaptées à chaque membre.

**Sport**
- Catalogue de plus de 40 sports et 100 exercices, plus la création de ses propres sports.
- Calendrier : planification des jours et des sports, répétition hebdomadaire, séances prévues ou
  réalisées.
- Enregistrement d'une séance avec durée et intensité ; calories brûlées calculées automatiquement
  (formule MET nette, poids réel de l'utilisateur) ou saisies manuellement.
- Les calories brûlées viennent s'ajouter au budget du jour, avec un coefficient réglable.
- Génération de séances personnalisées : objectif, niveau, temps disponible, lieu (maison, extérieur,
  salle publique ou privée), matériel, zones du corps à éviter et notes libres.
- Coach IA optionnel (Claude) qui reçoit le contexte de la personne et propose la séance ; en son
  absence ou en cas d'erreur, les règles Mavi'oh prennent le relais automatiquement.

---

## Démarrage rapide

### Prérequis

- PHP 8.1 ou plus avec les extensions `pdo_sqlite`, `pdo_pgsql`, `mbstring`, `xml`, `curl`, `intl`, `zip`
- Composer 2
- Node.js 20 ou plus
- Flutter 3.27 ou plus (pour l'application mobile uniquement)

### Windows

```powershell
.\scripts\start-all.ps1      # API sur 8000 et site web sur 3000
.\scripts\start-mobile.ps1   # application Flutter sur un téléphone connecté
```

### Linux et macOS

```bash
./scripts/start-all.sh
./scripts/start-mobile.sh
```

Les scripts créent les fichiers `.env` manquants, la base SQLite, appliquent les migrations et
chargent les données de démonstration au premier lancement.

Compte de démonstration : `demo@mavioh.app` / `Demo1234!`

### Installation manuelle

```bash
# API
cd backend
cp .env.example .env
composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --host 0.0.0.0 --port 8000

# Site web
cd ../web
cp .env.example .env.local
npm ci
npm run dev

# Mobile (téléphone sur le même réseau que le PC)
cd ../mobile
flutter pub get
flutter run --dart-define=API_BASE_URL=http://192.168.x.x:8000/api
```

---

## Configuration

Les variables importantes de `backend/.env` :

| Variable | Rôle |
|---|---|
| `DB_CONNECTION` | `sqlite` en développement, `pgsql` en production (MySQL non pris en charge) |
| `FRONTEND_URL` | base des liens de réinitialisation de mot de passe |
| `SANCTUM_EXPIRATION` | durée de vie des jetons en minutes (43200 = 30 jours) |
| `OFF_BASE_URL`, `OFF_USER_AGENT` | accès à Open Food Facts |
| `ANTHROPIC_API_KEY` | active le coach sportif IA ; vide, les séances sont générées par les règles |
| `ANTHROPIC_MODEL` | modèle utilisé, par défaut `claude-opus-5` |

Côté web, `API_URL` et `NEXT_PUBLIC_API_URL` pointent vers l'API. Côté mobile, l'URL est passée au
build avec `--dart-define=API_BASE_URL=...`.

---

## Tests

```bash
cd backend && php artisan test          # tests unitaires et d'API
cd web && npx tsc --noEmit && npm run lint && npm run build
cd mobile && flutter analyze && flutter test
```

---

## Documentation

| Fichier | Contenu |
|---|---|
| `docs/API.md` | référence complète des endpoints, requêtes et réponses |
| `docs/MODELE_DONNEES.md` | tables, colonnes, relations |
| `docs/REGLES_NUTRITION.md` | formules, bornes de sécurité, macros, régimes |
| `docs/REGLES_SPORT.md` | MET, calories, génération des séances, coach IA |
| `docs/RECETTE.md` | scénarios de recette et critères d'acceptation |
| `docs/DEPLOIEMENT_UBUNTU.md` | installation sur un serveur Ubuntu |
| `docs/DECISIONS.md` | choix techniques et périmètre de la version 1 |

---

## Hors périmètre de la version 1

Carte des restaurants, OCR des étiquettes, saisie vocale, reconnaissance de photo d'assiette,
notifications push système, espace d'administration `/admin`, mode hors ligne en écriture et thème
sombre sur mobile. Ces sujets sont cadrés dans le cahier des charges pour des versions ultérieures.

---

## Avertissement

Les objectifs, dépenses énergétiques et recommandations de Mavi'oh sont des estimations calculées à
partir de formules publiques. Ils ne constituent ni une mesure clinique ni un avis médical.
