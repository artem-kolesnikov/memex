#!/bin/bash
set -euo pipefail

if [ "$(id -u)" = 0 ]; then
    find /data ! -user www-data -exec chown www-data:www-data {} +
    drop=(setpriv --reuid=www-data --regid=www-data --init-groups)
else
    drop=()
fi
export HOME=/tmp
as_www() { "${drop[@]}" "$@"; }

if ! as_www test -w /data; then
    echo "memex: /data is not writable. Mount a named volume (-v memex:/data)." >&2
    exit 1
fi
as_www mkdir -p /data/log /data/backups
if [ ! -s /data/secret.env ]; then
    as_www sh -c 'umask 077 && printf "APP_SECRET=%s\n" "$(od -An -tx1 -N32 /dev/urandom | tr -d " \n")" > /data/secret.env.part && mv /data/secret.env.part /data/secret.env'
fi

cd /app/backend
as_www php bin/console app:setup-code --no-interaction

pids=()
php-fpm & pids+=($!)
(cd /app/ml-processor && exec "${drop[@]}" node src/index.js) & pids+=($!)
nginx -g 'daemon off;' & pids+=($!)
as_www /app/docker/schedule.sh & pids+=($!)

trap 'kill -TERM "${pids[@]}" 2>/dev/null; wait; exit 0' TERM INT
status=0
wait -n || status=$?
echo "memex: a process exited ($status); stopping the container" >&2
kill -TERM "${pids[@]}" 2>/dev/null || true
wait || true
exit "$status"
