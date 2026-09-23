#!/usr/bin/env bash
# Installe Mavi'oh de bout en bout sur un serveur Ubuntu déjà préparé par setup-ubuntu.sh.
# Idempotent : relançable sans risque.
#
#   sudo bash scripts/deploy/install.sh
#
# Variables acceptées (toutes facultatives) :
#   DB_PASSWORD   mot de passe PostgreSQL ; généré et affiché s'il n'est pas fourni
#   WEB_DOMAIN    domaine du site   (ex. mavioh.exemple.fr) ; sinon l'IP du serveur en HTTP
#   API_DOMAIN    domaine de l'API  (ex. api.mavioh.exemple.fr)
#   FRESH=1       vide la base et rejoue toutes les migrations (DONNÉES PERDUES)
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "[mavioh] Ce script doit être lancé avec sudo." >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEB_DOMAIN="${WEB_DOMAIN:-}"
API_DOMAIN="${API_DOMAIN:-}"
FRESH="${FRESH:-0}"
RUN_USER="${SUDO_USER:-root}"

log() { echo -e "\n[mavioh] $*"; }
warn() { echo -e "[mavioh] /!\\ $*" >&2; }
die() { echo -e "[mavioh] ERREUR : $*" >&2; exit 1; }

as_user() { sudo -u "$RUN_USER" -H "$@"; }

# --- Vérifications préalables -----------------------------------------------
for cmd in php composer node npm psql nginx systemctl python3 curl; do
  command -v "$cmd" >/dev/null 2>&1 || die "$cmd est absent. Lance d'abord scripts/deploy/setup-ubuntu.sh."
done
[[ -f "$ROOT_DIR/backend/artisan" ]] || die "backend/artisan introuvable dans $ROOT_DIR."
[[ -f "$ROOT_DIR/web/package-lock.json" ]] || die "web/package-lock.json introuvable dans $ROOT_DIR."

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
[[ -S "$FPM_SOCKET" ]] || warn "Socket PHP-FPM $FPM_SOCKET absent ; il apparaîtra au démarrage du service."

if [[ -n "$WEB_DOMAIN" ]]; then
  API_DOMAIN="${API_DOMAIN:-$WEB_DOMAIN}"
  APP_URL="https://${API_DOMAIN}"
  FRONT_URL="https://${WEB_DOMAIN}"
  API_BASE="https://${API_DOMAIN}/api"
else
  PUBLIC_IP="$(hostname -I | awk '{print $1}')"
  [[ -n "$PUBLIC_IP" ]] || die "Impossible de déterminer l'adresse IP du serveur."
  APP_URL="http://${PUBLIC_IP}"
  FRONT_URL="http://${PUBLIC_IP}"
  API_BASE="http://${PUBLIC_IP}/api"
  log "Aucun domaine fourni : le site sera servi en HTTP sur ${PUBLIC_IP}, l'API sous /api."
fi

if [[ -z "${DB_PASSWORD:-}" ]]; then
  DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"
  GENERATED_PASSWORD="oui"
fi

# --- 1. Base de données -----------------------------------------------------
log "PostgreSQL : rôle et base"
if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='mavioh'" | grep -q 1; then
  sudo -u postgres psql -q -c "ALTER USER mavioh WITH PASSWORD '${DB_PASSWORD}';"
else
  sudo -u postgres psql -q -c "CREATE USER mavioh WITH PASSWORD '${DB_PASSWORD}';"
fi
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='mavioh'" | grep -q 1; then
  sudo -u postgres psql -q -c "CREATE DATABASE mavioh OWNER mavioh;"
fi
sudo -u postgres psql -q -c "ALTER DATABASE mavioh OWNER TO mavioh;"

# --- 2. Backend Laravel -----------------------------------------------------
log "Backend : fichier .env"
cd "$ROOT_DIR/backend"
[[ -f .env ]] || cp .env.example .env
python3 - "$APP_URL" "$FRONT_URL" "$DB_PASSWORD" <<'PY'
import re, sys

app_url, front_url, db_password = sys.argv[1:4]
values = {
    'APP_ENV': 'production', 'APP_DEBUG': 'false', 'LOG_LEVEL': 'warning',
    'APP_URL': app_url, 'FRONTEND_URL': front_url,
    'DB_CONNECTION': 'pgsql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '5432',
    'DB_DATABASE': 'mavioh', 'DB_USERNAME': 'mavioh', 'DB_PASSWORD': db_password,
}
out, seen = [], set()
for line in open('.env', encoding='utf-8').read().splitlines():
    if line.startswith('#') and 'SQLite' in line:
        continue                           # commentaire devenu faux : on passe en PostgreSQL
    match = re.match(r'^#?\s*([A-Z0-9_]+)=', line)
    key = match.group(1) if match else None
    if key in values:
        if key in seen:
            continue                       # variante commentée en double : supprimée
        out.append('{}={}'.format(key, values[key]))
        seen.add(key)
    else:
        out.append(line)
