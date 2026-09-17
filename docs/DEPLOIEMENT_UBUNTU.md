# Déploiement de Mavi'oh sur un serveur Ubuntu

Ce guide installe l'API Laravel et le site Next.js sur un serveur Ubuntu 22.04 ou 24.04, à partir
du dépôt GitHub. L'application mobile Flutter se construit séparément (voir la dernière section).

Tout ce qui suit part du principe que tu es connecté en SSH sur le serveur avec un utilisateur
disposant de `sudo`.

---

## 1. Vue d'ensemble

| Composant | Rôle | Port par défaut |
|---|---|---|
| PostgreSQL | base de données | 5432 (local uniquement) |
| PHP-FPM + Nginx | API Laravel (`/api`) | 8000 en interne, 443 public |
| Node + Next.js | site web client | 3000 en interne, 443 public |
| Flutter | application Android/iOS | — (APK distribué séparément) |

Deux noms de domaine sont recommandés : `mavioh.mondomaine.fr` pour le site web et
`api.mavioh.mondomaine.fr` pour l'API. Un seul domaine fonctionne aussi, l'API étant alors servie
sous `/api` (voir la variante dans la configuration Nginx).

---

## 2. Installation des prérequis

Le script `scripts/deploy/setup-ubuntu.sh` fait tout ce qui suit et peut être relancé sans risque.

```bash
sudo apt update && sudo apt install -y git
git clone https://github.com/FloWindels/NutriApp.git /var/www/mavioh
cd /var/www/mavioh
sudo bash scripts/deploy/setup-ubuntu.sh
```

Il installe : PHP 8.3 (cli, fpm, pgsql, sqlite3, mbstring, xml, curl, zip, intl, bcmath, gd),
Composer, PostgreSQL, Node.js LTS, Nginx et Certbot.

Vérifie ensuite :

```bash
php -v && composer --version && node -v && psql --version && nginx -v
```

---

## 3. Base de données PostgreSQL

```bash
sudo -u postgres psql -c "CREATE USER mavioh WITH PASSWORD 'un-mot-de-passe-solide';"
sudo -u postgres psql -c "CREATE DATABASE mavioh OWNER mavioh;"
```

MySQL et MariaDB ne sont pas pris en charge : le schéma utilise des index uniques partiels que
seuls PostgreSQL et SQLite savent créer. SQLite reste utilisable pour un serveur personnel à faible
charge, mais PostgreSQL est le choix recommandé en production.

---

## 4. Configuration de l'API Laravel

```bash
cd /var/www/mavioh/backend
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
```

Édite `/var/www/mavioh/backend/.env` :

```dotenv
APP_NAME="Mavi'oh"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.mavioh.mondomaine.fr
APP_TIMEZONE=UTC
FRONTEND_URL=https://mavioh.mondomaine.fr

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=mavioh
DB_USERNAME=mavioh
DB_PASSWORD=un-mot-de-passe-solide

LOG_CHANNEL=daily
SANCTUM_EXPIRATION=43200

OFF_BASE_URL=https://world.openfoodfacts.org
OFF_USER_AGENT="Mavioh/1.0 (ton-email@exemple.fr)"

# Coach sportif IA : laisser vide désactive l'IA, les séances restent générées par les règles.
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-opus-5

MAIL_MAILER=log
```

Puis la base et les caches :

```bash
php artisan migrate --force --seed
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data /var/www/mavioh/backend/storage /var/www/mavioh/backend/bootstrap/cache
```

`--seed` charge le catalogue d'exercices et de sports, indispensable au module sport. Il crée aussi
le compte de démonstration `demo@mavioh.app` / `Demo1234!` : supprime-le après tes essais avec
`php artisan tinker --execute="App\Models\User::where('email','demo@mavioh.app')->delete();"`.

### Tâche planifiée

Le planificateur purge les jetons expirés. Ajoute-le au cron de `www-data` :

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/mavioh/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5. Configuration du site Next.js

```bash
cd /var/www/mavioh/web
cp .env.example .env.local
```

