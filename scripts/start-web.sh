#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT_DIR/web"
echo "[web] Starting Next.js dev server on http://localhost:3000"
npm run dev
