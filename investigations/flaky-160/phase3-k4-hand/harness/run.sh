#!/usr/bin/env bash
# PROVENANCE: copy of investigations/flaky-160/phase2/harness/run.sh at d37f9a3, sha256 7fce1c0221aa7d5f65fc8e9741a6c2ed19ad04eb9c1a29cd501c718d8dc0dbd0, ADAPTED: v_head and redis_up are kept, the phase-2 arms are replaced by the phase-3 arms; the difference is recorded in PROVENANCE-run.sh.diff. Delete this line and reverse that diff to get the original byte for byte.
# #160 phase 3 runner (PLAN.md §4, §5, §13). One fresh Redis container per arm;
# redis.sh and lib.php are the phase-1 copies (investigations/flaky-160/harness/),
# used unchanged. No PHPUnit run: the probe k.php drives the store directly.
#
#   run.sh <arm> <out> <n>     arm: knat kheld kforced kforcedm kcal hnat ddet vclock overhead xread (k.php)
#   run.sh report <out>        k_report.php over <out> -> <out>/report.json
#   run.sh gate <out> k|h      exit 1 unless V-CLOCK (and for k the overhead) held in <out>/report.json (BC-11)
#
# Every arm appends one line to <out>/timings.jsonl (unit, n, rows, seconds: the cost measurement, LT-160D-07)
# and leaves <out>/<arm>.rows.jsonl.gz.
#
# V-HEAD: refuses a run unless src/, tests/, composer.json and phpunit.xml are
# byte-identical to main@d37f9a3 and clean.
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
p1="$here/../../harness"
root="$(cd -- "$here/../../../.." && pwd)"
head_ref="${V_HEAD_REF:-d37f9a3}"
php=(env XDEBUG_MODE=off php -d memory_limit=1G)

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

case "$arm" in
    knat|kheld|kforced|kforcedm|kcal|hnat|ddet|vclock|overhead|xread)
        n="${3:?n}"
        v_head
        state="$out/$arm.redis.state"
        started="$(perl -MTime::HiRes=time -e "printf q(%.6f), time")"
        port="$(redis_up "$state")"
        status=0
        "${php[@]}" "$here/k.php" --root="$root" --port="$port" --arm="$arm" --n="$n" --out="$out/$arm.rows.jsonl" || status=$?
        bash "$p1/redis.sh" stop "$state"
        rm -f "$state"
        ended="$(perl -MTime::HiRes=time -e "printf q(%.6f), time")"
        rows="$(wc -l < "$out/$arm.rows.jsonl" | tr -d ' ')"
        gzip -f -9 "$out/$arm.rows.jsonl"
        awk -v u="$arm" -v n="$n" -v r="$rows" -v s="$status" -v a="$started" -v b="$ended" \
            'BEGIN{printf "{\"unit\":\"%s\",\"n\":%d,\"rows\":%d,\"exit\":%d,\"seconds\":%.3f}\n", u, n, r, s, b-a}' | tee -a "$out/timings.jsonl"
        exit "$status"
        ;;
    report)
        "${php[@]}" "$here/k_report.php" "$out" > "$out/report.json"
        echo "$out/report.json"
        ;;
    gate)
        # PLAN §5, BC-11: the natural arms of a job start only if V-CLOCK (and, in k, the overhead) held in that job
        which="${3:?gate k|h}"
        "${php[@]}" -r '
            $r = json_decode(file_get_contents($argv[1]), true);
            $ok = ($r["vclock"]["K"]["identical"] ?? false) && ($r["vclock"]["H"]["identical"] ?? false);
            if ($argv[2] === "k") { $ok = $ok && ($r["overhead"]["holds_le_1_25"] ?? false); }
            echo json_encode(["gate" => $argv[2], "vclock" => array_map(fn($t) => $t["identical"], $r["vclock"] ?? []),
                "overhead_ratio" => $r["overhead"]["median_ratio"] ?? null, "holds" => $ok]), "\n";
            exit($ok ? 0 : 1);' "$out/report.json" "$which" | tee -a "$out/gate.jsonl"
        ;;
    *)
        echo "unknown arm $arm" >&2
        exit 2
        ;;
esac