Édite `.env.local` :

```dotenv
API_URL=https://api.mavioh.mondomaine.fr/api
NEXT_PUBLIC_API_URL=https://api.mavioh.mondomaine.fr/api
```

Puis :

```bash
npm ci
npm run build
```

Le service systemd `scripts/deploy/mavioh-web.service.example` lance `npm start` sur le port 3000 :

```bash
sudo cp scripts/deploy/mavioh-web.service.example /etc/systemd/system/mavioh-web.service
sudo systemctl daemon-reload
sudo systemctl enable --now mavioh-web
sudo systemctl status mavioh-web
```

---

## 6. Nginx et HTTPS

```bash
sudo cp scripts/deploy/nginx.conf.example /etc/nginx/sites-available/mavioh
sudo ln -sf /etc/nginx/sites-available/mavioh /etc/nginx/sites-enabled/mavioh
sudo rm -f /etc/nginx/sites-enabled/default
```

Remplace les deux noms de domaine dans le fichier, teste et recharge :

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d mavioh.mondomaine.fr -d api.mavioh.mondomaine.fr
```

Certbot ajoute les certificats et la redirection HTTP vers HTTPS. Le renouvellement est automatique.

### Pare-feu

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

PostgreSQL et les ports 8000/3000 ne doivent jamais être exposés publiquement.

---

## 7. Mises à jour

Depuis le serveur, après un `git push` :

```bash
cd /var/www/mavioh
sudo bash scripts/deploy/update.sh
```

Le script fait `git pull`, réinstalle les dépendances, applique les migrations, reconstruit le site
et redémarre les services. Les migrations sont additives : aucune donnée n'est perdue.

---

## 8. Sauvegardes

```bash
sudo -u postgres pg_dump mavioh | gzip > /var/backups/mavioh-$(date +%F).sql.gz
```

Ajoute cette ligne au cron root pour une sauvegarde quotidienne, et pense à copier l'archive hors du
serveur. Restauration :

```bash
gunzip -c /var/backups/mavioh-2026-09-16.sql.gz | sudo -u postgres psql mavioh
```

---

## 9. Application mobile Flutter

L'APK se construit sur ta machine de développement, pas sur le serveur :

```bash
cd mobile
flutter build apk --release --dart-define=API_BASE_URL=https://api.mavioh.mondomaine.fr/api
```

Le fichier obtenu est `mobile/build/app/outputs/flutter-apk/app-release.apk`. Pour un test en
réseau local avec le serveur de développement, remplace l'URL par `http://192.168.x.x:8000/api` et
lance le backend avec `php artisan serve --host 0.0.0.0`.

Pour une diffusion publique sur le Play Store, il faut au préalable créer une clé de signature et
remplacer la configuration de signature de debug dans `mobile/android/app/build.gradle.kts`.

---

## 10. Vérifications après déploiement

```bash
curl -s https://api.mavioh.mondomaine.fr/api/portions | head -c 200
curl -s -X POST https://api.mavioh.mondomaine.fr/api/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"demo@mavioh.app","password":"Demo1234!"}' | head -c 200
```

La première commande doit renvoyer le catalogue des portions, la seconde un jeton. Ouvre ensuite
`https://mavioh.mondomaine.fr`, connecte-toi et vérifie le tableau de bord, le stock et le sport.

### En cas de problème

| Symptôme | Piste |
|---|---|
| 500 sur toutes les routes API | `tail -50 backend/storage/logs/laravel-*.log`, droits sur `storage/` |
| 502 depuis Nginx | PHP-FPM ou le service `mavioh-web` est arrêté (`systemctl status`) |
| Le site web ne joint pas l'API | `API_URL` dans `web/.env.local`, puis `npm run build` et redémarrage du service |
| Le téléphone ne joint pas l'API | HTTPS valide et `API_BASE_URL` passé au build Flutter |
| Séances IA absentes | `ANTHROPIC_API_KEY` vide : c'est le comportement prévu, les règles prennent le relais |
