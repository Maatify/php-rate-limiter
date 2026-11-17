#!/usr/bin/env bash
# Paired EVAL / EVALSHA p99 measurement of the #210 study, second round. PHP, Redis and the measurements run in Docker.
#   bash investigations/evalsha-210/noscript/run-paired.sh [output-directory]    (default: investigations/evalsha-210/noscript/raw-paired)
# Redis: redis:7.0.15-alpine, no persistence, started by this script. The PHP container shares the network namespace of the Redis
# container, so "loopback" is a real 127.0.0.1 connection; the round trip of the RTT sets is a delay proxy in front of
# that Redis (2.5 ms each way for the 7 ms sets, 3.5 ms each way for the 9 ms set of the first round's configuration), and its measured value is recorded by rtt-probe.php before and after every set. Everything runs sequentially.
# Env: PHP_IMAGE (default maatify-rl-php64:local), STUDY_REDIS_IMAGE, PAIRS_RTT7_W8X10 / PAIRS_LOOP_W8X10 / PAIRS_RTT7_W8X40 / PAIRS_RTT9_W8X10 / PAIRS_LOOP_W16X25.
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
out_rel="${1:-investigations/evalsha-210/noscript/raw-paired}"
out="$root/$out_rel"
php_image="${PHP_IMAGE:-maatify-rl-php64:local}"
redis_image="${STUDY_REDIS_IMAGE:-redis:7.0.15-alpine}"
name="rl210-paired-$$"
mkdir -p "$out"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT

docker run -d --name "$name" "$redis_image" redis-server --save "" --appendonly no >/dev/null
until [[ "$(docker exec "$name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done

sha="$(git -C "$root" rev-parse HEAD)"
{
    echo "git: $sha"
    echo "tree: $(git -C "$root" rev-parse 'HEAD^{tree}')"
    echo "tracked-status (empty = clean): $(git -C "$root" status --porcelain --untracked-files=no | tr '\n' ';')"
    echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "host: $(uname -a)"
    echo "docker: $(docker --version)"
    echo "docker-info: $(docker info --format 'NCPU={{.NCPU}} Mem={{.MemTotal}} OS={{.OperatingSystem}} Kernel={{.KernelVersion}}')"
    echo "php-image: $php_image $(docker image inspect "$php_image" --format '{{.Id}} {{.Architecture}}')"
    echo "redis-image: $redis_image $(docker image inspect "$redis_image" --format '{{.Id}} {{.Architecture}}')"
    echo "php: $(docker run --rm "$php_image" php -r 'echo PHP_VERSION, " INT_SIZE=", PHP_INT_SIZE, " ext-redis=", phpversion("redis") ?: "none", " pcntl=", extension_loaded("pcntl") ? "yes" : "no";')"
    echo "container nproc: $(docker run --rm "$php_image" nproc)"
    echo "redis: $(docker exec "$name" redis-server --version)"
    echo "redis config:"
    for key in maxmemory busy-reply-threshold tcp-keepalive save appendonly; do docker exec "$name" redis-cli config get "$key" | tr '\n' ' '; echo; done
    echo "client: stream-socket RESP client without pipelining (tests/Support/Redis/RespRedisCommandExecutor), one connection per worker"
    echo "pairs: rtt7-w8x10=${PAIRS_RTT7_W8X10:-10} loop-w8x10=${PAIRS_LOOP_W8X10:-10} rtt7-w8x40=${PAIRS_RTT7_W8X40:-6} rtt9-w8x10=${PAIRS_RTT9_W8X10:-6} loop-w16x25=${PAIRS_LOOP_W16X25:-6}; settle=${STUDY_SETTLE_SECONDS:-2}s"
} > "$out/environment.txt"

docker run --rm --network "container:$name" -v "$root:/app" -w /app -e "STUDY_GIT_SHA=$sha" -e PAIRS_RTT7_W8X10 -e PAIRS_LOOP_W8X10 -e PAIRS_RTT7_W8X40 -e PAIRS_RTT9_W8X10 -e PAIRS_LOOP_W16X25 -e STUDY_SETTLE_SECONDS -e STUDY_ONE_WAY_MS_7 -e STUDY_ONE_WAY_MS_9 "$php_image" \
    bash "investigations/evalsha-210/noscript/paired-inner.sh" "/app/$out_rel"
echo "raw results in $out"
