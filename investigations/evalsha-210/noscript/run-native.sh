#!/usr/bin/env bash
# The native-client part of the #210 study, second round: reply shapes of ext-redis rawCommand(), the NOSCRIPT classification test class, the
# proxy artifact probe and the command-level latency probe. PHP and Redis run in Docker; Redis is isolated (started here, no persistence).
#   bash investigations/evalsha-210/noscript/run-native.sh [output-directory]    (default: investigations/evalsha-210/noscript/raw-native)
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
out_rel="${1:-investigations/evalsha-210/noscript/raw-native}"
out="$root/$out_rel"
php_image="${PHP_IMAGE:-maatify-rl-php64:local}"
name="rl210-native-$$"
mkdir -p "$out"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run -d --name "$name" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [[ "$(docker exec "$name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done
{
    echo "git: $(git -C "$root" rev-parse HEAD)  tree: $(git -C "$root" rev-parse 'HEAD^{tree}')"
    echo "tracked-status (empty = clean): $(git -C "$root" status --porcelain --untracked-files=no | tr '\n' ';')"
    echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "php-image: $php_image $(docker image inspect "$php_image" --format '{{.Id}} {{.Architecture}}')"
    echo "php: $(docker run --rm "$php_image" php -r 'echo PHP_VERSION, " INT_SIZE=", PHP_INT_SIZE, " ext-redis=", phpversion("redis");')"
    echo "redis: $(docker exec "$name" redis-server --version)"
} > "$out/environment.txt"
run() { docker run --rm --network "container:$name" -v "$root:/app" -w /app -e REDIS_INTEGRATION_HOST=127.0.0.1 -e REDIS_INTEGRATION_PORT=6379 "$php_image" "$@"; }
run php investigations/evalsha-210/noscript/shape-probe.php 127.0.0.1 6379 > "$out/shape-probe.txt"
run php -d memory_limit=1G vendor/bin/phpunit --testdox tests/Integration/Redis/NativeNoscriptStudyRedisTest.php > "$out/native-noscript-testdox.txt" || true
run php -d memory_limit=1G vendor/bin/phpunit tests/Integration/Redis/EvalshaStudyRedisTest.php > "$out/evalsha-study-test.txt" || true
run sh -c '
php investigations/evalsha-210/delay-proxy.php 6402 127.0.0.1 6379 3.5 & php investigations/evalsha-210/noscript/delay-proxy-nodelay.php 6403 127.0.0.1 6379 3.5 & sleep 1
echo "-- direct"; php investigations/evalsha-210/noscript/payload-latency-probe.php 127.0.0.1 6379
echo "-- first-round proxy (delay-proxy.php, 3.5 ms each way)"; php investigations/evalsha-210/noscript/payload-latency-probe.php 127.0.0.1 6402
echo "-- delay-proxy-nodelay.php (3.5 ms each way)"; php investigations/evalsha-210/noscript/payload-latency-probe.php 127.0.0.1 6403' > "$out/payload-latency-probe.txt" 2>&1 || true
run sh -c '
php investigations/evalsha-210/noscript/delay-proxy-nodelay.php 6403 127.0.0.1 6379 2.5 & sleep 1
for conns in 1 8; do for rep in 1 2 3; do
  if [ $((rep % 2)) = 1 ]; then o="eval evalsha"; else o="evalsha eval"; fi
  echo "== connections $conns repetition $rep order $o"; php investigations/evalsha-210/noscript/command-latency-probe.php 127.0.0.1 6403 $conns 150 13000 $o
done; done' > "$out/command-latency-probe.txt" 2>&1 || true
echo "raw results in $out"
