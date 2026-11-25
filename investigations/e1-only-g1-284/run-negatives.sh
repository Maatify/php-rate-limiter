#!/usr/bin/env bash
# Negative routing controls in separate source files; the checkout is never swapped.
set -euo pipefail
cd "$(dirname "$0")/../.."
width="${1:?32 or 64}"
case "$width" in 32) platform=linux/386; image=maatify-rl-php32:local;; 64) platform=linux/arm64; image=maatify-rl-php64:local;; *) exit 2;; esac
scratch="$(mktemp -d "${TMPDIR:-/tmp}/g1-negative.XXXXXX")"
network="g1-negative-${width}-$$"; redis_name="${network}-redis"
cleanup() { status=$?; docker rm -f "$redis_name" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; rm -rf -- "$scratch"; exit "$status"; }
trap cleanup EXIT
php investigations/e1-only-g1-284/write-mutants.php "$scratch"
docker network create "$network" >/dev/null
docker run -d --name "$redis_name" --network "$network" redis:7.0.15-alpine redis-server --save '' --appendonly no >/dev/null
until [[ "$(docker exec "$redis_name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.2; done
for kind in budget gate bucket; do
 out="investigations/e1-only-g1-284/raw/negative-${width}-${kind}.log"
 set +e
 docker run --rm --platform "$platform" --network "$network" -v "$PWD:/app" -v "$scratch:/mutants:ro" -w /app \
  -e REDIS_INTEGRATION_HOST="$redis_name" -e REDIS_INTEGRATION_PORT=6379 -e G1_MUTANT="$kind" "$image" \
  php -d auto_prepend_file=/mutants/bootstrap.php vendor/bin/phpunit tests/Integration/Redis/KeyScopeE1RedisTest.php --no-progress > "$out" 2>&1
 status=$?
 set -e
 if [[ "$status" != 1 && "$status" != 2 ]]; then echo "Invalid negative control $kind: exit $status"; exit 1; fi
 if ! rg -q '(Failures: [1-9]|Errors: [1-9])' "$out"; then echo "Missing detection for $kind"; exit 1; fi
 echo "Expected detection: $kind width=$width exit=$status" >> "$out"
done
