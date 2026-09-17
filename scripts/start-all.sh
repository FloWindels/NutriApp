#!/usr/bin/env bash
# Lance l'API Laravel et le site Next.js de Mavi'oh (Linux / macOS).
#   ./scripts/start-all.sh          puis Ctrl+C pour tout arrêter
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_PORT="${BACKEND_PORT:-8000}"
WEB_PORT="${WEB_PORT:-3000}"

cleanup() {
  echo
  echo "[mavioh] Arrêt des processus…"
  [[ -n "${BACKEND_PID:-}" ]] && kill "$BACKEND_PID" 2>/dev/null || true
  [[ -n "${WEB_PID:-}" ]] && kill "$WEB_PID" 2>/dev/null || true
}
trap cleanup INT TERM EXIT

BACKEND_PORT="$BACKEND_PORT" "$ROOT_DIR/scripts/start-backend.sh" &
BACKEND_PID=$!

WEB_PORT="$WEB_PORT" "$ROOT_DIR/scripts/start-web.sh" &
WEB_PID=$!

echo
echo "[mavioh] API      : http://localhost:${BACKEND_PORT}/api"
echo "[mavioh] Site web : http://localhost:${WEB_PORT}"
echo "[mavioh] Mobile   : ./scripts/start-mobile.sh"
echo "[mavioh] Compte de démonstration : demo@mavioh.app / Demo1234!"
echo "[mavioh] Ctrl+C pour tout arrêter."

wait -n "$BACKEND_PID" "$WEB_PID"
