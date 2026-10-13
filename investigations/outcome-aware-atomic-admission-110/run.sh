#!/usr/bin/env bash
# #110 Outcome-Aware Atomic Admission — reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/outcome-aware-atomic-admission-110/.
#
#   bash investigations/outcome-aware-atomic-admission-110/run.sh                  # everything, diffed against expected/
#   UPDATE=1 bash investigations/outcome-aware-atomic-admission-110/run.sh         # regenerate expected/ (REVIEW the git diff!)
#   REDIS_SPOTCHECK=0 bash .../run.sh                                              # skip real Redis (Redis-backed gates become UNPROVEN)
#   OAAA_REDIS_FIRST_PORT=6700 bash .../run.sh                                     # first port to try for the instances run.sh starts
#
# Runtime levels (never merged into one result):
#   S0   = S0_RUNTIME_OBSERVED            the repository checkout, UNPATCHED (pipeline + Redis adapter sha-256 asserted by every scenario)
#   OAAA = CANDIDATE_EXPERIMENTAL_RUNTIME an isolated `git archive` of the pinned baseline + patches/oaaa-candidate.patch (build_candidate.sh),
#                                         selected at run time by env OAAA_ARM=OAAA (the patched pipeline refuses to run without it)
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="${here:?}/results/actual"
work="${here:?}/results/work"
expected="${here:?}/expected"
BASE_SHA=72f6dea8ab0729e63492b622e682e334c527c966
GATES=(E4 E5 E6 E1 E2 E3 F1 F2 O1 O2 O3 O4 O5 O6 O7a O7b O7c O7d O8 O9 O10 DECISION)
mkdir -p "$actual" "$work" "$expected"
find "${actual:?}" -maxdepth 1 -type f \( -name '*.jsonl' -o -name '*.diff' -o -name '*.stderr' -o -name '*.txt' -o -name 'summary.md' -o -name 'report.out' \) -delete
rm -rf "${actual:?}"/redis-* "${actual:?}"/failclosed-* "${actual:?}"/gatecheck-*

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
    echo "no vendor/ in $repo — run 'composer install' first." >&2
    exit 2
fi
status=0
report() { printf '%-64s %s\n' "$1" "$2"; }

# ---- 0. baseline + provenance ------------------------------------------------------------------------------------------------------
{
    echo "baseline_expected: $BASE_SHA"
    echo "repo_head: $(git -C "$repo" rev-parse HEAD)"
    echo "pipeline_sha256_repo: $(shasum -a 256 "$repo/src/Service/EvaluationPipeline.php" | cut -d' ' -f1)"
    echo "redis_adapter_sha256_repo: $(shasum -a 256 "$repo/src/Repository/Redis/RedisFullCapabilityStore.php" | cut -d' ' -f1)"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "composer: $(composer --version 2>/dev/null | head -1 || echo n/a)"
    echo "redis: $(redis-server --version 2>/dev/null || echo n/a)"
} >"$actual/versions.txt"
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { report "baseline_is_ancestor" "FAIL ($BASE_SHA is not an ancestor of HEAD)"; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src tests composer.json docs 2>/dev/null)" ]]; then
    report "baseline_src_identical" "FAIL (src/, tests/, docs/ or composer.json differ from $BASE_SHA)"
    exit 4
fi
if [[ -n "$(git -C "$repo" status --porcelain -- src tests composer.json docs)" ]]; then
    report "baseline_src_clean" "FAIL (uncommitted changes under src/, tests/, docs/ or composer.json)"
    exit 4
fi
report "baseline_src_identical" "PASS (src/, tests/, docs/, composer.json identical to $BASE_SHA; clean)"

# ---- 1. isolated candidate runtime --------------------------------------------------------------------------------------------------
cand="$work/candidate"
case "$cand" in
    "$here/results/work/"*) rm -rf "${cand:?}" ;;
    *) echo "refusing to remove $cand" >&2; exit 2 ;;
