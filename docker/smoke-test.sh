#!/bin/bash
# Runs an image the way an owner does and fails unless memex answers end to end:
# docker/smoke-test.sh <image>
set -euo pipefail

image="${1:?usage: smoke-test.sh <image>}"
name="memex-smoke-$$"
volume="memex-smoke-$$"
port="${SMOKE_PORT:-18080}"
base="http://127.0.0.1:$port"
jar="$(mktemp)"

cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then docker logs "$name" 2>&1 | tail -40 >&2 || true; fi
    docker rm -f "$name" > /dev/null 2>&1 || true
    docker volume rm "$volume" > /dev/null 2>&1 || true
    rm -f "$jar"
    exit "$status"
}
trap cleanup EXIT

fail() {
    echo "smoke test: $1" >&2
    exit 1
}

json() {
    python3 -c "import json, sys; print(eval(sys.argv[1], {'json': json, 'd': json.load(sys.stdin)}))" "$1"
}

docker run -d --name "$name" -p "127.0.0.1:$port:8080" -v "$volume:/data" "$image" > /dev/null

for _ in $(seq 60); do
    curl -fsS "$base/api/health" > /dev/null 2>&1 && break
    sleep 2
done
[ "$(curl -fsS "$base/api/health" | json 'd["status"]')" = ok ] || fail "/api/health is not ok"

for path in / /login /favicon.svg; do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$base$path")" = 200 ] || fail "$path does not answer 200"
done
case "$(curl -sI "$base/login")" in
    *[Cc]ontent-[Ss]ecurity-[Pp]olicy:*) ;;
    *) fail "/login carries no Content-Security-Policy" ;;
esac
issuer="$(curl -fsS "$base/.well-known/oauth-authorization-server" | json 'd["issuer"]')"
[ "$issuer" = "$base" ] || fail "OAuth discovery names $issuer, not the address in use, $base"

case "$(docker logs "$name" 2>&1)" in
    *"Setup code: "*) ;;
    *) fail "the log does not print the setup code" ;;
esac
[ "$(curl -fsS "$base/api/auth/owner" | json 'd["code"]')" = False ] || fail "first run asks for the code in its first ten minutes"

curl -fsS -c "$jar" -H 'Content-Type: application/json' \
    -d '{"email":"owner@example.com","password":"smoke-test-owner-password"}' \
    "$base/api/auth/owner" > /dev/null || fail "first run did not create the owner"
[ "$(curl -fsS "$base/api/auth/owner" | json 'd["owner"]')" = True ] || fail "the owner does not exist after first run"

minted="$(docker exec "$name" php bin/console app:create-token owner@example.com smoke)"
token="$(grep -m1 -oE '[A-Za-z0-9_-]{32,}' <<< "$minted" || true)"
[ -n "$token" ] || fail "app:create-token printed no token"

docker exec "$name" php bin/console app:embed 50 > /dev/null || fail "the embedding sweep failed"

search='{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search","arguments":{"query":"connecting an AI helper","limit":3}}}'
titles="$(curl -fsS "$base/mcp" -H "Authorization: Bearer $token" -H 'Content-Type: application/json' -d "$search" \
    | json '[i["title"] for i in json.loads(d["result"]["content"][0]["text"])["items"]]' 2>/dev/null || true)"
case "$titles" in
    *"Connect your first assistant"*) ;;
    *) fail "search by meaning did not find the welcome note: $titles" ;;
esac

reply="$(printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"health","arguments":{}}}' \
    | docker exec -i -e "MEMEX_TOKEN=$token" "$name" php bin/console app:mcp-stdio)"
case "$reply" in
    *'\"status\":\"ok\"'*) ;;
    *) fail "app:mcp-stdio did not answer health: $reply" ;;
esac

docker exec "$name" /app/docker/backup.sh > /dev/null || fail "the backup failed"
docker exec "$name" sh -c 'ls /data/backups/2*/markdown/*.zip' > /dev/null || fail "the backup holds no Markdown export"

docker stop "$name" > /dev/null
docker start "$name" > /dev/null
for _ in $(seq 30); do
    curl -fsS "$base/api/health" > /dev/null 2>&1 && break
    sleep 2
done
[ "$(curl -fsS "$base/api/auth/owner" | json 'd["owner"]')" = True ] || fail "the owner is gone after a restart"

echo "smoke test: $image passed"
