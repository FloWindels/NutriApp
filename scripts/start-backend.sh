#!/usr/bin/env bash
# Lance l'API Laravel de Mavi'oh (Linux / macOS).
#   BACKEND_PORT=8000 ./scripts/start-backend.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_HOST="${BACKEND_HOST:-0.0.0.0}"
BACKEND_PORT="${BACKEND_PORT:-8000}"

cd "$ROOT_DIR/backend"

if [[ ! -f .env ]]; then
  cp .env.example .env
  php artisan key:generate
fi

if grep -q '^DB_CONNECTION=sqlite' .env && [[ ! -f database/database.sqlite ]]; then
  touch database/database.sqlite
  php artisan migrate --force --seed
fi

echo "[backend] API Laravel sur http://${BACKEND_HOST}:${BACKEND_PORT}/api"
exec php artisan serve --host "$BACKEND_HOST" --port "$BACKEND_PORT"