esac
bash "$here/build_candidate.sh" "$cand" >/dev/null
cp "$cand/BUILD_INFO.txt" "$actual/candidate_build_info.txt"
report "candidate_build" "PASS (pipeline_sha256_after $(grep pipeline_sha256_after "$cand/BUILD_INFO.txt" | cut -d' ' -f2 | cut -c1-16)...)"

arms=(S0 OAAA)
run_arm() { # arm scenario out err [extra env...]
    local a=$1 sc=$2 o=$3 e=$4
    shift 4
    if [[ $a == S0 ]]; then
        env -u OAAA_ARM -u GNH_ARM -u OQC_ARM -u WSB_CANDIDATE LAB_ROOT="$repo" "$@" php "$here/scenarios/$sc.php" >"$o" 2>"$e"
    else
        env -u GNH_ARM -u OQC_ARM -u WSB_CANDIDATE OAAA_ARM=OAAA LAB_ROOT="$cand" "$@" php "$here/scenarios/$sc.php" >"$o" 2>"$e"
    fi
}
compare() { # name
    local name=$1
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$actual/$name.jsonl" "$expected/$name.jsonl"
        report "$name" "UPDATED"
    elif diff -u "$expected/$name.jsonl" "$actual/$name.jsonl" >"$actual/$name.diff" 2>&1; then
        rm -f "${actual:?}/$name.diff"
        report "$name" "PASS ($(wc -l <"$actual/$name.jsonl" | tr -d ' ') lines identical)"
    else
        report "$name" "FAIL (see results/actual/$name.diff)"
        status=1
    fi
}

# ---- 2. reference-store scenarios, both arms (the two arms of one scenario run concurrently; their outputs are independent) -----------
for sc in a01_matrix a02_lifecycle a03_rotation_ipv6 a04_failure_rollback_bounds; do
    pids=()
    for a in "${arms[@]}"; do
        run_arm "$a" "$sc" "$actual/$sc.$a.jsonl" "$actual/$sc.$a.stderr" &
        pids+=($!)
    done
    for i in "${!arms[@]}"; do
        a=${arms[$i]}
        name="$sc.$a"
        if ! wait "${pids[$i]}"; then
            report "$name" "FAIL (non-zero exit; see results/actual/$name.jsonl / .stderr)"
            status=1
            continue
        fi
        if [[ -s "$actual/$name.stderr" ]]; then
            report "$name" "FAIL (stderr not empty)"
            status=1
            continue
        fi
        rm -f "${actual:?}/$name.stderr"
        compare "$name"
    done
done