for key, value in values.items():
    if key not in seen:
        out.append('{}={}'.format(key, value))
open('.env', 'w', encoding='utf-8').write('\n'.join(out) + '\n')
PY

log "Backend : dépendances"
as_user composer install --no-dev --optimize-autoloader --no-interaction

log "Backend : clé d'application"
php artisan key:generate --force

log "Backend : migrations et catalogue"
if [[ "$FRESH" == "1" ]]; then
  warn "FRESH=1 : la base est vidée."
  php artisan migrate:fresh --force --seed
else
  php artisan migrate --force --seed
fi

log "Backend : caches et droits"
php artisan storage:link >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data "$ROOT_DIR/backend/storage" "$ROOT_DIR/backend/bootstrap/cache"

# --- 3. Site Next.js --------------------------------------------------------
log "Web : configuration et construction"
cd "$ROOT_DIR/web"
printf 'API_URL=%s\nNEXT_PUBLIC_API_URL=%s\n' "$API_BASE" "$API_BASE" > .env.local
as_user npm ci
as_user npm run build
chown -R www-data:www-data "$ROOT_DIR/web/.next"

# --- 4. Service systemd -----------------------------------------------------
log "Service mavioh-web"
sed "s#^WorkingDirectory=.*#WorkingDirectory=${ROOT_DIR}/web#" \
  "$ROOT_DIR/scripts/deploy/mavioh-web.service.example" > /etc/systemd/system/mavioh-web.service
systemctl daemon-reload
systemctl enable mavioh-web >/dev/null 2>&1 || true
systemctl restart mavioh-web

# --- 5. Nginx ---------------------------------------------------------------
log "Nginx"
if [[ -n "$WEB_DOMAIN" ]]; then
  sed -e "s#api\.mavioh\.mondomaine\.fr#${API_DOMAIN}#g" \
      -e "s#mavioh\.mondomaine\.fr#${WEB_DOMAIN}#g" \
      -e "s#/var/www/mavioh#${ROOT_DIR}#g" \
      -e "s#php8\.3-fpm\.sock#php${PHP_VERSION}-fpm.sock#g" \
      "$ROOT_DIR/scripts/deploy/nginx.conf.example" > /etc/nginx/sites-available/mavioh
else
  sed -e "s#/var/www/mavioh#${ROOT_DIR}#g" \
      -e "s#php8\.3-fpm\.sock#php${PHP_VERSION}-fpm.sock#g" \
      "$ROOT_DIR/scripts/deploy/nginx-ip.conf.example" > /etc/nginx/sites-available/mavioh
fi
ln -sf /etc/nginx/sites-available/mavioh /etc/nginx/sites-enabled/mavioh
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
systemctl restart "php${PHP_VERSION}-fpm" || true

# --- 6. Tâche planifiée -----------------------------------------------------
log "Tâche planifiée (purge des jetons expirés)"
CRON_LINE="* * * * * cd ${ROOT_DIR}/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1"
{ crontab -u www-data -l 2>/dev/null | grep -v 'artisan schedule:run' || true; echo "$CRON_LINE"; } \
  | crontab -u www-data -

# --- 7. Vérifications -------------------------------------------------------
log "Vérifications"
sleep 3
API_CHECK="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/api/portions || echo 000)"
WEB_CHECK="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/ || echo 000)"
echo "  API  /api/portions -> ${API_CHECK}"
echo "  Site /             -> ${WEB_CHECK}"

echo
if [[ "$API_CHECK" == "200" && "$WEB_CHECK" == "200" ]]; then
  echo "[mavioh] Installation terminée. Ouvre ${FRONT_URL}"
else
  warn "Une des deux vérifications a échoué. Pistes :"
  echo "  journalctl -u mavioh-web -n 40 --no-pager"
  echo "  tail -40 ${ROOT_DIR}/backend/storage/logs/laravel-*.log"
  echo "  tail -40 /var/log/nginx/mavioh*.error.log"
fi

echo "  Compte de démonstration : demo@mavioh.app / Demo1234!"
if [[ "${GENERATED_PASSWORD:-}" == "oui" ]]; then
  echo "  Mot de passe PostgreSQL généré : ${DB_PASSWORD}"
  echo "  (déjà inscrit dans backend/.env ; note-le pour tes sauvegardes)"
fi
if [[ -n "$WEB_DOMAIN" ]]; then
  echo "  HTTPS : sudo certbot --nginx -d ${WEB_DOMAIN} -d ${API_DOMAIN}"
fi
