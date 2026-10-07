#!/usr/bin/env bash
# Mavi'oh — compile, signe et publie l'application Android pour la mise à jour intégrée.
#
#   ./scripts/publier-apk.sh "Ce qui change dans cette version"
#
# La version vient de mobile/pubspec.yaml (`version: 1.2.0+3` → nom 1.2.0, code 3) : augmente
# le nombre après « + » à chaque publication, sinon les applications ne verront rien de neuf.
# Prérequis : la clé de signature (mobile/android/key.properties) et la pile Docker démarrée.
set -euo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
NOTES="${1:-}"
COMPOSE=(docker compose -f "$RACINE/docker/compose.yml")

VERSION="$(sed -n 's/^version:[[:space:]]*//p' "$RACINE/mobile/pubspec.yaml" | head -1)"
NOM="${VERSION%%+*}"
CODE="${VERSION##*+}"
[[ -n "$NOM" && "$CODE" =~ ^[0-9]+$ ]] || { echo "Version illisible dans pubspec.yaml : « $VERSION »" >&2; exit 1; }

PROPRIETES="$RACINE/mobile/android/key.properties"
[[ -f "$PROPRIETES" ]] || { echo "Clé de signature absente : $PROPRIETES" >&2; exit 1; }
CLE="$(sed -n 's/^storeFile=//p' "$PROPRIETES")"
DOSSIER_CLE="$(dirname "$CLE")"

echo "[apk] Compilation de Mavi'oh $NOM (code $CODE)…"
docker run --rm \
  -v "$RACINE/mobile":/app \
  -v "$DOSSIER_CLE":"$DOSSIER_CLE":ro \
  -v mavioh_flutter_cache:/root/.pub-cache \
  -v mavioh_gradle_cache:/root/.gradle \
  -w /app ghcr.io/cirruslabs/flutter:stable \
  sh -c "flutter build apk --release >/dev/null && chown -R $(id -u):$(id -g) /app/build /app/.dart_tool"

APK="$RACINE/mobile/build/app/outputs/flutter-apk/app-release.apk"
cp "$APK" "$HOME/Mavioh-$NOM.apk"

echo "[apk] Publication sur le serveur…"
"${COMPOSE[@]}" cp "$APK" api:/tmp/mavioh-a-publier.apk
"${COMPOSE[@]}" exec -T -u www-data api php artisan mavioh:publier-apk /tmp/mavioh-a-publier.apk \
  --code="$CODE" --nom="$NOM" --notes="$NOTES"
"${COMPOSE[@]}" exec -T api rm -f /tmp/mavioh-a-publier.apk

echo "[apk] Fait. Copie locale : $HOME/Mavioh-$NOM.apk"
echo "[apk] Les applications installées proposeront la mise à jour à leur prochaine ouverture."
