#!/usr/bin/env bash
# #157 phase 1 runner (PLAN.md §5, §9). Every arm runs PHPUnit only through the
# pinned vendor/bin/phpunit, with a fresh Redis container per run.
#
#   run.sh reference <out>                       full core suite once, no tap -> prefix.xml + kref.json (V-ORD)
#   run.sh l         <out> <first> <count> [fault]  arm L: prefix suite through the tap (fault: detector demo only)
#   run.sh lshard    <out> <shard> <per>          arm L shard on E-CI: run ids (shard-1)*per+1 .. shard*per
#   run.sh lconfig   <out> <id> <config>          one tapped run of another configuration (detector demo only)
#   run.sh d         <out> <case> <first> <count> arm D: --filter the target, tap deletes that case's key
#   run.sh overhead  <out> <k>                    tap overhead, direct vs tapped (LT-157-04)
#   run.sh h         <out> <probe|forced> <m> <shard>  arm H at fe8775c; needs H_ROOT
#
# V-HEAD: refuses a run unless src/, tests/, composer.json and phpunit.xml are
# byte-identical to the tested head and clean (H: H_ROOT against fe8775c).
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
main_head="${V_HEAD_REF:-c8b8fd763ccbeb32875abb390624c64dd473d6bc}"
h_head=fe8775c206d7209fc62cefe0b6a94beb8c0206ce
# Xdebug, when the local php.ini loads it, is switched off so it adds no latency.
php=(env XDEBUG_MODE=off php -d memory_limit=1G)
phpunit=(env XDEBUG_MODE=off php -d memory_limit=1G "$root/vendor/bin/phpunit")
target_filter='/::testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication$/'

arm="${1:?usage: see header}"
out="${2:?usage: see header}"
mkdir -p "$out/runs"
out="$(cd -- "$out" && pwd)"

v_head() {
    local tree="$1" ref="$2"
    if ! git -C "$tree" diff --quiet "$ref" -- src tests composer.json phpunit.xml \
        || [[ -n "$(git -C "$tree" status --porcelain -- src tests composer.json phpunit.xml)" ]]; then
        echo "V-HEAD FAIL: $tree differs from $ref under src/ tests/ composer.json phpunit.xml, or is not clean" >&2
        return 1
    fi
}

redis_up() { # $1 state file -> prints port, records V-CONFIG
    local port
    port="$(bash "$here/redis.sh" start "$1")"
    "${php[@]}" -r '
        require $argv[1];
        $c = new Flaky157\Client("127.0.0.1", (int) $argv[2]);
        $server = $c->info("server");
        echo json_encode(["port" => (int) $argv[2], "redis_version" => $server["redis_version"] ?? null,
            "maxmemory" => $c->call("CONFIG", "GET", "maxmemory"), "maxmemory_policy" => $c->call("CONFIG", "GET", "maxmemory-policy"),
            "wall" => microtime(true)]), "\n";' "$here/lib.php" "$port" >> "$out/config.jsonl"
    echo "$port"
}

tap_up() { # $1 run dir, $2 upstream port, $3 mode, $4 fault -> prints tap port
    local dir="$1" waited=0
    rm -f "$dir/tap.port"
    "${php[@]}" "$here/tap.php" --autoload="$root/vendor/autoload.php" --upstream-port="$2" \
        --port-file="$dir/tap.port" --log="$dir/tap.jsonl" --mode="$3" ${4:+--fault="$4"} > "$dir/tap.out" 2> "$dir/tap.err" &
    echo $! > "$dir/tap.pid"
    until [[ -s "$dir/tap.port" ]]; do
        sleep 0.05
        waited=$((waited + 1))
        if (( waited > 200 )); then echo "tap did not start" >&2; return 1; fi
    done
    cat "$dir/tap.port"
}

tap_down() {
    local pid
    pid="$(cat "$1/tap.pid")"
    kill -TERM "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
}

