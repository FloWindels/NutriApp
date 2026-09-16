#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/backend/.env"
BACKEND_PORT="${BACKEND_PORT:-8000}"
WEB_PORT="${WEB_PORT:-3000}"
FLUTTER_PORT="${FLUTTER_PORT:-8091}"

read_env_value() {
  local key="$1"
  local file="$2"

  if [[ ! -f "$file" ]]; then
    return 1
  fi

  local line
  line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
  if [[ -z "$line" ]]; then
    return 1
  fi

  line="${line#*=}"
  line="${line%\"}"
  line="${line#\"}"
  line="${line%\'}"
  line="${line#\'}"
  printf '%s\n' "$line"
}

DB_PORT="$(read_env_value DB_PORT "$ENV_FILE" || true)"
DB_PORT="${DB_PORT:-5432}"

run_with_privileges() {
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    "$@"
    return $?
  fi

  if command -v sudo >/dev/null 2>&1; then
    sudo "$@"
    return $?
  fi

  return 1
}

stop_port_processes() {
  local port="$1"
  local pids=""

  if command -v lsof >/dev/null 2>&1; then
    pids="$(lsof -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null || true)"
  fi

  if [[ -z "$pids" ]] && command -v fuser >/dev/null 2>&1; then
    pids="$(fuser -n tcp "$port" 2>/dev/null || true)"
  fi

  if [[ -z "$pids" ]]; then
    echo "[stop] Nothing listening on port ${port}"
    return 0
  fi

  echo "[stop] Stopping processes on port ${port} (PIDs: $pids)"
  kill $pids >/dev/null 2>&1 || true

  for _ in 1 2 3; do
    if ! ss -ltn "sport = :$port" 2>/dev/null | grep -q LISTEN; then
      echo "[stop] Port ${port} released"
      return 0
    fi
    sleep 1
  done

  if command -v fuser >/dev/null 2>&1; then
    fuser -k -n tcp "$port" >/dev/null 2>&1 || true
  fi
  kill -9 $pids >/dev/null 2>&1 || true

  if ! ss -ltn "sport = :$port" 2>/dev/null | grep -q LISTEN; then
    echo "[stop] Port ${port} released"
  else
    echo "[stop] Could not fully release port ${port}"
  fi
}

stop_db() {
  local stopped="false"

  if command -v pg_lsclusters >/dev/null 2>&1 && command -v pg_ctlcluster >/dev/null 2>&1; then
    local cluster_info
    cluster_info="$(pg_lsclusters --no-header 2>/dev/null | awk -v target_port="$DB_PORT" '$3 == target_port { print $1 " " $2 " " $4; exit }' || true)"

    if [[ -n "$cluster_info" ]]; then
      local cluster_ver cluster_name cluster_status
      cluster_ver="$(awk '{print $1}' <<<"$cluster_info")"
      cluster_name="$(awk '{print $2}' <<<"$cluster_info")"
      cluster_status="$(awk '{print $3}' <<<"$cluster_info")"

      if [[ "$cluster_status" == "online" ]]; then
        echo "[stop] Stopping PostgreSQL cluster ${cluster_ver}/${cluster_name}..."
        run_with_privileges pg_ctlcluster "$cluster_ver" "$cluster_name" stop || true
      else
        echo "[stop] PostgreSQL cluster ${cluster_ver}/${cluster_name} is already down"
      fi
      stopped="true"
    fi
  fi

  if [[ "$stopped" != "true" ]] && command -v systemctl >/dev/null 2>&1; then
    if systemctl list-unit-files | grep -q '^postgresql\.service'; then
      echo "[stop] Stopping PostgreSQL service..."
      run_with_privileges systemctl stop postgresql || true
      stopped="true"
    fi
  elif [[ "$stopped" != "true" ]] && command -v service >/dev/null 2>&1; then
    echo "[stop] Stopping PostgreSQL service..."
    run_with_privileges service postgresql stop || true
    stopped="true"
  fi

  if ss -ltn "sport = :$DB_PORT" 2>/dev/null | grep -q LISTEN; then
    echo "[stop] PostgreSQL still listening on port ${DB_PORT}"
  else
    echo "[stop] PostgreSQL stopped on port ${DB_PORT}"
  fi
}

echo "[stop] Stopping web and flutter processes..."
stop_port_processes "$BACKEND_PORT"
stop_port_processes "$WEB_PORT"
stop_port_processes "$FLUTTER_PORT"

echo "[stop] Stopping database..."
stop_db

echo "[stop] Done"
