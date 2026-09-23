#!/usr/bin/env bash
# Met à jour Mavi'oh depuis GitHub sur le serveur Ubuntu.
#
#   cd /var/www/mavioh && sudo bash scripts/deploy/update.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEB_SERVICE="${WEB_SERVICE:-mavioh-web}"
PHP_FPM="${PHP_FPM:-php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.3)-fpm}"

log() { echo -e "\n[mavioh] $*"; }

cd "$ROOT_DIR"

log "Récupération du code (git pull)"
git pull --ff-only

# --- Backend ----------------------------------------------------------------
log "Backend : dépendances"
cd "$ROOT_DIR/backend"
composer install --no-dev --optimize-autoloader --no-interaction

log "Backend : migrations"
php artisan migrate --force

log "Backend : catalogue exercices et sports"
php artisan db:seed --class=Database\\Seeders\\ExerciseSeeder --force
php artisan db:seed --class=Database\\Seeders\\SportSeeder --force

log "Backend : caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

if id www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "$ROOT_DIR/backend/storage" "$ROOT_DIR/backend/bootstrap/cache"
fi

# --- Web --------------------------------------------------------------------
log "Web : dépendances et build"
cd "$ROOT_DIR/web"
npm ci
npm run build

# --- Services ---------------------------------------------------------------
log "Redémarrage des services"
systemctl restart "$PHP_FPM" || true
systemctl restart "$WEB_SERVICE" || true
systemctl reload nginx || true

log "Terminé. État des services :"
systemctl is-active "$PHP_FPM" "$WEB_SERVICE" nginx || true
