#!/usr/bin/env bash
# #153 phase 2, gate §4.5 (non-regression of the measured S0 behaviour), E-SAME.
# Reruns the six #156 stream cells at σ = AA (both twins at 0, SYS clocks)
# registered for S-BEN and S-RA in its run.sh (LOGIN-NA, CYCLE-NA, LOGIN), on the
# fix head, with the archived #156 run.php unchanged; s0_parity.php compares
# them with the archived pre-fix outputs. Added after the first acceptance run:
# run.sh did not include this registered gate. V-1 as run.sh.
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../../.." && pwd)"
p156="$root/investigations/clock-mixes-153/harness"
archived=(
    investigations/clock-skew-153/harness/lib.php
    investigations/clock-mixes-153/harness/mixlib.php
    investigations/clock-mixes-153/harness/run.php
)

if [[ -n "$(git -C "$root" status --porcelain -- src tests composer.json)" ]]; then
    echo 'V-1 FAIL: src/, tests/ or composer.json are not committed and clean.' >&2
    exit 1
fi
main_ref="$(git -C "$root" rev-parse --verify -q origin/main || git -C "$root" rev-parse main)"
for path in "${archived[@]}"; do
    if [[ "$(git -C "$root" rev-parse "HEAD:$path")" != "$(git -C "$root" rev-parse "$main_ref:$path")" ]] \
        || [[ -n "$(git -C "$root" status --porcelain -- "$path")" ]]; then
        echo "V-1 FAIL: archived $path differs from main or is not clean." >&2
        exit 1
    fi
done

out="${ACCEPT_OUT:-$here/expected}/E-SAME/s0"
rm -rf "$out"
mkdir -p "$out" "$here/logs"
log="$here/logs/E-SAME-s0.log"
php=(env XDEBUG_MODE=off php -d memory_limit=1G)

{
    echo "env=E-SAME gate=4.5 head=$(git -C "$root" rev-parse HEAD) main=$main_ref started=$(date -u +%FT%TZ)"
    for path in "${archived[@]}"; do echo "blob $path $(git -C "$root" rev-parse "HEAD:$path")"; done
    echo "php=$("${php[@]}" -r 'echo PHP_VERSION;') os=$(uname -sr)"
} | tee "$log"

port="$("${php[@]}" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
redis_dir="$(mktemp -d)"
redis-server --port "$port" --save "" --appendonly no --daemonize yes --maxclients 4096 \
    --pidfile "$redis_dir/redis.pid" --logfile "$redis_dir/redis.log" --dir "$redis_dir"
trap 'kill "$(cat "$redis_dir/redis.pid")" 2>/dev/null || true; rm -rf "$redis_dir"' EXIT
until redis-cli -p "$port" ping >/dev/null 2>&1; do sleep 0.1; done
echo "redis=$(redis-cli -p "$port" INFO server | tr -d '\r' | grep '^redis_version:')" | tee -a "$log"

{
    echo "group s0: 6 cells started=$(date -u +%FT%TZ)"
    pids=()
    for spec in "S-BEN LOGIN-NA" "S-RA LOGIN-NA" "S-BEN CYCLE-NA" "S-RA CYCLE-NA" "S-BEN LOGIN" "S-RA LOGIN"; do
        read -r family policy <<<"$spec"
        "${php[@]}" "$p156/run.php" "$root" E-SAME 127.0.0.1 "$port" "$out" "$family" "$policy" AA 200 1 &
        pids+=("$!")
    done
    failed=0
    for pid in "${pids[@]}"; do
        wait "$pid" || failed=$((failed + 1))
    done
    echo "group s0: finished=$(date -u +%FT%TZ) failed=$failed"
} 2>&1 | tee -a "$log"

echo "finished=$(date -u +%FT%TZ)" | tee -a "$log"
