#!/usr/bin/env bash
# Installe les prérequis de Mavi'oh sur Ubuntu 22.04 / 24.04.
# Idempotent : relançable sans risque.
#
#   sudo bash scripts/deploy/setup-ubuntu.sh
#
# Le script ne s'interrompt jamais sur un paquet facultatif : il installe ce qu'il peut,
# puis dresse en fin de course la liste de ce qui manque vraiment.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "[mavioh] Ce script doit être lancé avec sudo." >&2
  exit 1
fi

log() { echo -e "\n[mavioh] $*"; }
warn() { echo -e "[mavioh] /!\\ $*" >&2; }

PHP_VERSION="${PHP_VERSION:-8.3}"
MISSING=()

log "Mise à jour de la liste des paquets"
apt-get update -y

log "Paquets de base"
apt-get install -y ca-certificates curl gnupg lsb-release software-properties-common unzip git ufw

# --- PHP --------------------------------------------------------------------
# Ubuntu 22.04 ne fournit que PHP 8.1 ; le dépôt ondrej apporte les versions récentes.
if ! command -v "php${PHP_VERSION}" >/dev/null 2>&1; then
  log "Dépôt PHP (ondrej/php)"
  if add-apt-repository -y ppa:ondrej/php; then
    apt-get update -y
  else
    warn "Dépôt ondrej/php inaccessible : on se contentera du PHP de la distribution."
  fi
fi

# Extensions réellement nécessaires à Mavi'oh (voir backend/composer.json).
PHP_REQUIRED=(cli fpm pgsql sqlite3 mbstring xml curl zip intl bcmath)
# gd n'est utilisé par aucune fonctionnalité : les photos de recettes sont stockées telles
# quelles. Sur Ubuntu 22.04, php8.3-gd réclame un libgd3 plus récent que celui de la
# distribution : on tente, et on continue sans lui en cas d'échec.
PHP_OPTIONAL=(gd)

log "PHP ${PHP_VERSION} et extensions"
PHP_PACKAGES=()
for ext in "${PHP_REQUIRED[@]}"; do PHP_PACKAGES+=("php${PHP_VERSION}-${ext}"); done

if ! apt-get install -y "${PHP_PACKAGES[@]}"; then
  warn "PHP ${PHP_VERSION} indisponible sur cette version d'Ubuntu."
  if php -v >/dev/null 2>&1; then
    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    warn "Repli sur le PHP déjà installé (${PHP_VERSION}). Mavi'oh exige PHP 8.1 ou plus."
    PHP_PACKAGES=()
    for ext in "${PHP_REQUIRED[@]}"; do PHP_PACKAGES+=("php${PHP_VERSION}-${ext}"); done
    apt-get install -y "${PHP_PACKAGES[@]}" || MISSING+=("extensions PHP")
  else
    MISSING+=("PHP")
  fi
fi

for ext in "${PHP_OPTIONAL[@]}"; do
  if ! apt-get install -y "php${PHP_VERSION}-${ext}" >/dev/null 2>&1; then
    warn "php${PHP_VERSION}-${ext} non installé (facultatif, aucune fonctionnalité de Mavi'oh ne l'utilise)."
  fi
done

update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >/dev/null 2>&1 || true

# --- Composer ---------------------------------------------------------------
# Composer 2.2 (paquet Ubuntu 22.04) fonctionne, mais 2.5+ évite des surprises de plugins.
COMPOSER_OK="false"
if command -v composer >/dev/null 2>&1; then
  COMPOSER_MAJOR_MINOR="$(composer --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+' | head -1)"
  if [[ -n "$COMPOSER_MAJOR_MINOR" ]] && awk "BEGIN{exit !($COMPOSER_MAJOR_MINOR >= 2.5)}"; then
    COMPOSER_OK="true"
    log "Composer déjà à jour ($(composer --version 2>/dev/null | head -1))"
  else
    log "Composer présent mais ancien ($COMPOSER_MAJOR_MINOR) : installation d'une version récente"
  fi
fi

if [[ "$COMPOSER_OK" != "true" ]]; then
  if curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null; then
    log "Composer installé dans /usr/local/bin"
  else
    warn "Installation de Composer impossible."
    command -v composer >/dev/null 2>&1 || MISSING+=("Composer")
  fi
  rm -f /tmp/composer-setup.php
fi

# --- PostgreSQL -------------------------------------------------------------
log "PostgreSQL"
apt-get install -y postgresql postgresql-contrib || MISSING+=("PostgreSQL")
systemctl enable --now postgresql || true

# --- Node.js LTS ------------------------------------------------------------
if ! node -v 2>/dev/null | grep -qE "^v(20|22|24)\."; then
  log "Node.js LTS (NodeSource)"
  if curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -; then
    apt-get install -y nodejs || MISSING+=("Node.js")
  else
    MISSING+=("Node.js")
  fi
else
  log "Node.js déjà présent ($(node -v))"
fi

# --- Nginx + Certbot --------------------------------------------------------
log "Nginx et Certbot"
apt-get install -y nginx certbot python3-certbot-nginx || MISSING+=("Nginx")
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true

# --- Bilan ------------------------------------------------------------------
log "Versions installées"
php -v 2>/dev/null | head -1 || echo "php : absent"
composer --version 2>/dev/null | head -1 || echo "composer : absent"
node -v 2>/dev/null || echo "node : absent"
npm -v 2>/dev/null || echo "npm : absent"
psql --version 2>/dev/null || echo "psql : absent"
nginx -v 2>&1 || echo "nginx : absent"

log "Extensions PHP chargées"
for ext in pdo_pgsql pdo_sqlite mbstring xml curl zip intl bcmath; do
  if php -m 2>/dev/null | grep -qix "$ext"; then
    echo "  ok       $ext"
  else
    echo "  MANQUANT $ext"
    MISSING+=("extension PHP $ext")
  fi
done

if [[ ${#MISSING[@]} -gt 0 ]]; then
  echo
  warn "Éléments manquants : ${MISSING[*]}"
  warn "Corrige-les avant de poursuivre le déploiement."
  exit 1
fi

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
