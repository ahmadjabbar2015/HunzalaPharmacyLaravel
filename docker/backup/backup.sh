#!/bin/sh
# Nightly database backup.
#
# The database is the ONLY copy of the shop's data - there is no SQLite file on
# a till to fall back on (DEPLOYMENT_WEB.md §7). This runs in its own container
# so a backup does not depend on the app container being healthy.
set -e

DEST=/backups
RETAIN_DAYS="${BACKUP_RETAIN_DAYS:-30}"
STAMP="$(date +%F_%H%M)"
TARGET="$DEST/pharmacy-$STAMP.sql.gz"

mkdir -p "$DEST"

echo "[backup] dumping ${DB_DATABASE} -> $TARGET"

# --single-transaction dumps from one consistent snapshot without locking the
# tables, so a sale rung mid-backup neither blocks nor lands half-recorded.
mysqldump \
    --host="${DB_HOST}" \
    --port="${DB_PORT:-3306}" \
    --user="${DB_USERNAME}" \
    --password="${DB_PASSWORD}" \
    --single-transaction \
    --routines \
    --quick \
    "${DB_DATABASE}" \
  | gzip > "$TARGET.partial"

# Rename only after a clean exit, so an interrupted dump is never mistaken for
# a usable backup by the restore script or by a person in a hurry.
mv "$TARGET.partial" "$TARGET"

echo "[backup] wrote $(du -h "$TARGET" | cut -f1)"

# A zero-length or truncated gzip is worse than no backup, because it looks
# like one. Verify before pruning anything older.
if ! gzip -t "$TARGET"; then
    echo "[backup] FAILED integrity check, keeping old backups" >&2
    exit 1
fi

find "$DEST" -name 'pharmacy-*.sql.gz' -mtime "+$RETAIN_DAYS" -delete
find "$DEST" -name 'pharmacy-*.partial' -mtime +1 -delete

echo "[backup] done. NOTE: copy $DEST off this server - a backup on the same"
echo "[backup] disk does not survive the disk."