# One tapped PHPUnit run. $1 arm label, $2 id, $3 tap mode, $4 fault, $5 case for D (or -), rest: phpunit args.
tapped_run() {
    local label="$1" id="$2" mode="$3" fault="$4" dcase="$5"
    shift 5
    local dir="$out/runs/$label-$id" port tport status=0 cache
    rm -rf "$dir"
    mkdir -p "$dir"
    # Explicit returns: errexit does not apply inside a function called from `||` (lshard).
    v_head "$root" "$main_head" || return 1
    if ! port="$(redis_up "$dir/redis.state")" || ! tport="$(tap_up "$dir" "$port" "$mode" "$fault")"; then
        [[ -s "$dir/tap.pid" ]] && tap_down "$dir"
        [[ -s "$dir/redis.state" ]] && bash "$here/redis.sh" stop "$dir/redis.state"
        printf '{"arm":"%s","id":%d,"valid":false,"v_frame":false,"v_port":false,"target":"missing","problems":["harness_error"],"findings":[],"reproduced":false,"other_failures":0}\n' "$label" "$id" | tee -a "$out/verdicts.jsonl"
        return 0
    fi
    cache="$(mktemp -d)"
    REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$tport" \
        "${phpunit[@]}" --do-not-cache-result --cache-directory "$cache" --log-junit "$dir/junit.xml" "$@" \
        > "$dir/phpunit.out" 2>&1 || status=$?
    rm -rf "$cache"
    tap_down "$dir"
    bash "$here/redis.sh" stop "$dir/redis.state"
    "${php[@]}" "$here/check_run.php" --arm="$label" --id="$id" --dir="$dir" --kref="$out/kref.json" \
        --exit="$status" --redis-port="$port" ${dcase:+--case="$dcase"} | tee -a "$out/verdicts.jsonl"
}

case "$arm" in
    reference)
        dir="$out/reference"
        rm -rf "$dir"
        mkdir -p "$dir"
        v_head "$root" "$main_head"
        port="$(redis_up "$dir/redis.state")"
        cache="$(mktemp -d)"
        status=0
        REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" \
            "${phpunit[@]}" -c "$root/phpunit.xml" --exclude-group archive --do-not-cache-result --cache-directory "$cache" \
            --log-junit "$out/reference.junit.xml" > "$dir/phpunit.out" 2>&1 || status=$?
        rm -rf "$cache"
        bash "$here/redis.sh" stop "$dir/redis.state"
        echo "reference exit=$status $(tail -n 3 "$dir/phpunit.out" | tr '\n' ' ')"
        (( status == 0 )) || { echo 'reference run did not pass; no prefix is written' >&2; exit 1; }
        "${php[@]}" "$here/prefix.php" --root="$root" --junit="$out/reference.junit.xml" --out-dir="$out"
        ;;
    l)
        first="${3:?first}" count="${4:?count}" fault="${5:-}"
        [[ -f "$out/prefix.xml" && -f "$out/kref.json" ]] || { echo 'run "reference" first' >&2; exit 1; }
        for ((i = first; i < first + count; i++)); do
            tapped_run l "$i" record "$fault" "" -c "$out/prefix.xml" --exclude-group archive
        done
        ;;
    lshard)
        # Shard s of a fixed-N arm L: run ids (s-1)*per+1 .. s*per (LT-157-03/05: no early stop).
        shard="${3:?shard}" per="${4:?per}"
        [[ -f "$out/prefix.xml" && -f "$out/kref.json" ]] || { echo 'run "reference" first' >&2; exit 1; }
        for ((i = (shard - 1) * per + 1; i <= shard * per; i++)); do
            tapped_run l "$i" record "" "" -c "$out/prefix.xml" --exclude-group archive || echo "run $i: harness error $?" >&2
        done
        ;;
    lconfig)
        id="${3:?id}" config="${4:?config}"
        tapped_run lconfig "$id" record "" "" -c "$config" --exclude-group archive
        ;;
    d)
        dcase="${3:?case}" first="${4:?first}" count="${5:?count}"
        for ((i = first; i < first + count; i++)); do
            tapped_run "d$dcase" "$i" "del:$dcase" "" "$dcase" -c "$root/phpunit.xml" --filter "$target_filter"
        done
        ;;
    overhead)
        k="${3:?k}"
        dir="$out/overhead"
        rm -rf "$dir"
        mkdir -p "$dir"
        v_head "$root" "$main_head"
        port="$(redis_up "$dir/redis.state")"
        tport="$(tap_up "$dir" "$port" record "")"
        "${php[@]}" "$here/overhead.php" --autoload="$root/vendor/autoload.php" --direct-port="$port" \
            --tap-port="$tport" --k="$k" --out="$out/overhead.json"
        tap_down "$dir"
        bash "$here/redis.sh" stop "$dir/redis.state"
        ;;
    h)
        hmode="${3:?probe|forced}" m="${4:?m}" shard="${5:?shard}"
        h_root="${H_ROOT:?H_ROOT must point at a checkout of $h_head with vendor/}"
        v_head "$h_root" "$h_head"
        dir="$out/h-$hmode-$shard"
        rm -rf "$dir"
        mkdir -p "$dir"
        port="$(redis_up "$dir/redis.state")"
        "${php[@]}" "$here/h_probe.php" --autoload="$h_root/vendor/autoload.php" --port="$port" \
            --mode="$hmode" --m="$m" --out="$dir/h.jsonl"
        bash "$here/redis.sh" stop "$dir/redis.state"
        ;;
    *)
        echo "unknown arm $arm" >&2
        exit 2
        ;;
esac
