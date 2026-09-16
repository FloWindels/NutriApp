#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_PORT="${BACKEND_PORT:-8000}"
DEVICE_ID="${DEVICE_ID:-}"

export PATH="$PATH:/snap/bin"

LAN_IP="${LAN_IP:-$(ip route get 8.8.8.8 2>/dev/null | grep -oP 'src \K\S+' || true)}"

if [[ -z "$LAN_IP" ]]; then
  echo "[flutter-android] Impossible de détecter l'IP LAN."
  echo "[flutter-android] Relance avec: LAN_IP=192.168.x.x ./scripts/start-flutter-android.sh"
  exit 1
fi

API_BASE_URL="${API_BASE_URL:-http://${LAN_IP}:${BACKEND_PORT}/api}"

cd "$ROOT_DIR/mobile"
echo "[flutter-android] API_BASE_URL=${API_BASE_URL}"

if [[ -n "$DEVICE_ID" ]]; then
  flutter run -d "$DEVICE_ID" --dart-define=API_BASE_URL="$API_BASE_URL"
else
  flutter run -d android --dart-define=API_BASE_URL="$API_BASE_URL"
fi
