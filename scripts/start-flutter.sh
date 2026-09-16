#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FLUTTER_PORT="${FLUTTER_PORT:-8091}"
API_BASE_URL="${API_BASE_URL:-http://127.0.0.1:8000/api}"

export PATH="$PATH:/snap/bin"

cd "$ROOT_DIR/mobile"
echo "[flutter] Starting Flutter web server on http://localhost:${FLUTTER_PORT}"
flutter run -d web-server --web-port "$FLUTTER_PORT" --dart-define=API_BASE_URL="$API_BASE_URL"
