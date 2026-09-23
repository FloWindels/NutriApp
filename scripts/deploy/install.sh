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
#   WEB_PORT      port interne du site Next   ; sinon le premier libre à partir de 3000
#   API_PORT      port public de l'API Laravel ; sinon le premier libre à partir de 8080
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

# Un port est disponible s'il n'est écouté par personne, ou seulement par le service de
# Mavi'oh lui-même — sans quoi une seconde exécution croirait ses propres ports occupés.
port_available() {                       # $1 = port, $2 = processus toléré (facultatif)
  command -v ss >/dev/null 2>&1 || return 0
  local owner
  owner="$(ss -ltnpH 2>/dev/null | awk -v p=":$1\$" '$4 ~ p {print $NF}' | head -1)"
  [[ -z "$owner" ]] && return 0
  [[ -n "${2:-}" && "$owner" == *"$2"* ]] && return 0
  return 1
}

pick_port() {                            # $1 = port imposé ou vide, $2 = processus toléré, $3.. = candidats
  local forced="$1" owned="$2"; shift 2
  if [[ -n "$forced" ]]; then
    port_available "$forced" "$owned" || die "Le port $forced est déjà utilisé par autre chose."
    echo "$forced"; return
  fi
  local candidate
  for candidate in "$@"; do
    if port_available "$candidate" "$owned"; then echo "$candidate"; return; fi
  done
  die "Aucun port libre parmi : $*. Fixe-le toi-même (WEB_PORT=… ou API_PORT=…)."
}

# --- Vérifications préalables -----------------------------------------------
for cmd in php composer node npm psql nginx systemctl python3 curl; do
  command -v "$cmd" >/dev/null 2>&1 || die "$cmd est absent. Lance d'abord scripts/deploy/setup-ubuntu.sh."
done
[[ -f "$ROOT_DIR/backend/artisan" ]] || die "backend/artisan introuvable dans $ROOT_DIR."
[[ -f "$ROOT_DIR/web/package-lock.json" ]] || die "web/package-lock.json introuvable dans $ROOT_DIR."

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
[[ -S "$FPM_SOCKET" ]] || warn "Socket PHP-FPM $FPM_SOCKET absent ; il apparaîtra au démarrage du service."

# Le site Next écoute en local ; seul Nginx est exposé. On libère le port avant de sonder,
# sinon le service en cours passerait pour un occupant étranger.
systemctl stop mavioh-web >/dev/null 2>&1 || true
WEB_PORT="$(pick_port "${WEB_PORT:-}" node 3000 3001 3100 4000 4100)"

# API_BASE   : adresse de Laravel utilisée côté serveur par les route handlers Next.
# MOBILE_API : adresse de Laravel joignable depuis un téléphone, pour le build Flutter.
# Le navigateur, lui, n'appelle jamais Laravel : il passe par /api/** du site Next.
if [[ -n "$WEB_DOMAIN" ]]; then
  API_DOMAIN="${API_DOMAIN:-$WEB_DOMAIN}"
  APP_URL="https://${API_DOMAIN}"
  FRONT_URL="https://${WEB_DOMAIN}"
  API_BASE="https://${API_DOMAIN}/api"
  MOBILE_API="$API_BASE"
  API_PORT=""
else
  PUBLIC_IP="$(hostname -I | awk '{print $1}')"
  [[ -n "$PUBLIC_IP" ]] || die "Impossible de déterminer l'adresse IP du serveur."
  API_PORT="$(pick_port "${API_PORT:-}" nginx 8080 8090 8880 9080 9090)"
  APP_URL="http://${PUBLIC_IP}:${API_PORT}"
  FRONT_URL="http://${PUBLIC_IP}"
  API_BASE="http://127.0.0.1:${API_PORT}/api"
  MOBILE_API="http://${PUBLIC_IP}:${API_PORT}/api"
  log "Aucun domaine fourni : site sur http://${PUBLIC_IP}, API Laravel sur le port ${API_PORT}."
