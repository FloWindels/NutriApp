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

Il installe : PHP 8.3 (cli, fpm, pgsql, sqlite3, mbstring, xml, curl, zip, intl, bcmath),
Composer, PostgreSQL, Node.js LTS, Nginx et Certbot.

Le script ne s'arrête jamais sur un paquet facultatif : il installe tout ce qu'il peut, puis
affiche en fin de course la liste de ce qui manque réellement. Deux points méritent une
explication :

- **`php8.3-gd` peut refuser de s'installer sur Ubuntu 22.04** : il réclame un `libgd3` plus
  récent que celui de la distribution. C'est sans conséquence, aucune fonctionnalité de Mavi'oh
  ne l'utilise, les photos de recettes étant stockées telles quelles. Le script affiche un
  avertissement et poursuit.
- **Si PHP 8.3 est introuvable**, le script se rabat sur le PHP de la distribution (8.1 sur
  Ubuntu 22.04), qui satisfait l'exigence `php ^8.1` du backend.

Une autre version peut être imposée :
`sudo PHP_VERSION=8.2 bash scripts/deploy/setup-ubuntu.sh`.

Tout ce guide suppose que le dépôt se trouve dans `/var/www/mavioh`. Un clone fait ailleurs — dans
ton dossier personnel par exemple — se déplace sans dommage, et c'est préférable : sur Ubuntu,
`/home/<utilisateur>` est en 750, donc `www-data` ne peut pas y lire les fichiers servis par Nginx.

```bash
sudo mv ~/Documents/NutriApp /var/www/mavioh
sudo chown -R "$USER":www-data /var/www/mavioh
cd /var/www/mavioh
```

Vérifie ensuite :

```bash
php -v && composer --version && node -v && psql --version && nginx -v
```

---

## 2 bis. Installation en une commande

Les sections 3 à 6 détaillent chaque étape. Si tu veux simplement que tout soit installé, le script
`scripts/deploy/install.sh` les enchaîne : base PostgreSQL, `.env` de production, dépendances,
migrations et catalogue, construction du site, service systemd, Nginx, tâche planifiée, puis
vérification que l'API et le site répondent. Il est idempotent.

```bash
cd /var/www/mavioh
sudo bash scripts/deploy/install.sh
```

Sans variable, il sert l'application en HTTP sur l'adresse IP du serveur et génère un mot de passe
PostgreSQL qu'il affiche en fin d'exécution. Avec un domaine :

```bash
sudo WEB_DOMAIN=mavioh.exemple.fr API_DOMAIN=api.mavioh.exemple.fr bash scripts/deploy/install.sh
```

Il reste alors à demander les certificats : `sudo certbot --nginx -d mavioh.exemple.fr -d api.mavioh.exemple.fr`.

Autres variables : `DB_PASSWORD` pour imposer le mot de passe, `FRESH=1` pour vider la base et
rejouer toutes les migrations — **toutes les données sont perdues**.

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

# Coach sportif IA : laisser tout vide désactive l'IA, les séances restent générées par les règles.
LLM_PROVIDER=auto
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-opus-5

# Variante gratuite : modèle exécuté sur le serveur, aucune donnée ne sort de la machine.
# Prérequis : `curl -fsSL https://ollama.com/install.sh | sh` puis `ollama pull llama3.2`.
OLLAMA_ENABLED=false
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.2

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

### Sans nom de domaine

`mavioh.mondomaine.fr` est un exemple : remplace-le partout par un domaine que tu possèdes, dont
l'enregistrement `A` pointe vers l'IP publique du serveur. Sans cela, Certbot échoue avec
`NXDOMAIN` — Let's Encrypt vérifie le domaine depuis Internet avant d'émettre le certificat.

Tant qu'aucun domaine n'est prêt, sers l'application en HTTP sur l'adresse IP, site sur `/` et API
sous `/api` :

```bash
sudo cp scripts/deploy/nginx-ip.conf.example /etc/nginx/sites-available/mavioh
sudo ln -sf /etc/nginx/sites-available/mavioh /etc/nginx/sites-enabled/mavioh
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Adapte alors les URL : `APP_URL` et `FRONTEND_URL` dans `backend/.env`, `API_URL` et
`NEXT_PUBLIC_API_URL` dans `web/.env.local` (suivies de `npm run build`). Ce mode convient au
réseau local et aux essais, pas à une mise en ligne publique : les mots de passe et les jetons
circulent en clair.

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

## 7 bis. Coach sportif IA en local (facultatif)

Mavi'oh sait générer les séances avec un modèle exécuté sur ton propre serveur, sans clé d'API ni
coût par appel. Un petit modèle suffit : `llama3.2` répond en quelques secondes et respecte les
contraintes (matériel, focus, zones douloureuses).

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama pull llama3.2
sudo systemctl enable --now ollama
```

Puis, dans `backend/.env` :

```dotenv
OLLAMA_ENABLED=true
OLLAMA_MODEL=llama3.2
```

Enfin `php artisan config:cache` et un redémarrage de PHP-FPM.

Compte 4 Go de mémoire vive pour ce modèle. Sans GPU, la génération prend une dizaine de secondes ;
le module reste utilisable puisque l'interface affiche une progression et laisse annuler. En cas de
panne d'Ollama, les règles Mavi'oh prennent le relais automatiquement.

**Attention à la durée d'exécution PHP** : `max_execution_time` doit rester au-dessus du délai des
appels au modèle. Mavi'oh relève la limite lui-même pendant l'appel, mais si tu passes par un
proxy, vérifie aussi `fastcgi_read_timeout` côté Nginx (120 s dans la configuration fournie).

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
| `nginx : command not found` après l'installation | le script a été interrompu avant : relance `sudo bash scripts/deploy/setup-ubuntu.sh`, il est idempotent |
| `php8.3-gd` refuse de s'installer | normal sur Ubuntu 22.04, sans effet sur Mavi'oh : poursuis le déploiement |
| `Deprecation Notice: Using ${var}` pendant `composer install` | c'est le Composer du paquet Ubuntu (2.2.6) : installe l'officiel, `curl -sS https://getcomposer.org/installer \| sudo php -- --install-dir=/usr/local/bin --filename=composer` |
| `Could not open input file: artisan` | tu n'es pas dans `backend/` : `cd /var/www/mavioh/backend` |

---

## 11. Repartir de zéro

Pour reprendre une installation ratée sans traîner d'état intermédiaire. **Ces commandes effacent
les données** : ne les utilise que sur un serveur d'essai.

```bash
sudo systemctl disable --now mavioh-web 2>/dev/null; sudo rm -f /etc/systemd/system/mavioh-web.service
sudo systemctl daemon-reload
sudo rm -f /etc/nginx/sites-enabled/mavioh /etc/nginx/sites-available/mavioh
sudo systemctl reload nginx 2>/dev/null || true
sudo crontab -u www-data -l 2>/dev/null | grep -v 'artisan schedule:run' | sudo crontab -u www-data -
sudo -u postgres psql -c "DROP DATABASE IF EXISTS mavioh;"
sudo -u postgres psql -c "DROP ROLE IF EXISTS mavioh;"
sudo rm -rf /var/www/mavioh
```

Puis reprends à la section 2 : clone dans `/var/www/mavioh`, `setup-ubuntu.sh`, `install.sh`. Les
paquets système (PHP, PostgreSQL, Node, Nginx) restent installés, il n'y a pas lieu de les retirer.
