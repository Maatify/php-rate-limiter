#!/usr/bin/env bash
# #153 phase 2, LT-P2-15 / LT-P2-17 (PLAN §12.3, §12.4): runs tail_cause.php once on
# E-SAME (a fresh local redis-server), descriptive only. V-1 as run.sh: src/, tests/
# and composer.json committed and clean; the archived lib.php equal to main.
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../../.." && pwd)"
lib=investigations/clock-skew-153/harness/lib.php

if [[ -n "$(git -C "$root" status --porcelain -- src tests composer.json)" ]]; then
    echo 'V-1 FAIL: src/, tests/ or composer.json are not committed and clean.' >&2
    exit 1
fi
main_ref="$(git -C "$root" rev-parse --verify -q origin/main || git -C "$root" rev-parse main)"
if [[ "$(git -C "$root" rev-parse "HEAD:$lib")" != "$(git -C "$root" rev-parse "$main_ref:$lib")" ]] \
    || [[ -n "$(git -C "$root" status --porcelain -- "$lib")" ]]; then
    echo "V-1 FAIL: archived $lib differs from main or is not clean." >&2
    exit 1
fi

out="${ACCEPT_OUT:-$here/expected}/E-SAME"
mkdir -p "$out" "$here/logs"
rm -f "$out/tail-cause.jsonl" "$out/tail-cause.jsonl.gz"
log="$here/logs/E-SAME-tail-cause.log"
php=(env XDEBUG_MODE=off php -d memory_limit=1G)
{
    echo "env=E-SAME tail-cause head=$(git -C "$root" rev-parse HEAD) main=$main_ref started=$(date -u +%FT%TZ)"
    echo "blob $lib $(git -C "$root" rev-parse "HEAD:$lib")"
    echo "php=$("${php[@]}" -r 'echo PHP_VERSION;') os=$(uname -sr)"
} | tee "$log"

port="$("${php[@]}" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
redis_dir="$(mktemp -d)"
redis-server --port "$port" --save "" --appendonly no --daemonize yes \
    --pidfile "$redis_dir/redis.pid" --logfile "$redis_dir/redis.log" --dir "$redis_dir"
trap 'kill "$(cat "$redis_dir/redis.pid")" 2>/dev/null || true; rm -rf "$redis_dir"' EXIT
until redis-cli -p "$port" ping >/dev/null 2>&1; do sleep 0.1; done
echo "redis=$(redis-cli -p "$port" INFO server | tr -d '\r' | grep '^redis_version:')" | tee -a "$log"
"${php[@]}" "$here/tail_cause.php" "$root" E-SAME 127.0.0.1 "$port" "$out/tail-cause.jsonl" 2>&1 | tee -a "$log"
echo "finished=$(date -u +%FT%TZ)" | tee -a "$log"
