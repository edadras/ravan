#!/usr/bin/env bash
# Dump the database and the uploaded files into deploy/mysql/backups/.
#
#   ./deploy/backup.sh              one backup, timestamped
#   ./deploy/backup.sh --keep 14    also delete backups older than 14 days
#
# Add to cron:  0 3 * * *  cd /opt/ravan && ./deploy/backup.sh --keep 14
set -euo pipefail
cd "$(dirname "$0")/.."

KEEP=0
[ "${1:-}" = "--keep" ] && KEEP="${2:-14}"

set -a; . ./.env; set +a
OUT="deploy/mysql/backups"
mkdir -p "$OUT"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)

echo "==> dumping ${DB_DATABASE}"
docker compose exec -T mysql mysqldump \
    --single-transaction --quick --routines --triggers \
    -u root -p"${DB_ROOT_PASSWORD}" "${DB_DATABASE}" \
    | gzip -9 > "${OUT}/db-${STAMP}.sql.gz"

echo "==> archiving uploaded files"
docker compose run --rm --no-deps -T \
    -v "$(pwd)/${OUT}:/backup" backend \
    tar czf "/backup/storage-${STAMP}.tar.gz" -C /app/storage/app . 2>/dev/null \
    || echo "    (no uploaded files yet)"

if [ "$KEEP" -gt 0 ]; then
    find "$OUT" -name '*.gz' -mtime "+${KEEP}" -print -delete
fi

echo "==> done:"
ls -lh "${OUT}" | tail -5
echo
echo "These files contain patient data. Store them encrypted and off this server."
