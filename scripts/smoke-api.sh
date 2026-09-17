#!/usr/bin/env bash
# Vérification rapide de l'API Mavi'oh avec le compte de démonstration.
#
#   ./scripts/smoke-api.sh                             # http://127.0.0.1:8000
#   API=https://api.mondomaine.fr ./scripts/smoke-api.sh
#
# Prérequis : le backend tourne et `php artisan migrate --seed` a été exécuté.
set -uo pipefail

API="${API:-http://127.0.0.1:8000}"
EMAIL="${EMAIL:-demo@mavioh.app}"
PASSWORD="${PASSWORD:-Demo1234!}"

ok=0
ko=0

check() {
  local label="$1" method="$2" path="$3" expected="$4" auth="${5:-yes}"
  local args=(-s -o /tmp/mavioh-smoke.json -w '%{http_code}' -X "$method" -H 'Accept: application/json')
  [[ "$auth" == "yes" && -n "${TOKEN:-}" ]] && args+=(-H "Authorization: Bearer $TOKEN")
  local code
  code="$(curl "${args[@]}" "${API}${path}")"
  if [[ "$code" == "$expected" ]]; then
    printf '  \033[32mOK\033[0m   %-38s %s %s\n' "$label" "$method" "$path"
    ok=$((ok + 1))
  else
    printf '  \033[31mKO\033[0m   %-38s %s %s → HTTP %s (attendu %s)\n' "$label" "$method" "$path" "$code" "$expected"
    echo "       $(head -c 200 /tmp/mavioh-smoke.json)"
    ko=$((ko + 1))
  fi
}

echo "[smoke] API : $API"

echo
echo "Routes publiques"
check "catalogue des portions" GET /api/portions 200 no
check "catalogue des régimes"  GET /api/diets    200 no
check "catalogue des exercices" GET /api/sport/exercises 200 no
check "route protégée sans jeton" GET /api/me 401 no

echo
echo "Connexion"
TOKEN="$(curl -s -X POST "${API}/api/login" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"email\":\"${EMAIL}\",\"password\":\"${PASSWORD}\"}" \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')"

if [[ -z "$TOKEN" ]]; then
  echo "  KO   connexion impossible avec ${EMAIL} — le seeder de démonstration a-t-il été lancé ?"
  exit 1
fi
printf '  \033[32mOK\033[0m   connexion (%s)\n' "${TOKEN:0:12}…"
ok=$((ok + 1))

echo
echo "Routes authentifiées"
check "profil utilisateur"        GET /api/me              200
check "profil nutritionnel"       GET /api/profile         200
check "tableau de bord"           GET /api/dashboard       200
check "repas du jour"             GET /api/meals           200
check "historique"                GET /api/history         200
check "stock"                     GET /api/stocks          200
check "alertes de péremption"     GET /api/stocks/alerts   200
check "recettes"                  GET /api/recipes         200
check "recommandations du coach"  GET /api/recommendations 200
check "évaluation du régime"      GET /api/diets/evaluate  200
check "liste de courses"          GET /api/shopping-list   200
check "planificateur"             GET /api/planner         200
check "foyer"                     GET /api/household       200
check "paramètres"                GET /api/settings        200
check "notifications"             GET /api/notifications   200
check "configuration sport"       GET /api/sport/config    200
check "résumé sport"              GET /api/sport/summary   200
check "calendrier sport"          GET /api/sport/calendar  200
check "séances"                   GET /api/sport/sessions  200
check "alias /api/v1"             GET /api/v1/me           200

echo
echo "[smoke] ${ok} succès, ${ko} échec(s)"
[[ "$ko" -eq 0 ]] || exit 1