fi
log "Ports retenus : site ${WEB_PORT} (interne)${API_PORT:+, API ${API_PORT} (public)}"

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
# Une exécution précédente a pu donner ces dossiers à www-data ; composer et artisan y écrivent
# sous le compte qui construit. Ils repasseront à www-data en fin de script.
chown -R "$RUN_USER" "$ROOT_DIR/backend/storage" "$ROOT_DIR/backend/bootstrap/cache" 2>/dev/null || true
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
printf 'API_URL=%s\nNEXT_PUBLIC_API_URL=%s\n' "$API_BASE" "$MOBILE_API" > .env.local
# Même raison que pour storage/ : .next appartient à www-data après une première installation.
[[ -d .next ]] && chown -R "$RUN_USER" .next 2>/dev/null || true
as_user npm ci
as_user npm run build
chown -R www-data:www-data "$ROOT_DIR/web/.next"

# --- 4. Service systemd -----------------------------------------------------
log "Service mavioh-web"
sed -e "s#^WorkingDirectory=.*#WorkingDirectory=${ROOT_DIR}/web#" \
    -e "s#^Environment=PORT=.*#Environment=PORT=${WEB_PORT}#" \
    -e "s#--port [0-9]\+#--port ${WEB_PORT}#" \
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
      -e "s#127\.0\.0\.1:3000#127.0.0.1:${WEB_PORT}#g" \
      "$ROOT_DIR/scripts/deploy/nginx.conf.example" > /etc/nginx/sites-available/mavioh
else
  sed -e "s#/var/www/mavioh#${ROOT_DIR}#g" \
      -e "s#php8\.3-fpm\.sock#php${PHP_VERSION}-fpm.sock#g" \
      -e "s#127\.0\.0\.1:3000#127.0.0.1:${WEB_PORT}#g" \
      -e "s#listen 8080;#listen ${API_PORT};#" \
      -e "s#listen \[::\]:8080;#listen [::]:${API_PORT};#" \
      "$ROOT_DIR/scripts/deploy/nginx-ip.conf.example" > /etc/nginx/sites-available/mavioh
fi
ln -sf /etc/nginx/sites-available/mavioh /etc/nginx/sites-enabled/mavioh
rm -f /etc/nginx/sites-enabled/default
# Un autre site peut déjà être le serveur par défaut : deux le seraient, Nginx refuserait.
for site in /etc/nginx/sites-enabled/*; do
  [[ -e "$site" && "$(basename "$site")" != "mavioh" ]] || continue
  if grep -q 'default_server' "$site"; then
    warn "$(basename "$site") est déjà le serveur Nginx par défaut ; Mavi'oh ne le sera pas."
    sed -i 's/ default_server//g' /etc/nginx/sites-available/mavioh
    break
  fi
done
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
if [[ -n "$API_PORT" ]]; then
  API_CHECK="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${API_PORT}/api/portions" || echo 000)"
else
  API_CHECK="$(curl -s -o /dev/null -w '%{http_code}' "${API_BASE}/portions" || echo 000)"
fi
# « / » redirige vers « /login » (307) : on suit la redirection avant de juger.
WEB_CHECK="$(curl -sL -o /dev/null -w '%{http_code}' http://127.0.0.1/ || echo 000)"
# Le relais du site vers Laravel : sans jeton, Laravel doit répondre 401 — preuve que la
# chaîne navigateur -> Next -> Laravel est complète.
BFF_CHECK="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/api/auth/me || echo 000)"
echo "  API Laravel   /api/portions  -> ${API_CHECK}   (attendu 200)"
echo "  Site          /  -> /login   -> ${WEB_CHECK}   (attendu 200)"
echo "  Relais du site /api/auth/me  -> ${BFF_CHECK}   (attendu 401)"

echo
if [[ "$API_CHECK" == "200" && "$WEB_CHECK" == "200" && "$BFF_CHECK" == "401" ]]; then
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
echo "  Site : ${FRONT_URL}"
echo "  APK  : flutter build apk --release --dart-define=API_BASE_URL=${MOBILE_API}"
if [[ -n "$WEB_DOMAIN" ]]; then
  echo "  HTTPS : sudo certbot --nginx -d ${WEB_DOMAIN} -d ${API_DOMAIN}"
else
  echo "  Le port ${API_PORT} ne sert qu'à l'application mobile ; laisse-le fermé si tu ne l'utilises pas."
fi
