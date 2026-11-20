#!/usr/bin/env bash
# usage: run270.sh <php64|php32> <phpunit args...>   (isolated Redis 7.0.15 per run, internal network, no published ports)
set -euo pipefail
img=$1; shift
R="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
S="${EVIDENCE_DIR:-/tmp}"
net=n270-$img-$$; rd=r270-$img-$$
docker network create --internal "$net" >/dev/null
docker run -d --rm --name "$rd" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
trap 'docker rm -f "$rd" >/dev/null 2>&1; docker network rm "$net" >/dev/null 2>&1' EXIT
sleep 3
docker run --rm --network "$net" -v "$R":/app:ro -v "$S":/out -w /app -e REDIS_INTEGRATION_HOST="$rd" -e REDIS_INTEGRATION_PORT=6379 -e EXPIRY_270_EVIDENCE=/out/ev-$img.jsonl "maatify-rl-${img}:local" php vendor/bin/phpunit --do-not-cache-result "$@"
