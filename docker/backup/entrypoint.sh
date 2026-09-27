#!/bin/sh
# Runs backup.sh once a day at BACKUP_HOUR, then sleeps.
#
# A sleep loop rather than crond: one process, logs to stdout, and no second
# supervisor to reason about inside a container.
set -e

HOUR="${BACKUP_HOUR:-3}"

echo "[backup] scheduled daily at ${HOUR}:00 UTC, retaining ${BACKUP_RETAIN_DAYS:-30} days"

while true; do
    now_h=$(date +%-H)
    now_m=$(date +%-M)

    # Seconds until the next occurrence of HOUR:00.
    wait_s=$(( ((HOUR - now_h + 24) % 24) * 3600 - now_m * 60 ))
    [ "$wait_s" -le 0 ] && wait_s=$((wait_s + 86400))

    echo "[backup] next run in $((wait_s / 3600))h $(((wait_s % 3600) / 60))m"
    sleep "$wait_s"

    # Never let one failed dump kill the loop - tomorrow's must still run.
    /usr/local/bin/backup.sh || echo "[backup] run failed, will retry tomorrow" >&2
done
