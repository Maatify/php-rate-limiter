#!/usr/bin/env bash
# PROVENANCE: copy of investigations/flaky-160/phase3-k4-hand/harness/run.sh at 5202bf3, sha256 ad14bc1a2f18a163327d858fe8e5516769366624a57ff4ff8f8298b8e390da54, ADAPTED: V-HEAD is the S2 commit a45066a, and the ufilter, decision and decide arms are added; the difference is recorded in PROVENANCE-run.sh.diff. Delete this line and reverse that diff to get the original byte for byte.
# PROVENANCE: copy of investigations/flaky-160/phase2/harness/run.sh at d37f9a3, sha256 7fce1c0221aa7d5f65fc8e9741a6c2ed19ad04eb9c1a29cd501c718d8dc0dbd0, ADAPTED: v_head and redis_up are kept, the phase-2 arms are replaced by the phase-3 arms; the difference is recorded in PROVENANCE-run.sh.diff. Delete this line and reverse that diff to get the original byte for byte.
# #160 phase 3 runner (PLAN.md §4, §5, §13). One fresh Redis container per arm;
# redis.sh and lib.php are the phase-1 copies (investigations/flaky-160/harness/),
# used unchanged. No PHPUnit run: the probe k.php drives the store directly.
#
#   run.sh <arm> <out> <n>     arm: knat kheld kforced kforcedm kcal hnat ddet vclock overhead xread (k.php)
#   run.sh ufilter <out> <n>   n untapped PHPUnit runs of T-K4, T-HAND and the comparator (--filter), a fresh
#                              container each -> <out>/ufilter.jsonl (fix-a3 PLAN §11 LT-a3-07)
#   run.sh decision <out> <label> <tree> <ref> [phpunit args]
#                              the Integration and System suites of <tree> (V-HEAD against <ref>) once, a fresh
#                              container -> <out>/decision-<label>.junit.xml (fix-a3 PLAN §11 LT-a3-06)
#   run.sh decide <out>        junit.php decide over decision-main and decision-head -> <out>/decision.json
#   run.sh report <out>        k_report.php over <out> -> <out>/report.json
#   run.sh gate <out> k|h      exit 1 unless V-CLOCK (and for k the overhead) held in <out>/report.json (BC-11)
#
# Every arm appends one line to <out>/timings.jsonl (unit, n, rows, seconds: the cost measurement, LT-160D-07)
# and leaves <out>/<arm>.rows.jsonl.gz.
#
# V-HEAD: refuses a run unless src/, tests/, composer.json and phpunit.xml are
# byte-identical to the fix-a3 S2 commit (V_HEAD_REF) and clean (fix-a3 PLAN §11 LT-a3-08).
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
p1="$here/../../harness"
root="$(cd -- "$here/../../../.." && pwd)"
head_ref="${V_HEAD_REF:-a45066a}"
php=(env XDEBUG_MODE=off php -d memory_limit=1G)

arm="${1:?usage: see header}"
out="${2:?usage: see header}"
mkdir -p "$out"
out="$(cd -- "$out" && pwd)"

v_head() { # [$1 tree, $2 ref] (default: this checkout, V_HEAD_REF)
    local tree="${1:-$root}" ref="${2:-$head_ref}"
    if ! git -C "$tree" diff --quiet "$ref" -- src tests composer.json phpunit.xml \
        || [[ -n "$(git -C "$tree" status --porcelain -- src tests composer.json phpunit.xml)" ]]; then
        echo "V-HEAD FAIL: $tree differs from $ref under src/ tests/ composer.json phpunit.xml, or is not clean" >&2
        return 1
    fi
}

seconds() { perl -MTime::HiRes=time -e "printf q(%.6f), time"; }
timing() { # $1 unit, $2 n, $3 rows, $4 exit, $5 started, $6 ended
    awk -v u="$1" -v n="$2" -v r="$3" -v s="$4" -v a="$5" -v b="$6" \
        'BEGIN{printf "{\"unit\":\"%s\",\"n\":%d,\"rows\":%d,\"exit\":%d,\"seconds\":%.3f}\n", u, n, r, s, b-a}' | tee -a "$out/timings.jsonl"
}

