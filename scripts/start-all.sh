#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_PORT="${BACKEND_PORT:-8000}"
BACKEND_HOST="${BACKEND_HOST:-0.0.0.0}"
FLUTTER_PORT="${FLUTTER_PORT:-8091}"
WEB_PORT="${WEB_PORT:-3000}"

export PATH="$PATH:/snap/bin"

free_port() {
  local port="$1"
  local pids=""
  local i

  if command -v lsof >/dev/null 2>&1; then
    pids="$(lsof -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null || true)"
  fi

  if [[ -z "$pids" ]] && command -v fuser >/dev/null 2>&1; then
    pids="$(fuser -n tcp "$port" 2>/dev/null || true)"
  fi

  if [[ -n "$pids" ]]; then
    echo "[dev] Releasing port ${port} (PIDs: $pids)"
    if command -v fuser >/dev/null 2>&1; then
      fuser -k -n tcp "$port" >/dev/null 2>&1 || true
    fi
    kill $pids >/dev/null 2>&1 || true
    sleep 1
  fi

  for i in 1 2 3; do
    if ! ss -ltn "sport = :$port" 2>/dev/null | grep -q LISTEN; then
      return
    fi
    pids="$(lsof -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null || true)"
    [[ -n "$pids" ]] && kill -9 $pids >/dev/null 2>&1 || true
    sleep 1
  done
}

cleanup() {
  echo
  echo "[dev] Stopping processes..."
  if [[ -n "${BACKEND_PID:-}" ]]; then kill "$BACKEND_PID" 2>/dev/null || true; fi
  if [[ -n "${WEB_PID:-}" ]]; then kill "$WEB_PID" 2>/dev/null || true; fi
  if [[ -n "${FLUTTER_PID:-}" ]]; then kill "$FLUTTER_PID" 2>/dev/null || true; fi
}

trap cleanup INT TERM EXIT

free_port "$WEB_PORT"
free_port "$BACKEND_PORT"
free_port "$FLUTTER_PORT"

(
  cd "$ROOT_DIR/backend"
  echo "[backend] http://${BACKEND_HOST}:${BACKEND_PORT}"
  php artisan serve --host "$BACKEND_HOST" --port "$BACKEND_PORT"
) &
BACKEND_PID=$!

(
  cd "$ROOT_DIR/web"
  echo "[web] http://localhost:${WEB_PORT}"
  npm run dev -- --port "$WEB_PORT"
) &
WEB_PID=$!

(
  cd "$ROOT_DIR/mobile"
  echo "[flutter] http://localhost:${FLUTTER_PORT}"
  flutter run -d web-server --web-port "$FLUTTER_PORT"
) &
FLUTTER_PID=$!

echo "[dev] Both apps started"
echo "[dev] Backend:  http://localhost:${BACKEND_PORT}"
echo "[dev] Web:     http://localhost:${WEB_PORT}"
echo "[dev] Flutter: http://localhost:${FLUTTER_PORT}"
echo "[dev] Press Ctrl+C to stop both"

wait -n "$BACKEND_PID" "$WEB_PID" "$FLUTTER_PID"
