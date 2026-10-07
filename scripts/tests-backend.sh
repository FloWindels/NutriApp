#!/usr/bin/env bash
# Mavi'oh — suite de tests du backend, sur SQLite ET sur PostgreSQL (la base de production).
#
#   ./scripts/tests-backend.sh            # les deux
#   ./scripts/tests-backend.sh pgsql      # PostgreSQL seulement
#
# SQLite tolère ce que PostgreSQL refuse (échappement LIKE, identifiants hors bornes, dates
# impossibles) : ne tester que sur SQLite a déjà laissé passer des erreurs 500 en production.
# Prérequis : l'image mavioh-api (docker compose build api) et Docker.
set -euo pipefail

RACINE="$(cd "$(dirname "$0")/.." && pwd)"
BASES="${1:-sqlite pgsql}"
RESEAU=mavioh-tests
PG=mavioh-pg-tests
COMPOSER="$(mktemp -d)/composer"

docker run --rm --entrypoint cat composer:2 /usr/bin/composer > "$COMPOSER"
chmod +x "$COMPOSER"

if [[ "$BASES" == *pgsql* ]]; then
  docker network inspect "$RESEAU" >/dev/null 2>&1 || docker network create "$RESEAU" >/dev/null
  docker rm -f "$PG" >/dev/null 2>&1 || true
  docker run -d --name "$PG" --network "$RESEAU" -e POSTGRES_DB=tests -e POSTGRES_USER=tests -e POSTGRES_PASSWORD=tests postgres:16-alpine >/dev/null
  trap 'docker rm -f "$PG" >/dev/null 2>&1 || true' EXIT
  until docker exec "$PG" pg_isready -U tests -q; do sleep 1; done
fi

for BASE in $BASES; do
  echo "== Tests sur $BASE"
  ENV=()
  if [[ "$BASE" == pgsql ]]; then
    ENV=(--network "$RESEAU" -e DB_CONNECTION=pgsql -e DB_HOST="$PG" -e DB_PORT=5432 -e DB_DATABASE=tests -e DB_USERNAME=tests -e DB_PASSWORD=tests)
  fi
  docker run --rm -u root "${ENV[@]}" \
    -v "$RACINE/backend":/src:ro -v "$COMPOSER":/usr/local/bin/composer:ro \
    --entrypoint sh mavioh-api -c 'cp -r /src /work && cd /work && cp .env.example .env \
      && composer install -q --no-interaction && php artisan key:generate -q \
      && php -d memory_limit=1G artisan test'
done