# fix-a3 PLAN §11 LT-a3-07: the three tests the probe copies (RedisFullCapabilityStoreIntegrationTest L1833, L1871, L1963 at 5202bf3)
ufilter_tests='testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries|testConcurrentPreviousHandoffHasNoDoubleSeedAndGenerationMutationsHaveNoLostUpdates|testLegacyHandoffAndSameValueMutationAdvanceGenerationExactlyOnce'
# fix-a3 S1 (5d31685, a7f5aec): the test methods V-DECISION expects at the head only
s1_methods='testLegacyHandoffAppliesAndWritesTheReadExpiryAcrossCallerGaps,testConcurrentLegacyHandoffHasExactlyOneWinnerAcrossCallerGaps,testLegacyCurrentMutationWritesTheExpiryReadObserved,testLegacyTtlOnlyChangeBetweenReadAndMutateIsNotStaleAndKeepsTheReadExpiry,testLegacyMutationRejectsAMalformedSnapshotExpiryWithoutWriting'

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
    ufilter)
        # FLAKY160_UFILTER overrides the filter (D9 only: a filter that matches nothing must be invalid)
        n="${3:?n}"
        filter="${FLAKY160_UFILTER:-RedisFullCapabilityStoreIntegrationTest::($ufilter_tests)}"
        v_head
        started="$(seconds)"
        for ((i = 1; i <= n; i++)); do
            dir="$out/ufilter/$i"
            mkdir -p "$dir"
            run_started="$(seconds)"
            status=0
            port="$(redis_up "$dir/redis.state")" || status=$?
            if (( status == 0 )); then
                cache="$(mktemp -d)"
                REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" \
                    "${php[@]}" "$root/vendor/bin/phpunit" -c "$root/phpunit.xml" --do-not-cache-result --cache-directory "$cache" \
                    --log-junit "$dir/junit.xml" --filter "$filter" "$root/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php" \
                    > "$dir/phpunit.out" 2>&1 || status=$?
                rm -rf "$cache"
                bash "$p1/redis.sh" stop "$dir/redis.state"
            fi
            "${php[@]}" -r '
                require $argv[1];
                $suite = Flaky160D\junitSuite($argv[2]);
                echo json_encode(["id" => (int) $argv[3], "exit" => (int) $argv[4], "seconds" => round(microtime(true) - (float) $argv[5], 3), "suite" => $suite]
                    + Flaky160D\ufilterVerdict($suite, (int) $argv[4], 3)), "\n";' "$here/junit.php" "$dir/junit.xml" "$i" "$status" "$run_started" >> "$out/ufilter.jsonl"
            gzip -f -9 "$dir/phpunit.out"
            rm -f "$dir/redis.state"
        done
        timing ufilter "$n" "$(wc -l < "$out/ufilter.jsonl" | tr -d ' ')" 0 "$started" "$(seconds)"
        ;;
    decision)
        label="${3:?label}" tree="${4:?tree}" ref="${5:?ref}"
        shift 5
        v_head "$tree" "$ref"
        state="$out/decision-$label.redis.state"
        started="$(seconds)"
        port="$(redis_up "$state")"
        cache="$(mktemp -d)"
        status=0
        REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" \
            "${php[@]}" "$tree/vendor/bin/phpunit" -c "$tree/phpunit.xml" --testsuite Integration,System --exclude-group archive \
            --do-not-cache-result --cache-directory "$cache" --log-junit "$out/decision-$label.junit.xml" "$@" \
            > "$out/decision-$label.phpunit.out" 2>&1 || status=$?
        rm -rf "$cache"
        bash "$p1/redis.sh" stop "$state"
        rm -f "$state"
        echo "decision $label exit=$status: $(tail -n 3 "$out/decision-$label.phpunit.out" | tr '\n' ' ')"
        gzip -f -9 "$out/decision-$label.phpunit.out"
        timing "decision-$label" 1 1 "$status" "$started" "$(seconds)"
        ;;
    decide)
        status=0
        "${php[@]}" "$here/junit.php" decide "$out/decision-main.junit.xml" "$out/decision-head.junit.xml" "$s1_methods" > "$out/decision.json" || status=$?
        cat "$out/decision.json"
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
