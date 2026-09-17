#!/usr/bin/env bash
# Lance le site Next.js de Mavi'oh (Linux / macOS).
#   WEB_PORT=3000 ./scripts/start-web.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_PORT="${WEB_PORT:-3000}"

cd "$ROOT_DIR/web"

[[ -f .env.local ]] || cp .env.example .env.local
[[ -d node_modules ]] || npm ci

echo "[web] Site sur http://localhost:${WEB_PORT}"
exec npm run dev -- --port "$WEB_PORT"
