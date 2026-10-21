#!/usr/bin/env bash
# #153 LT-153-07 runner (PLAN.md §5.6, §12, §13). Usage: run.sh E-SAME | E-DOCKER
# Dry runs only (harness-time note): MIX_SCALE (< 1), MIX_GROUPS (comma list) and MIX_OUT;
# the scale is written into every cell's meta and report.php refuses a cell whose scale is not 1.
# V-1: refuses unless src/, tests/ and composer.json are byte-identical to the
# baseline and clean. Writes expected/<env>/ and logs/<env>.log. Cells of one
# group run concurrently (one process per cell); groups run in the fixed order below.
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

out="${MIX_OUT:-$study/expected}/$env_id"
scale="${MIX_SCALE:-1}"
groups=",${MIX_GROUPS:-probes-a,probes-b,streams-atk,streams-ben-ra,docker},"
rm -rf "$out"
log_dir="${MIX_OUT:-$study/logs}"
mkdir -p "$out" "$log_dir"
log="$log_dir/$env_id.log"
# Xdebug is loaded by the local php.ini; mode off removes its overhead from the timings.
php=(env XDEBUG_MODE=off php -d memory_limit=1G)
lib_blob="$(git -C "$root" rev-parse HEAD:investigations/clock-skew-153/harness/lib.php)"

{
    echo "env=$env_id baseline=$baseline head=$(git -C "$root" rev-parse HEAD) lib.php=$lib_blob started=$(date -u +%FT%TZ)"
    echo "php=$("${php[@]}" -r 'echo PHP_VERSION;') os=$(uname -sr)"
} | tee "$log"

both=(-60 -10 -2 -1 -0.5 -0.25 +0.25 +0.5 +1 +2 +10 +60)
small=(-2 -1 -0.5 -0.25 +0.25 +0.5 +1 +2)

# One group: each argument is "family policy sigma pairs"; all cells concurrently.
group() {
    local host="$1" port="$2" name="$3"
    shift 3
    if [[ "$groups" != *",$name,"* ]]; then
        echo "group $name: skipped (MIX_GROUPS)"
        return 0
    fi
    echo "group $name: $# cells started=$(date -u +%FT%TZ)"
    local pids=() failed=0 spec
    for spec in "$@"; do
        read -r family policy sigma pairs <<<"$spec"
        "${php[@]}" "$here/run.php" "$root" "$env_id" "$host" "$port" "$out" "$family" "$policy" "$sigma" "$pairs" "$scale" &
        pids+=("$!")
    done
    for pid in "${pids[@]}"; do
        wait "$pid" || failed=$((failed + 1))
    done
    echo "group $name: finished=$(date -u +%FT%TZ) failed=$failed"
    [[ "$failed" -eq 0 ]]
}

matrix() {
    local host="$1" port="$2" cells s p
    "${php[@]}" "$here/offset.php" "$root" "$host" "$port" "$out/offset-before.jsonl"
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
        cells=()
        for p in LOGIN-NA CYCLE-NA; do
            for s in AA "${small[@]}"; do
                cells+=("S-ATK $p $s 200")
            done
        done
        group "$host" "$port" streams-atk "${cells[@]}"
        cells=()
        for p in LOGIN-NA CYCLE-NA; do
            for s in AA "${small[@]}"; do
                cells+=("S-BEN $p $s 200" "S-RA $p $s 200")
            done
        done
        for s in AA -2 -1 +1 +2; do
            cells+=("S-BEN LOGIN $s 200" "S-RA LOGIN $s 200")
        done
        group "$host" "$port" streams-ben-ra "${cells[@]}"
    else
        cells=()
        for s in -1 +1; do
            cells+=("P-B LOGIN-NA $s 200" "P-E2 LOGIN-NA $s 200" "P-E2 CYCLE-NA $s 200"
                "P-RA1 LOGIN-NA $s 100" "P-RA2 LOGIN-NA $s 100" "P-RA2 CYCLE-NA $s 100")
        done
        group "$host" "$port" docker "${cells[@]}"
    fi
    "${php[@]}" "$here/offset.php" "$root" "$host" "$port" "$out/offset-after.jsonl"
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
        export env_id here root out scale groups
        bash "$root/scripts/ci/run-with-redis-service.sh" bash -c '
            set -euo pipefail
            php=(env XDEBUG_MODE=off php -d memory_limit=1G)
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
