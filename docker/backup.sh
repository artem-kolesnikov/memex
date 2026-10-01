#!/bin/bash
set -euo pipefail
if [ "$(id -u)" = 0 ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$0" "$@"
fi
umask 077

data=/data
keep=7
[ -f "$data/directory.sqlite" ] || exit 0

stamp=$(date -u +%Y%m%d-%H%M%S)
work="$data/backups/.$stamp"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/vaults"

copy() {
    sqlite3 -readonly "$data/$1" ".timeout 5000" "VACUUM INTO '$work/$1'"
    if [ "$(sqlite3 -readonly "$work/$1" 'PRAGMA integrity_check')" != ok ]; then
        echo "memex: the copy of $1 fails its integrity check" >&2
        exit 1
    fi
}

copy directory.sqlite
vaults=0
for vault in "$data"/vaults/*.sqlite; do
    [ -e "$vault" ] || continue
    copy "vaults/$(basename "$vault")"
    vaults=$((vaults + 1))
done

if [ "$vaults" -gt 0 ]; then
    mkdir "$work/markdown"
    (cd /app/backend && php bin/console app:export-vault "$work/markdown" --all-vaults --no-interaction > /dev/null) \
        || echo "memex: no Markdown export in this backup; a memex without notes has none" >&2
fi

mv "$work" "$data/backups/$stamp"
trap - EXIT
find "$data/backups" -mindepth 1 -maxdepth 1 -type d -name '2*' | sort | head -n "-$keep" | xargs -r rm -rf
echo "memex: backup written to $data/backups/$stamp"
