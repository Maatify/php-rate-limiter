#!/usr/bin/env bash
# #153 phase 1 runner (PLAN.md §5.4, §7, §11). Usage: run.sh E-SAME | E-DOCKER
# V-1: refuses unless src/, tests/ and composer.json are byte-identical to the
# baseline and clean. Writes expected/<env>/ and logs/<env>.log.
set -euo pipefail

env_id="${1:?usage: run.sh E-SAME|E-DOCKER}"
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
study="$(cd -- "$here/.." && pwd)"
root="$(cd -- "$study/../.." && pwd)"
baseline=1e951ee3c3b29ecc49ce08ceff809869bccf139d

if ! git -C "$root" diff --quiet "$baseline" -- src tests composer.json \
    || [[ -n "$(git -C "$root" status --porcelain -- src tests composer.json)" ]]; then
    echo 'V-1 FAIL: src/, tests/ or composer.json differ from the baseline or are not clean.' >&2
    exit 1
fi

out="$study/expected/$env_id"
rm -rf "$out"
mkdir -p "$out" "$study/logs"
log="$study/logs/$env_id.log"
# Xdebug is loaded by the local php.ini; mode off removes its overhead from the timings.
php=(env XDEBUG_MODE=off php -d memory_limit=1G)

{
    echo "env=$env_id baseline=$baseline head=$(git -C "$root" rev-parse HEAD) started=$(date -u +%FT%TZ)"
    echo "php=$("${php[@]}" -r 'echo PHP_VERSION;') os=$(uname -sr)"
} | tee "$log"

case "$env_id" in
    E-SAME)
        port="$("${php[@]}" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
        redis_dir="$(mktemp -d)"
        redis-server --port "$port" --save "" --appendonly no --daemonize yes \
            --pidfile "$redis_dir/redis.pid" --logfile "$redis_dir/redis.log" --dir "$redis_dir"
        trap 'kill "$(cat "$redis_dir/redis.pid")" 2>/dev/null || true; rm -rf "$redis_dir"' EXIT
        until redis-cli -p "$port" ping >/dev/null 2>&1; do sleep 0.1; done
        echo "redis=$(redis-cli -p "$port" INFO server | tr -d '\r' | grep '^redis_version:')" | tee -a "$log"
        "${php[@]}" "$here/run.php" "$root" E-SAME 127.0.0.1 "$port" "$out" 2>&1 | tee -a "$log"
        "${php[@]}" "$here/host_surface.php" "$root" E-SAME 127.0.0.1 "$port" "$out/host-surface.txt" 2>&1 | tee -a "$log"
        "${php[@]}" "$here/tail.php" "$root" E-SAME 127.0.0.1 "$port" "$out/tail.jsonl" 2>&1 | tee -a "$log"
        ;;
    E-DOCKER)
        bash "$root/scripts/ci/run-with-redis-service.sh" bash -c '
            set -euo pipefail
            php=(env XDEBUG_MODE=off php -d memory_limit=1G)
            echo "redis=$(printf "INFO server\r\n" | nc -w 2 "$REDIS_INTEGRATION_HOST" "$REDIS_INTEGRATION_PORT" | tr -d "\r" | grep "^redis_version:")"
            "${php[@]}" "$0/run.php" "$1" E-DOCKER "$REDIS_INTEGRATION_HOST" "$REDIS_INTEGRATION_PORT" "$2"
            "${php[@]}" "$0/host_surface.php" "$1" E-DOCKER "$REDIS_INTEGRATION_HOST" "$REDIS_INTEGRATION_PORT" "$2/host-surface.txt"
        ' "$here" "$root" "$out" 2>&1 | tee -a "$log"
        ;;
    *)
        echo "unknown env $env_id" >&2
        exit 2
        ;;
esac

echo "finished=$(date -u +%FT%TZ)" | tee -a "$log"