# ---- 3. real Redis (instances owned and proven by this script; PR #103 F103-01 safety model) ---------------------------------------------
free_port() { local p=$1; while (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; do p=$((p + 1)); done; echo "$p"; }
start_redis() { # name port -> REDIS_PID_VALUE REDIS_DIR_VALUE
    local name=$1 port=$2
    REDIS_DIR_VALUE="$actual/redis-$name"
    rm -rf "${REDIS_DIR_VALUE:?}"
    mkdir -p "$REDIS_DIR_VALUE"
    redis-server --port "$port" --bind 127.0.0.1 --protected-mode yes --save "" --appendonly no \
        --dir "$REDIS_DIR_VALUE" --daemonize yes --pidfile "$REDIS_DIR_VALUE/redis.pid" >/dev/null
    local i=0
    until [[ -s "$REDIS_DIR_VALUE/redis.pid" ]] && redis-cli -h 127.0.0.1 -p "$port" ping >/dev/null 2>&1; do
        i=$((i + 1))
        [[ $i -gt 50 ]] && { echo "redis $name did not start" >&2; return 1; }
        sleep 0.2
    done
    REDIS_PID_VALUE=$(tr -d '[:space:]' <"$REDIS_DIR_VALUE/redis.pid")
}
stop_redis() { local pid=${1:-} i=0; [[ -n "$pid" ]] || return 0; kill "$pid" 2>/dev/null || return 0; while kill -0 "$pid" 2>/dev/null && [[ $i -lt 50 ]]; do i=$((i + 1)); sleep 0.1; done; return 0; }

echo SKIPPED >"$actual/redis_status.txt"
if [[ "${REDIS_SPOTCHECK:-1}" == "1" ]]; then
    if ! command -v redis-server >/dev/null 2>&1 || ! command -v redis-cli >/dev/null 2>&1; then
        report "a05 / a06 / a07" "SKIPPED (redis not installed) -> Redis-backed gates UNPROVEN"
    else
        own_port=$(free_port "${OAAA_REDIS_FIRST_PORT:-6531}")
        decoy_port=$(free_port $((own_port + 1)))
        start_redis own "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
        start_redis decoy "$decoy_port"; decoy_pid=$REDIS_PID_VALUE; decoy_dir=$REDIS_DIR_VALUE
        trap 'stop_redis "${own_pid:-}"; stop_redis "${decoy_pid:-}"' EXIT
        redis-cli -h 127.0.0.1 -p "$decoy_port" set decoy:sentinel precious >/dev/null
        safety=PASS
        set +e
        # (1) a foreign server selected through inherited REDIS_HOST/REDIS_PORT, (2) wrong pid, (3) wrong dir, (4) non-empty server with matching pid+dir
        env -u OAAA_ARM REDIS_HOST=127.0.0.1 REDIS_PORT=$decoy_port LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc1=$?
        env -u OAAA_ARM WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$own_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc2=$?
        env -u OAAA_ARM WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc3=$?
        env -u OAAA_ARM WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$decoy_dir LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc4=$?
        set -e
        [[ $rc1 -eq 3 && $rc2 -eq 3 && $rc3 -eq 3 && $rc4 -eq 3 ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" get decoy:sentinel)" == "precious" ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" dbsize | tr -dc '0-9')" == "1" ]] || safety=FAIL
        report "redis_safety" "$safety (foreign/mismatched/non-empty server refused rc=$rc1,$rc2,$rc3,$rc4; decoy sentinel intact)"
        [[ $safety == PASS ]] || status=1
        if [[ $safety == PASS ]]; then
            echo RAN >"$actual/redis_status.txt"
            for sc in a05_redis a06_concurrency a07_host_probe; do
                for a in "${arms[@]}"; do
                    name="$sc.$a"
                    stop_redis "$own_pid"
                    own_port=$(free_port "$own_port")
                    start_redis "own-$sc-$a" "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
                    run_arm "$a" "$sc" "$actual/$name.jsonl" "$actual/$name.stderr" REDIS_HOST=198.51.100.1 REDIS_PORT=1 WSB_REDIS_PORT="$own_port" WSB_REDIS_PID="$own_pid" WSB_REDIS_DIR="$own_dir" || true
                    if [[ -s "$actual/$name.stderr" ]]; then
                        report "$name" "FAIL (stderr: results/actual/$name.stderr)"
                        status=1
                    else
                        rm -f "${actual:?}/$name.stderr"
                        compare "$name"
                    fi
                done
            done
            stop_redis "$own_pid"
        fi
    fi
fi

# ---- 4. gates + summary -------------------------------------------------------------------------------------------------------------
gates_complete() { # gates-file -> 0 when every pre-registered gate appears exactly once with a known status
    local f=$1 g n
    for g in "${GATES[@]}"; do
        n=$(grep -c "\"gate\":\"$g\"," "$f" 2>/dev/null || true)
        [[ "$n" == "1" ]] || return 1
    done
    [[ "$(wc -l <"$f" | tr -d ' ')" == "${#GATES[@]}" ]] || return 1
    ! grep -v -E '"status":"(PASS|FAIL|UNPROVEN|MEASURED|NO-GO|GO_FOR_OWNER_RISK_REVIEW)"' "$f" | grep -q .
}
if php "$here/report.php" "$actual" "$actual" >"$actual/report.out" 2>"$actual/report.stderr" && [[ ! -s "$actual/report.stderr" ]]; then
    if gates_complete "$actual/gates.jsonl"; then report "gates_complete" "PASS (${#GATES[@]} gate rows, each exactly once)"; else report "gates_complete" "FAIL (a pre-registered gate is missing, duplicated or has an unknown status)"; status=1; fi
    compare "gates"
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$actual/summary.md" "$expected/summary.md"
        report "summary.md" "UPDATED"
    elif diff -q "$expected/summary.md" "$actual/summary.md" >/dev/null 2>&1; then
        report "summary.md" "PASS (identical)"
    else
        report "summary.md" "FAIL (differs from expected/summary.md)"
        status=1
    fi
    report "decision (pre-registered rule)" "$(grep -o '"gate":"DECISION","group":"rule","status":"[^"]*"' "$actual/gates.jsonl" | sed 's/.*"status":"//; s/"$//')"
else
    report "gates" "FAIL (report.php; see results/actual/report.stderr / report.out)"
    status=1
fi

# ---- 5. self-tests: provenance, pinning and fail-closed evidence must refuse damage -----------------------------------------------------
selftest_exit() { # name expected-exit command...
    local name=$1 want=$2 rc
    shift 2
    set +e
    "$@" >"$actual/selftest_$name.out" 2>"$actual/selftest_$name.err"
    rc=$?
    set -e
    rm -f "$actual/selftest_$name.out" "$actual/selftest_$name.err"
    if [[ $rc -eq $want ]]; then report "selftest_$name" "PASS (refused with exit $rc)"; else report "selftest_$name" "FAIL (expected exit $want, got $rc)"; status=1; fi
}
selftest_exit candidate_arm_on_repo_tree 4 env OAAA_ARM=OAAA LAB_ROOT="$repo" php "$here/scenarios/a02_lifecycle.php"
selftest_exit s0_arm_on_candidate_tree 4 env -u OAAA_ARM LAB_ROOT="$cand" php "$here/scenarios/a02_lifecycle.php"
selftest_exit unknown_arm 4 env OAAA_ARM=GNH5 LAB_ROOT="$cand" php "$here/scenarios/a02_lifecycle.php"
selftest_exit foreign_arm_variable 4 env OAAA_ARM=OAAA GNH_ARM=GNH5 LAB_ROOT="$cand" php "$here/scenarios/a02_lifecycle.php"
# the patched pipeline itself refuses to run without OAAA_ARM=OAAA (it never acts as S0): harness provenance bypassed on purpose
selftest_exit patched_tree_refuses_s0_label 255 env -u OAAA_ARM LAB_ROOT="$cand" php -d display_errors=stderr -r 'require $argv[1]; oaaaLab();' "$here/lib/oaaa.php"
drift="$work/drifted.patch"
sed 's/OAAA_SCOPE_EPOCH_UNITS = 2/OAAA_SCOPE_EPOCH_UNITS = 3/' "$here/patches/oaaa-candidate.patch" >"$drift"
selftest_exit drifted_patch_hash_pin 6 env OAAA_TEST_PATCH_FILE="$drift" bash "$here/build_candidate.sh" "$work/drift-out"
rm -rf "${work:?}/drift-out"
perl -pe 'if (!$d && /^-[^-]/) { s/$/ DRIFT/; $d = 1 }' "$here/patches/oaaa-candidate.patch" >"$drift"   # a removed line no longer matches the target
selftest_exit drifted_patch_does_not_apply 4 env OAAA_TEST_PATCH_FILE="$drift" OAAA_TEST_SKIP_PIN=1 bash "$here/build_candidate.sh" "$work/drift-out"
rm -rf "${work:?}/drift-out" "$drift"
selftest_exit wrong_baseline_target_hash 3 env OAAA_TEST_EXPECT_HASH=0000000000000000000000000000000000000000000000000000000000000000 bash "$here/build_candidate.sh" "$work/drift-out"
rm -rf "${work:?}/drift-out"
selftest_exit strict_mode_makes_candidate_findings_fatal 1 env OAAA_ARM=OAAA OAAA_STRICT=1 OAAA_SELFTEST_BREAK=1 LAB_ROOT="$cand" php "$here/scenarios/a02_lifecycle.php"
selftest_exit s0_invariant_violation_is_fatal 1 env -u OAAA_ARM OAAA_SELFTEST_BREAK=1 LAB_ROOT="$repo" php "$here/scenarios/a02_lifecycle.php"

# fail-closed evidence: delete / damage representative evidence and require report.php to refuse (exit 5) and write no PASS gate
mutate() { # file php-expression-on-$r (returns the modified row, or null to delete it)
    php -r '
        [$_, $file, $expr] = $argv;
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            $r = eval("return " . $expr . ";");
            if ($r !== null) { $out[] = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        }
        file_put_contents($file, implode("\n", $out) . "\n");' "$1" "$2"
}
fc_check() { # name mutation-command-on-copy
    local name=$1 dir="$actual/failclosed-$1" rc
    rm -rf "${dir:?}"
    mkdir -p "$dir"
    cp "$actual"/*.jsonl "$actual"/redis_status.txt "$dir"/ 2>/dev/null || true
    rm -f "$dir"/gates.jsonl
    eval "$2"
    set +e
    php "$here/report.php" "$dir" "$dir" >"$dir/report.out" 2>"$dir/report.stderr"
    rc=$?
    set -e
    if [[ $rc -eq 5 ]] && ! grep -q '"status":"PASS"' "$dir/gates.jsonl" 2>/dev/null; then
        report "selftest_failclosed_$name" "PASS (report.php exit 5; no PASS gate written)"
    else
        report "selftest_failclosed_$name" "FAIL (exit $rc; expected 5 with no PASS gate)"
        status=1
    fi
    rm -rf "${dir:?}"
}
fc_check missing_s0_matrix 'rm -f "$dir/a01_matrix.S0.jsonl"'
fc_check missing_oaaa_matrix 'rm -f "$dir/a01_matrix.OAAA.jsonl"'
fc_check missing_lifecycle 'rm -f "$dir/a02_lifecycle.OAAA.jsonl"'
fc_check missing_rotation 'rm -f "$dir/a03_rotation_ipv6.S0.jsonl"'
fc_check missing_bounds 'rm -f "$dir/a04_failure_rollback_bounds.OAAA.jsonl"'
fc_check empty_file ': >"$dir/a02_lifecycle.S0.jsonl"'
fc_check corrupted_json_line 'printf "{not json\n" >>"$dir/a03_rotation_ipv6.OAAA.jsonl"'
fc_check missing_scenario_cell 'mutate "$dir/a01_matrix.OAAA.jsonl" "(\$r[\"s\"] === \"A01.matrix\" && \$r[\"stream\"] === \"D2\" && \$r[\"shape\"] === \"login\" && \$r[\"flow\"] === \"pre+out\") ? null : \$r"'
fc_check matrix_field_removed 'mutate "$dir/a01_matrix.S0.jsonl" "(\$r[\"s\"] === \"A01.matrix\" && \$r[\"stream\"] === \"SP1n\") ? array_diff_key(\$r, [\"attackReachedBy60\" => 1]) : \$r"'
fc_check missing_assertion 'mutate "$dir/a02_lifecycle.OAAA.jsonl" "str_starts_with(\$r[\"assert\"] ?? \"\", \"L7 \") ? null : \$r"'
fc_check missing_data_row 'mutate "$dir/a04_failure_rollback_bounds.OAAA.jsonl" "(\$r[\"s\"] === \"A04.R3.s0-family-state-parity\") ? null : \$r"'
fc_check unreproduced_s0_invariant 'mutate "$dir/a01_matrix.S0.jsonl" "isset(\$r[\"assert\"]) && str_starts_with(\$r[\"assert\"], \"S0 D2 \") ? [\"ok\" => false] + \$r : \$r"'
fc_check unreproduced_s0_cell 'mutate "$dir/a01_matrix.S0.jsonl" "(\$r[\"s\"] === \"A01.matrix\" && \$r[\"stream\"] === \"ABA_A\" && \$r[\"shape\"] === \"login\" && \$r[\"flow\"] === \"pre+out\") ? [\"attackReachedVerification\" => 9] + \$r : \$r"'
fc_check wrong_s0_runtime_sha 'mutate "$dir/a02_lifecycle.S0.jsonl" "(\$r[\"s\"] === \"A02.provenance\") ? [\"pipelineSha256\" => str_repeat(\"0\", 64)] + \$r : \$r"'
fc_check wrong_candidate_runtime_sha 'mutate "$dir/a01_matrix.OAAA.jsonl" "(\$r[\"s\"] === \"A01.provenance\") ? [\"pipelineSha256\" => \"4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc\"] + \$r : \$r"'
fc_check wrong_redis_adapter_sha 'mutate "$dir/a05_redis.OAAA.jsonl" "(\$r[\"s\"] === \"A05.provenance\") ? [\"redisAdapterSha256\" => \"520574d4b0758470c0fc36ec304001825cf20a183e28bf6a999bcd41d54297a4\"] + \$r : \$r"'
fc_check candidate_run_labelled_s0 'cp "$dir/a02_lifecycle.OAAA.jsonl" "$dir/a02_lifecycle.S0.jsonl" && sed -i.bak "s/\"arm\":\"OAAA\"/\"arm\":\"S0\"/g" "$dir/a02_lifecycle.S0.jsonl" && rm -f "$dir/a02_lifecycle.S0.jsonl.bak"'
fc_check arm_label_swapped 'sed -i.bak "s/\"arm\":\"S0\"/\"arm\":\"OAAA\"/g" "$dir/a03_rotation_ipv6.S0.jsonl" && rm -f "$dir/a03_rotation_ipv6.S0.jsonl.bak"'
fc_check unknown_assertion_row 'grep "\"assert\":\"L1 " "$dir/a02_lifecycle.OAAA.jsonl" | sed "s/\"assert\":\"L1 /\"assert\":\"Z9 /" >>"$dir/a02_lifecycle.OAAA.jsonl"'
fc_check redis_evidence_deleted 'rm -f "$dir/a05_redis.OAAA.jsonl"; echo RAN >"$dir/redis_status.txt"'
fc_check concurrency_evidence_deleted 'rm -f "$dir/a06_concurrency.S0.jsonl"; echo RAN >"$dir/redis_status.txt"'
fc_check host_probe_deleted 'rm -f "$dir/a07_host_probe.OAAA.jsonl"; echo RAN >"$dir/redis_status.txt"'
fc_check redis_status_missing 'rm -f "$dir/redis_status.txt"'
# invalid patch provenance: a copy of the reporter next to a drifted copy of the committed patch must refuse the evidence
fc_patch() {
    local dir="$actual/failclosed-patch" rc
    rm -rf "${dir:?}"
    mkdir -p "$dir/study/lib" "$dir/study/patches" "$dir/guarded-network-headroom-110/lib" "$dir/guarded-network-headroom-110/expected" "$dir/ev"
    cp "$here/report.php" "$dir/study/"
    cp "$here/lib/cells.php" "$dir/study/lib/"
    cp "$here/../guarded-network-headroom-110/lib/cells.php" "$dir/guarded-network-headroom-110/lib/"
    cp "$here/../guarded-network-headroom-110/expected/g01_matrix.S0.jsonl" "$dir/guarded-network-headroom-110/expected/"
    sed 's/OAAA_SCOPE_DAY_UNITS = 6/OAAA_SCOPE_DAY_UNITS = 60/' "$here/patches/oaaa-candidate.patch" >"$dir/study/patches/oaaa-candidate.patch"
    cp "$actual"/*.jsonl "$actual"/redis_status.txt "$dir/ev/"
    rm -f "$dir/ev/gates.jsonl"
    set +e
    php "$dir/study/report.php" "$dir/ev" "$dir/ev" >/dev/null 2>&1
    rc=$?
    set -e
    if [[ $rc -eq 5 ]] && ! grep -q '"status":"PASS"' "$dir/ev/gates.jsonl" 2>/dev/null; then report "selftest_failclosed_invalid_patch_provenance" "PASS (report.php exit 5; no PASS gate written)"; else report "selftest_failclosed_invalid_patch_provenance" "FAIL (exit $rc)"; status=1; fi
    rm -rf "${dir:?}"
}
fc_patch
# missing gate: the completeness check must detect a deleted gate row
cp "$actual/gates.jsonl" "$work/gates-missing.jsonl"
grep -v '"gate":"E3",' "$work/gates-missing.jsonl" >"$work/gates-missing.tmp" && mv "$work/gates-missing.tmp" "$work/gates-missing.jsonl"
if gates_complete "$work/gates-missing.jsonl"; then report "selftest_missing_gate_detected" "FAIL (a deleted gate row was not detected)"; status=1; else report "selftest_missing_gate_detected" "PASS (gate completeness check refuses a deleted gate row)"; fi
rm -f "$work/gates-missing.jsonl"

# gate detection: inject a violation into otherwise VALID evidence; report.php must still exit 0 and the targeted gate must turn FAIL
gate_check() { # name gate mutation-command-on-copy
    local name=$1 gate=$2 dir="$actual/gatecheck-$1" rc
    rm -rf "${dir:?}"
    mkdir -p "$dir"
    cp "$actual"/*.jsonl "$actual"/redis_status.txt "$dir"/ 2>/dev/null || true
    rm -f "$dir"/gates.jsonl
    eval "$3"
    set +e
    php "$here/report.php" "$dir" "$dir" >"$dir/report.out" 2>"$dir/report.stderr"
    rc=$?
    set -e
    if [[ $rc -eq 0 ]] && grep -q "\"gate\":\"$gate\",[^}]*\"status\":\"FAIL\"" "$dir/gates.jsonl"; then
        report "selftest_gate_$name" "PASS ($gate turns FAIL)"
    else
        report "selftest_gate_$name" "FAIL (exit $rc; $gate did not turn FAIL)"
        status=1
    fi
    rm -rf "${dir:?}"
}
gate_check e2_detects_burst_leak E2 'mutate "$dir/a06_concurrency.OAAA.jsonl" "(\$r[\"s\"] === \"A06.C2.burst-no-unit-left\") ? [\"rounds\" => array_map(static fn(array \$x): array => [\"admitted\" => 5] + \$x, \$r[\"rounds\"])] + \$r : \$r"'
gate_check e5_detects_replay_violation E5 'mutate "$dir/a02_lifecycle.OAAA.jsonl" "isset(\$r[\"assert\"]) && str_starts_with(\$r[\"assert\"], \"L7 \") ? [\"ok\" => false] + \$r : \$r"'
gate_check e6_detects_aggregate_violation E6 'mutate "$dir/a01_matrix.OAAA.jsonl" "(\$r[\"s\"] === \"A01.matrix\" && \$r[\"stream\"] === \"OWN4\" && \$r[\"shape\"] === \"login\" && \$r[\"flow\"] === \"pre+out\") ? [\"attackReachedByIp600\" => array_map(static fn(array \$w): array => array_map(static fn(int \$n): int => \$n + 5, \$w), \$r[\"attackReachedByIp600\"])] + \$r : \$r"'
gate_check f2_detects_fairness_regression F2 'mutate "$dir/a01_matrix.OAAA.jsonl" "(\$r[\"s\"] === \"A01.matrix\" && \$r[\"stream\"] === \"D2\" && \$r[\"shape\"] === \"login\" && \$r[\"flow\"] === \"pre+out\") ? [\"legitDenied\" => 120] + \$r : \$r"'
gate_check o1_detects_pre_only_change O1 'mutate "$dir/a01_matrix.OAAA.jsonl" "(\$r[\"s\"] === \"A01.matrix\" && \$r[\"stream\"] === \"ABA_B\" && \$r[\"shape\"] === \"login\" && \$r[\"flow\"] === \"pre\") ? [\"legitDeniedBits\" => strtr(\$r[\"legitDeniedBits\"], \"01\", \"10\")] + \$r : \$r"'

if [[ "${UPDATE:-0}" == "1" ]]; then
    report "UPDATE" "expected/ regenerated — review the git diff before committing"
fi
exit "$status"
