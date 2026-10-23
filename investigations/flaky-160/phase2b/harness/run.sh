#!/usr/bin/env bash
# #160 phase 2b runner (PLAN.md §3-§5, §8). PHPUnit runs only through the pinned vendor/bin/phpunit with
# the W1 bootstrap passed as --bootstrap (never in phpunit.xml). One fresh Redis container per run
# (redis.sh/lib.php are the phase-1 copies, unchanged). LOCAL_REDIS_PORT=<port> uses an already running
# local Redis instead (E-LOCAL smoke only, never a counted number).
#
#   run.sh vtransform <out>               V-TRANSFORM (hash pin, evaluated text = original + diff)
#   run.sh vclock2    <out>               V-CLOCK-2: fixed clock with / without the recorder
#   run.sh detectors  <out>               synthetic detectors (no Redis needed except the vclock/transform ones)
#   run.sh adet       <out> <n>           A-DET-1 / A-DET-0 / A-DET-b, n trials each
#   run.sh ref        <out>               V-REF: one --filter run with the bootstrap
#   run.sh anat       <out> <shard> <per> A-NAT shard: V-REF then runs (shard-1)*per+1 .. shard*per
#   run.sh overhead   <out> <spans> <calls>  with/without the bootstrap alternating, plus per-call cost
#
# V-HEAD: refuses a run unless src/, tests/, composer.json and phpunit.xml are byte-identical to
# main@2cf8565 and clean.
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
p1="$here/../../harness"
root="$(cd -- "$here/../../../.." && pwd)"
head_ref="${V_HEAD_REF:-2cf8565}"
php=(env XDEBUG_MODE=off php -d memory_limit=1G)
phpunit=(env XDEBUG_MODE=off php -d memory_limit=1G "$root/vendor/bin/phpunit")
test_file='tests/Integration/Redis/KnownDeviceAllowanceConcurrencyIntegrationTest.php'
test_filter='/::testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly$/'

arm="${1:?usage: see header}"
out="${2:?usage: see header}"
mkdir -p "$out"
out="$(cd -- "$out" && pwd)"

v_head() {
    if ! git -C "$root" diff --quiet "$head_ref" -- src tests composer.json phpunit.xml \
        || [[ -n "$(git -C "$root" status --porcelain -- src tests composer.json phpunit.xml)" ]]; then
        echo "V-HEAD FAIL: $root differs from $head_ref under src/ tests/ composer.json phpunit.xml, or is not clean" >&2
        return 1
    fi
}

redis_up() { # $1 state file -> prints port, records V-CONFIG
    local port
    if [[ -n "${LOCAL_REDIS_PORT:-}" ]]; then echo "$LOCAL_REDIS_PORT"; return 0; fi
    port="$(bash "$p1/redis.sh" start "$1")"
    "${php[@]}" -r '
        require $argv[1];
        $c = new Flaky157\Client("127.0.0.1", (int) $argv[2]);
        $server = $c->info("server");
        echo json_encode(["port" => (int) $argv[2], "redis_version" => $server["redis_version"] ?? null,
            "maxmemory" => $c->call("CONFIG", "GET", "maxmemory"), "maxmemory_policy" => $c->call("CONFIG", "GET", "maxmemory-policy"),
            "wall" => microtime(true)]), "\n";' "$p1/lib.php" "$port" >> "$out/config.jsonl"
    echo "$port"
}
redis_down() { [[ -n "${LOCAL_REDIS_PORT:-}" ]] || bash "$p1/redis.sh" stop "$1"; }

# One PHPUnit run of the A test. $1 dir, $2 port, $3 recorded(1/0). Sets status, wall.
phpunit_run() {
    local dir="$1" port="$2" rec="$3" cache started ended
    cache="$(mktemp -d)"
    mkdir -p "$dir/rec"
    status=0
    started="$(perl -MTime::HiRes=time -e "printf q(%.6f), time")"
    if [[ "$rec" == 1 ]]; then
        REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" FLAKY160B_DIR="$dir/rec" \
            "${phpunit[@]}" --bootstrap "$here/w1_bootstrap.php" -c "$root/phpunit.xml" --do-not-cache-result --cache-directory "$cache" \
            --log-junit "$dir/junit.xml" --filter "$test_filter" "$root/$test_file" > "$dir/phpunit.out" 2>&1 || status=$?
    else
        REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" \
            "${phpunit[@]}" -c "$root/phpunit.xml" --do-not-cache-result --cache-directory "$cache" \
            --log-junit "$dir/junit.xml" --filter "$test_filter" "$root/$test_file" > "$dir/phpunit.out" 2>&1 || status=$?
    fi
    ended="$(perl -MTime::HiRes=time -e "printf q(%.6f), time")"
    wall="$(awk -v a="$started" -v b="$ended" 'BEGIN{printf "%.3f", b-a}')"
    rm -rf "$cache"
}

