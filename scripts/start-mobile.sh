#!/usr/bin/env bash
# Lance l'application Flutter Mavi'oh (Linux / macOS).
#   ./scripts/start-mobile.sh                       -> appareil Android, API sur l'IP LAN
#   DEVICE=chrome ./scripts/start-mobile.sh         -> navigateur, API sur 127.0.0.1
#   API_BASE_URL=https://api.exemple.fr/api ./scripts/start-mobile.sh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_PORT="${BACKEND_PORT:-8000}"
DEVICE="${DEVICE:-}"

if [[ -z "${API_BASE_URL:-}" ]]; then
  case "$DEVICE" in
    chrome|edge|linux|macos)
      API_BASE_URL="http://127.0.0.1:${BACKEND_PORT}/api"
      ;;
    *)
      LAN_IP="$(ip route get 8.8.8.8 2>/dev/null | grep -oP 'src \K\S+' || true)"
      [[ -z "$LAN_IP" ]] && LAN_IP="$(ipconfig getifaddr en0 2>/dev/null || echo 127.0.0.1)"
      API_BASE_URL="http://${LAN_IP}:${BACKEND_PORT}/api"
      ;;
  esac
fi

cd "$ROOT_DIR/mobile"
echo "[mobile] API_BASE_URL=${API_BASE_URL}"
echo "[mobile] Le backend doit ecouter sur 0.0.0.0 (./scripts/start-backend.sh)"

if [[ -n "$DEVICE" ]]; then
  exec flutter run -d "$DEVICE" --dart-define=API_BASE_URL="$API_BASE_URL"
fi
exec flutter run --dart-define=API_BASE_URL="$API_BASE_URL"
