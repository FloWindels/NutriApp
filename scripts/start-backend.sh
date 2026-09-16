#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_HOST="${BACKEND_HOST:-0.0.0.0}"
BACKEND_PORT="${BACKEND_PORT:-8000}"

export PATH="$PATH:/snap/bin"

cd "$ROOT_DIR/backend"
echo "[backend] Starting Laravel API on http://${BACKEND_HOST}:${BACKEND_PORT}"
php artisan serve --host "$BACKEND_HOST" --port "$BACKEND_PORT"