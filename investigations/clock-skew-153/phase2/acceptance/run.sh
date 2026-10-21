#!/usr/bin/env bash
# #153 phase 2 acceptance runner (PLAN.md §4.6, §8 LT-P2-07, §10).
# Usage: run.sh E-SAME | E-DOCKER
# Reruns the archived #154 harness in full (RED-1c = its SYS_F cells) and the
# archived #156 probe groups on the fix head, by calling the archived scripts
# unchanged; then the two-clock arm (§4.7, E-SAME only). V-1: src/, tests/ and
# composer.json are committed and clean (the tested tree is the fix head), and
# every archived script used here is byte-identical to `main` (blob SHAs logged).
set -euo pipefail

env_id="${1:?usage: run.sh E-SAME|E-DOCKER}"
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../../.." && pwd)"
p1="$root/investigations/clock-skew-153/harness"
p156="$root/investigations/clock-mixes-153/harness"
archived=(
    investigations/clock-skew-153/harness/lib.php
    investigations/clock-skew-153/harness/run.php
    investigations/clock-skew-153/harness/host_surface.php
    investigations/clock-skew-153/harness/tail.php
    investigations/clock-mixes-153/harness/mixlib.php
    investigations/clock-mixes-153/harness/run.php
    investigations/clock-mixes-153/harness/offset.php
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

out="${ACCEPT_OUT:-$here/expected}/$env_id"
rm -rf "$out"
mkdir -p "$out/p154" "$out/p156" "$here/logs"
log="$here/logs/$env_id.log"
php=(env XDEBUG_MODE=off php -d memory_limit=1G)

{
    echo "env=$env_id head=$(git -C "$root" rev-parse HEAD) main=$main_ref started=$(date -u +%FT%TZ)"
    for path in "${archived[@]}"; do echo "blob $path $(git -C "$root" rev-parse "HEAD:$path")"; done
    echo "php=$("${php[@]}" -r 'echo PHP_VERSION;') os=$(uname -sr)"
} | tee "$log"

both=(-60 -10 -2 -1 -0.5 -0.25 +0.25 +0.5 +1 +2 +10 +60)
small=(-2 -1 -0.5 -0.25 +0.25 +0.5 +1 +2)

# #156 cells of one group run concurrently, as in the archived runner.
group() {
    local host="$1" port="$2" name="$3"
    shift 3
    echo "group $name: $# cells started=$(date -u +%FT%TZ)"
    local pids=() failed=0 spec
    for spec in "$@"; do
        read -r family policy sigma pairs <<<"$spec"
        "${php[@]}" "$p156/run.php" "$root" "$env_id" "$host" "$port" "$out/p156" "$family" "$policy" "$sigma" "$pairs" 1 &
        pids+=("$!")
    done
    for pid in "${pids[@]}"; do
        wait "$pid" || failed=$((failed + 1))
    done
    echo "group $name: finished=$(date -u +%FT%TZ) failed=$failed"
    [[ "$failed" -eq 0 ]]
}

matrix() {
    local host="$1" port="$2" cells s
    # #154 in full (its run.php, host surface, and, on E-SAME, the P-TAIL / P-HT wait).
    "${php[@]}" "$p1/run.php" "$root" "$env_id" "$host" "$port" "$out/p154"
    # After the fix limit() returns instead of throwing, so host_surface.php exits 1 at its
    # first (pre-fix) assertion; its output file is the record and report.php reads it.
    "${php[@]}" "$p1/host_surface.php" "$root" "$env_id" "$host" "$port" "$out/p154/host-surface.txt" || echo "host_surface.php exit $? (expected after the fix)"
    if [[ "$env_id" == E-SAME ]]; then
        "${php[@]}" "$p1/tail.php" "$root" "$env_id" "$host" "$port" "$out/p154/tail.jsonl"
    fi
    # #156 probe groups (as registered in its run.sh).
    "${php[@]}" "$p156/offset.php" "$root" "$host" "$port" "$out/p156/offset-before.jsonl"
    if [[ "$env_id" == E-SAME ]]; then
        cells=()
        for s in "${both[@]}"; do
            cells+=("P-E2 LOGIN-NA $s 200" "P-E2 CYCLE-NA $s 200" "P-E3 LOGIN-NA $s 200" "P-E3 CYCLE-NA $s 200")
        done
        cells+=("P-SAT LOGIN-NA +60 100")
        for s in "${small[@]}"; do
            cells+=("P-RA2 LOGIN-NA $s 100" "P-RA2 CYCLE-NA $s 100")
        done
        group "$host" "$port" probes-a "${cells[@]}"
        cells=()
        for s in "${both[@]}"; do
            cells+=("P-B LOGIN-NA $s 200")
        done
        for s in "${small[@]}"; do
            cells+=("P-I LOGIN-NA $s 200" "P-I CYCLE-NA $s 200" "P-RA1 LOGIN-NA $s 100")
        done
        group "$host" "$port" probes-b "${cells[@]}"
        # The two-clock arm (§4.7), once, E-SAME.
        "${php[@]}" "$here/two_clock.php" "$root" "$host" "$port" "$out/two-clock.jsonl"
    else
        cells=()
        for s in -1 +1; do
            cells+=("P-B LOGIN-NA $s 200" "P-E2 LOGIN-NA $s 200" "P-E2 CYCLE-NA $s 200"
                "P-RA1 LOGIN-NA $s 100" "P-RA2 LOGIN-NA $s 100" "P-RA2 CYCLE-NA $s 100")
        done
        group "$host" "$port" docker "${cells[@]}"
    fi
    "${php[@]}" "$p156/offset.php" "$root" "$host" "$port" "$out/p156/offset-after.jsonl"
}

case "$env_id" in
    E-SAME)
        port="$("${php[@]}" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')"
        redis_dir="$(mktemp -d)"
        redis-server --port "$port" --save "" --appendonly no --daemonize yes --maxclients 4096 \
            --pidfile "$redis_dir/redis.pid" --logfile "$redis_dir/redis.log" --dir "$redis_dir"
        trap 'kill "$(cat "$redis_dir/redis.pid")" 2>/dev/null || true; rm -rf "$redis_dir"' EXIT
        until redis-cli -p "$port" ping >/dev/null 2>&1; do sleep 0.1; done
        echo "redis=$(redis-cli -p "$port" INFO server | tr -d '\r' | grep '^redis_version:')" | tee -a "$log"
        matrix 127.0.0.1 "$port" 2>&1 | tee -a "$log"
        ;;
    E-DOCKER)
        export -f group matrix
        export env_id here root p1 p156 out
        export both_list="${both[*]}" small_list="${small[*]}"
        bash "$root/scripts/ci/run-with-redis-service.sh" bash -c '
            set -euo pipefail
            php=(env XDEBUG_MODE=off php -d memory_limit=1G)
            read -r -a both <<<"$both_list"; read -r -a small <<<"$small_list"
            echo "redis=$(printf "INFO server\r\n" | nc -w 2 "$REDIS_INTEGRATION_HOST" "$REDIS_INTEGRATION_PORT" | tr -d "\r" | grep "^redis_version:")"
            matrix "$REDIS_INTEGRATION_HOST" "$REDIS_INTEGRATION_PORT"
        ' 2>&1 | tee -a "$log"
        ;;
    *)
        echo "unknown env $env_id" >&2
        exit 2
        ;;
esac

echo "finished=$(date -u +%FT%TZ)" | tee -a "$log"