# One recorded A run. $1 label, $2 id. Prints the verdict line (also appended to verdicts.jsonl).
a_run() {
    local label="$1" id="$2" port line dir
    dir="$out/runs/$label-$id"
    rm -rf "$dir"; mkdir -p "$dir"
    v_head || return 1
    if ! port="$(redis_up "$dir/redis.state")"; then
        line="$(printf '{"arm":"%s","id":%d,"valid":false,"problems":["harness_error"]}' "$label" "$id")"
    else
        phpunit_run "$dir" "$port" 1
        redis_down "$dir/redis.state"
        cp "$dir/phpunit.out" "$dir/rec/phpunit.out"
        line="$("${php[@]}" "$here/anat_check.php" --arm="$label" --id="$id" --dir="$dir/rec" --exit="$status" --wall="$wall" )"
    fi
    printf '%s\n' "$line" | tee -a "$out/verdicts.jsonl"
    # A valid passing run keeps its verdict line; a failing or invalid run keeps everything.
    if [[ "$line" == *'"valid":true'* && "$line" == *'"test":"pass"'* && -z "${KEEP_ALL:-}" ]]; then rm -rf "$dir"; fi
}

case "$arm" in
    vtransform)
        v_head
        d="$out/vtransform"; rm -rf "$d"; mkdir -p "$d"
        FLAKY160B_DIR="$d" "${php[@]}" -r 'require $argv[1]; class_exists("Maatify\\RateLimiter\\Repository\\Redis\\RedisFullCapabilityStore");' "$here/w1_bootstrap.php"
        "${php[@]}" "$here/vtransform.php" --root="$root" --dir="$d" | tee "$out/vtransform.json"
        ;;
    vclock2)
        v_head
        d="$out/vclock2"; rm -rf "$d"; mkdir -p "$d"
        port="$(redis_up "$d/redis.state")"
        "${php[@]}" "$here/vclock2.php" --root="$root" --port="$port" --dir="$d" | tee "$out/vclock2.json"
        redis_down "$d/redis.state"
        ;;
    detectors)
        bash "$here/detectors.sh" "$out"
        ;;
    adet)
        n="${3:?n}"
        v_head
        d="$out/adet"; rm -rf "$d"; mkdir -p "$d"
        port="$(redis_up "$d/redis.state")"
        FLAKY160B_DIR="$d" "${php[@]}" "$here/adet.php" --root="$root" --port="$port" --n="$n" --out="$out/adet.rows.jsonl"
        redis_down "$d/redis.state"
        ;;
    ref)
        a_run ref 1
        ;;
    anat)
        shard="${3:?shard}" per="${4:?per}"
        # V-REF (enforced): a pass, or a failure at L136 = "failure on the phenomenon" (the shard continues);
        # any other failure stops the shard.
        a_run vref "$shard" > "$out/vref.line"
        if [[ "$(cat "$out/vref.line")" == *'"valid":false'* ]] && [[ "$(cat "$out/vref.line")" != *'"failure_at_l136":true'* ]]; then
            echo "V-REF failed for a reason other than L136: shard stopped" >&2; exit 4
        fi
        for ((i = (shard - 1) * per + 1; i <= shard * per; i++)); do
            a_run anat "$i" || echo "run $i: harness error $?" >&2
        done
        ;;
    overhead)
        spans="${3:?spans}" calls="${4:?calls}"
        v_head
        d="$out/overhead"; rm -rf "$d"; mkdir -p "$d"
        port="$(redis_up "$d/redis.state")"
        for ((s = 1; s <= spans; s++)); do
            for mode in without with; do
                rm -rf "$d/$mode-$s"; mkdir -p "$d/$mode-$s"
                if [[ "$mode" == with ]]; then phpunit_run "$d/$mode-$s" "$port" 1; else phpunit_run "$d/$mode-$s" "$port" 0; fi
                printf '{"mode":"%s","span":%d,"wall_seconds":%s,"status":%d}\n' "$mode" "$s" "$wall" "$status" >> "$out/overhead-spans.jsonl"
                rm -rf "$d/$mode-$s"
            done
        done
        "${php[@]}" "$here/overhead.php" --root="$root" --port="$port" --calls="$calls" --dir="$d" > "$out/overhead-calls.json"
        redis_down "$d/redis.state"
        "${php[@]}" -r '
            $s = ["without" => [], "with" => []];
            foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $l) { $r = json_decode($l, true); $s[$r["mode"]][] = $r["wall_seconds"]; }
            $med = static function (array $x): float { sort($x); $n = count($x); return $n % 2 ? $x[intdiv($n, 2)] : ($x[$n / 2 - 1] + $x[$n / 2]) / 2; };
            $m = ["without" => $med($s["without"]), "with" => $med($s["with"])];
            echo json_encode(["spans_wall_seconds" => $s, "median" => $m, "ratio" => round($m["with"] / $m["without"], 3), "threshold" => 1.25,
                "within" => $m["with"] / $m["without"] <= 1.25, "calls" => json_decode(file_get_contents($argv[2]), true)], JSON_PRETTY_PRINT), "\n";' \
            "$out/overhead-spans.jsonl" "$out/overhead-calls.json" > "$out/overhead.json"
        cat "$out/overhead.json"
        ;;
    *)
        echo "unknown arm $arm" >&2
        exit 2
        ;;
esac
