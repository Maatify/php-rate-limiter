#!/usr/bin/env bash
# #160 phase 1 runner (PLAN.md §4, §5, §12). PHPUnit runs only through the
# pinned vendor/bin/phpunit, with a fresh Redis container per run and NO tap:
# PHPUnit connects to the container's published port directly.
#
#   run.sh reference <out>                     full core suite once -> prefix.xml, kref.json, vref.json (V-REF)
#   run.sh u         <out> <first> <count>      arm U runs <first> .. <first+count-1> (needs vref.json ok)
#   run.sh ushard    <out> <shard> <per>        arm U shard on E-CI: ids (shard-1)*per+1 .. shard*per
#   run.sh ucost     <out> <id>                 one untapped prefix run, labelled for the cost measurement
#   run.sh uconfig   <out> <id> <config>        one run of another configuration (detector demo only)
#   run.sh m         <out> <subarm> <n>          arm M sub-arm with its own container (m.php)
#
# V-HEAD: refuses a run unless src/, tests/, composer.json and phpunit.xml are
# byte-identical to main@8b79e9b and clean.
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
main_head="${V_HEAD_REF:-8b79e9b2e874f8216aadeaff034e5f248b49ea27}"
# Xdebug, when the local php.ini loads it, is switched off so it adds no latency.
php=(env XDEBUG_MODE=off php -d memory_limit=1G)
phpunit=(env XDEBUG_MODE=off php -d memory_limit=1G "$root/vendor/bin/phpunit")

arm="${1:?usage: see header}"
out="${2:?usage: see header}"
mkdir -p "$out/runs"
out="$(cd -- "$out" && pwd)"

# Wall-clock seconds (portable: BSD date has no %N).
now() { php -r 'printf("%.6F", microtime(true));'; }
since() { php -r 'printf("%.3F", microtime(true) - (float) $argv[1]);' "$1"; }

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

# One untapped PHPUnit run. $1 arm label, $2 id, rest: phpunit args.
untapped_run() {
    local label="$1" id="$2"
    shift 2
    local dir="$out/runs/$label-$id" port status=0 cache started
    rm -rf "$dir"
    mkdir -p "$dir"
    # Explicit returns: errexit does not apply inside a function called from `||` (ushard).
    v_head "$root" "$main_head" || return 1
    started="$(now)"
    if ! port="$(redis_up "$dir/redis.state")"; then
        [[ -s "$dir/redis.state" ]] && bash "$here/redis.sh" stop "$dir/redis.state"
        printf '{"arm":"%s","id":%d,"valid":false,"v_port":false,"v_ord":false,"problems":["harness_error"],"pooled":false,"any_failure":false,"six":{},"seventh":[],"wall_seconds":0}\n' "$label" "$id" | tee -a "$out/verdicts.jsonl"
        return 0
    fi
    cache="$(mktemp -d)"
    REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" \
        "${phpunit[@]}" --do-not-cache-result --cache-directory "$cache" --log-junit "$dir/junit.xml" "$@" \
        > "$dir/phpunit.out" 2>&1 || status=$?
    rm -rf "$cache"
    bash "$here/redis.sh" stop "$dir/redis.state"
    "${php[@]}" "$here/check_u.php" --arm="$label" --id="$id" --dir="$dir" --vref="$out/vref.json" \
        --exit="$status" --redis-port="$port" --wall="$(since "$started")" | tee -a "$out/verdicts.jsonl"
}

require_vref() {
    [[ -f "$out/prefix.xml" && -f "$out/vref.json" ]] || { echo 'run "reference" first' >&2; exit 1; }
    "${php[@]}" -r 'exit(json_decode(file_get_contents($argv[1]), true)["ok"] === true ? 0 : 1);' "$out/vref.json" \
        || { echo 'V-REF is not ok: U does not start (PLAN §4.3, LT-160-04)' >&2; exit 1; }
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
        started="$(now)"
        REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" \
            "${phpunit[@]}" -c "$root/phpunit.xml" --exclude-group archive --do-not-cache-result --cache-directory "$cache" \
            --log-junit "$out/reference.junit.xml" > "$dir/phpunit.out" 2>&1 || status=$?
        rm -rf "$cache"
        bash "$here/redis.sh" stop "$dir/redis.state"
        echo "reference exit=$status wall=$(since "$started") $(tail -n 3 "$dir/phpunit.out" | tr '\n' ' ')"
        (( status == 0 )) || { echo 'reference run did not pass; no prefix is written' >&2; exit 1; }
        "${php[@]}" "$here/prefix.php" --root="$root" --junit="$out/reference.junit.xml" --out-dir="$out"
        "${php[@]}" "$here/vref.php" --root="$root" --out-dir="$out"
        ;;
    u)
        first="${3:?first}" count="${4:?count}"
        require_vref
        for ((i = first; i < first + count; i++)); do
            untapped_run u "$i" -c "$out/prefix.xml" --exclude-group archive
        done
        ;;
    ushard)
        # Shard s of the fixed-N arm U: run ids (s-1)*per+1 .. s*per (no early stop).
        shard="${3:?shard}" per="${4:?per}"
        require_vref
        for ((i = (shard - 1) * per + 1; i <= shard * per; i++)); do
            untapped_run u "$i" -c "$out/prefix.xml" --exclude-group archive || echo "run $i: harness error $?" >&2
        done
        ;;
    ucost)
        id="${3:?id}"
        require_vref
        untapped_run ucost "$id" -c "$out/prefix.xml" --exclude-group archive
        ;;
    uconfig)
        id="${3:?id}" config="${4:?config}"
        untapped_run uconfig "$id" -c "$config" --exclude-group archive
        ;;
    m)
        subarm="${3:?subarm}" n="${4:?n}"
        v_head "$root" "$main_head"
        dir="$out/m-$subarm"
        rm -rf "$dir"
        mkdir -p "$dir"
        port="$(redis_up "$dir/redis.state")"
        status=0
        "${php[@]}" "$here/m.php" --root="$root" --port="$port" --subarm="$subarm" --n="$n" --out="$dir/rows.jsonl" \
            > "$dir/m.out" 2>&1 || status=$?
        bash "$here/redis.sh" stop "$dir/redis.state"
        tail -n 1 "$dir/m.out"
        exit "$status"
        ;;
    *)
        echo "unknown arm $arm" >&2
        exit 2
        ;;
esac
