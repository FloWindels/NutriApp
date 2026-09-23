#!/usr/bin/env bash
# Sauvegarde la base Mavi'oh et purge les archives trop anciennes.
#
#   sudo bash scripts/deploy/backup.sh
#
# Installation en tâche quotidienne (3 h du matin) :
#   sudo cp scripts/deploy/backup.sh /usr/local/bin/mavioh-backup && sudo chmod +x /usr/local/bin/mavioh-backup
#   echo '0 3 * * * root /usr/local/bin/mavioh-backup' | sudo tee /etc/cron.d/mavioh-backup
#
# Variables : BACKUP_DIR (défaut /var/backups/mavioh), KEEP_DAYS (défaut 14).
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/mavioh}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%F-%H%M)"
TARGET="${BACKUP_DIR}/mavioh-${STAMP}.sql.gz"

if [[ "${EUID}" -ne 0 ]]; then
  echo "[mavioh] Ce script doit être lancé avec sudo." >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# L'archive est écrite sous un nom temporaire : un pg_dump interrompu ne laisse pas
# une sauvegarde tronquée portant un nom valide.
sudo -u postgres pg_dump mavioh | gzip > "${TARGET}.part"
mv "${TARGET}.part" "$TARGET"
chmod 600 "$TARGET"

find "$BACKUP_DIR" -name 'mavioh-*.sql.gz' -mtime "+${KEEP_DAYS}" -delete

echo "[mavioh] Sauvegarde : $TARGET ($(du -h "$TARGET" | cut -f1))"
echo "[mavioh] Restauration : gunzip -c $TARGET | sudo -u postgres psql mavioh"
