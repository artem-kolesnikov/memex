#!/bin/bash
set -uo pipefail
cd /app/backend

sleep 60
while true; do
    php bin/console app:embed 500 --no-interaction || echo "memex: the embedding sweep failed" >&2
    if [ "$(cat /data/backups/.last-daily 2>/dev/null)" != "$(date -u +%F)" ]; then
        php bin/console app:purge-limbo --no-interaction \
            && php bin/console app:purge-oauth --no-interaction \
            && /app/docker/backup.sh \
            && date -u +%F > /data/backups/.last-daily \
            || echo "memex: the daily purge and backup failed; the next sweep tries again" >&2
    fi
    sleep 900
done
