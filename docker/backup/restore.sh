#!/bin/sh
# Restore a dump. Run by hand:
#
#   docker compose run --rm backup restore.sh pharmacy-2026-09-27_0300.sql.gz
#
# DEPLOYMENT_WEB.md §7 is right that an untested backup is not a backup. Pass
# a second argument to restore into a scratch database instead of the live one,
# which is how you test without betting the shop on it:
#
#   docker compose run --rm backup restore.sh <file> pharmacy_restore_test
set -e

FILE="$1"
TARGET_DB="${2:-$DB_DATABASE}"

if [ -z "$FILE" ]; then
    echo "usage: restore.sh <dump.sql.gz> [target-database]" >&2
    exit 64
fi

[ -f "$FILE" ] || FILE="/backups/$FILE"

if [ ! -f "$FILE" ]; then
    echo "no such dump: $1" >&2
    exit 66
fi

gzip -t "$FILE" || { echo "dump is corrupt, refusing to restore" >&2; exit 65; }

if [ "$TARGET_DB" = "$DB_DATABASE" ]; then
    echo "About to overwrite the LIVE database '$TARGET_DB' with $FILE."
    printf 'Type the database name to confirm: '
    read -r confirm
    [ "$confirm" = "$TARGET_DB" ] || { echo "aborted"; exit 1; }
fi

mysql --skip-ssl --host="$DB_HOST" --port="${DB_PORT:-3306}" \
      --user="$DB_USERNAME" --password="$DB_PASSWORD" \
      -e "CREATE DATABASE IF NOT EXISTS \`$TARGET_DB\`
          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

gunzip < "$FILE" | mysql --skip-ssl --host="$DB_HOST" --port="${DB_PORT:-3306}" \
      --user="$DB_USERNAME" --password="$DB_PASSWORD" "$TARGET_DB"

echo "restored $FILE into $TARGET_DB"
