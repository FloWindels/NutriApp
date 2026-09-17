#!/usr/bin/env bash
# Installe les prérequis de Mavi'oh sur Ubuntu 22.04 / 24.04.
# Idempotent : relançable sans risque.
#
#   sudo bash scripts/deploy/setup-ubuntu.sh
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "[mavioh] Ce script doit être lancé avec sudo." >&2
  exit 1
fi

log() { echo -e "\n[mavioh] $*"; }

log "Mise à jour de la liste des paquets"
apt-get update -y

log "Paquets de base"
apt-get install -y ca-certificates curl gnupg lsb-release software-properties-common unzip git ufw

# --- PHP 8.3 ----------------------------------------------------------------
if ! php -v 2>/dev/null | grep -q "PHP 8.3"; then
  log "Dépôt PHP (ondrej/php)"
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi

log "PHP 8.3 et extensions"
apt-get install -y \
  php8.3-cli php8.3-fpm php8.3-pgsql php8.3-sqlite3 php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath php8.3-gd

update-alternatives --set php /usr/bin/php8.3 >/dev/null 2>&1 || true

# --- Composer ---------------------------------------------------------------
if ! command -v composer >/dev/null 2>&1; then
  log "Composer"
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
else
  log "Composer déjà présent ($(composer --version 2>/dev/null | head -1))"
fi

# --- PostgreSQL -------------------------------------------------------------
log "PostgreSQL"
apt-get install -y postgresql postgresql-contrib
systemctl enable --now postgresql

# --- Node.js LTS ------------------------------------------------------------
if ! node -v 2>/dev/null | grep -qE "^v(20|22|24)\."; then
  log "Node.js LTS (NodeSource)"
  curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -
  apt-get install -y nodejs
else
  log "Node.js déjà présent ($(node -v))"
fi

# --- Nginx + Certbot --------------------------------------------------------
log "Nginx et Certbot"
apt-get install -y nginx certbot python3-certbot-nginx
systemctl enable --now nginx php8.3-fpm

log "Versions installées"
php -v | head -1
composer --version | head -1
node -v
npm -v
psql --version
nginx -v 2>&1

cat <<'TXT'

[mavioh] Prérequis installés.

Étapes suivantes (voir docs/DEPLOIEMENT_UBUNTU.md) :
  1. Créer la base :   sudo -u postgres psql -c "CREATE USER mavioh WITH PASSWORD '...';"
                       sudo -u postgres psql -c "CREATE DATABASE mavioh OWNER mavioh;"
  2. Backend :         cd backend && cp .env.example .env && composer install --no-dev -o
                       php artisan key:generate && php artisan migrate --force --seed
  3. Web :             cd web && cp .env.example .env.local && npm ci && npm run build
  4. Services :        scripts/deploy/mavioh-web.service.example et scripts/deploy/nginx.conf.example
TXT
