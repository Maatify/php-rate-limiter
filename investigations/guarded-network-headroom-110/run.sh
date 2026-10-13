#!/usr/bin/env bash
# #110 Guarded Network Headroom — reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/guarded-network-headroom-110/.
#
#   bash investigations/guarded-network-headroom-110/run.sh                  # everything, diffed against expected/
#   UPDATE=1 bash investigations/guarded-network-headroom-110/run.sh         # regenerate expected/ (REVIEW the git diff!)
#   REDIS_SPOTCHECK=0 bash investigations/guarded-network-headroom-110/run.sh   # skip the real-Redis stage (G4a/G4b/G4d become UNPROVEN)
#   GNH_REDIS_FIRST_PORT=6500 bash .../run.sh                               # first port to try for the instances run.sh starts
#
# Runtime levels (never merged into one result):
#   S0         = S0_RUNTIME_OBSERVED            the repository checkout, UNPATCHED EvaluationPipeline (sha-256 asserted by every scenario)
#   TH5 / GNH5 = CANDIDATE_EXPERIMENTAL_RUNTIME an isolated copy of the pinned baseline + patches/gnh-candidates.patch (build_candidate.sh),
#                the arm selected at run time by env GNH_ARM (read by the patched pipeline, which refuses any other value).
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="${here:?}/results/actual"
work="${here:?}/results/work"
expected="${here:?}/expected"
BASE_SHA=1ad9af289f83f86346667a014dd486f9c4b8fc08
mkdir -p "$actual" "$work" "$expected"
find "${actual:?}" -maxdepth 1 -type f \( -name '*.jsonl' -o -name '*.diff' -o -name '*.stderr' -o -name '*.txt' -o -name 'summary.md' -o -name 'report.out' \) -delete
rm -rf "${actual:?}"/redis-* "${actual:?}"/failclosed-*

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
    echo "no vendor/ in $repo — run 'composer install' first." >&2
    exit 2
fi
status=0
report() { printf '%-60s %s\n' "$1" "$2"; }

# ---- 0. baseline + provenance ------------------------------------------------------------------------------------------------------
{
    echo "baseline_expected: $BASE_SHA"
    echo "repo_head: $(git -C "$repo" rev-parse HEAD)"
    echo "pipeline_sha256_repo: $(shasum -a 256 "$repo/src/Service/EvaluationPipeline.php" | cut -d' ' -f1)"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "composer: $(composer --version 2>/dev/null | head -1 || echo n/a)"
    echo "redis: $(redis-server --version 2>/dev/null || echo n/a)"
} >"$actual/versions.txt"
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { report "baseline_is_ancestor" "FAIL ($BASE_SHA is not an ancestor of HEAD)"; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src tests composer.json 2>/dev/null)" ]]; then
    report "baseline_src_identical" "FAIL (src/, tests/ or composer.json differ from $BASE_SHA)"
    exit 4
fi
if [[ -n "$(git -C "$repo" status --porcelain -- src tests composer.json)" ]]; then
    report "baseline_src_clean" "FAIL (uncommitted changes under src/, tests/ or composer.json)"
    exit 4
fi
report "baseline_src_identical" "PASS (src/, tests/, composer.json identical to $BASE_SHA; clean)"

# ---- 1. isolated candidate runtime --------------------------------------------------------------------------------------------------
cand="$work/candidate"
case "$cand" in
    "$here/results/work/"*) rm -rf "${cand:?}" ;;
    *) echo "refusing to remove $cand" >&2; exit 2 ;;
esac
bash "$here/build_candidate.sh" "$cand" >/dev/null
cp "$cand/BUILD_INFO.txt" "$actual/candidate_build_info.txt"
report "candidate_build" "PASS (target_sha256_after $(grep target_sha256_after "$cand/BUILD_INFO.txt" | cut -d' ' -f2 | cut -c1-16)...)"

arms=(S0 TH5 GNH5)
run_arm() { # arm scenario out err
    local a=$1 sc=$2 o=$3 e=$4
    if [[ $a == S0 ]]; then
        env -u GNH_ARM -u OQC_ARM -u WSB_CANDIDATE LAB_ROOT="$repo" php "$here/scenarios/$sc.php" >"$o" 2>"$e"
    else
        env -u OQC_ARM -u WSB_CANDIDATE GNH_ARM="$a" LAB_ROOT="$cand" php "$here/scenarios/$sc.php" >"$o" 2>"$e"
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

# ---- 2. reference-store scenarios, every arm (the three arms of one scenario run concurrently; their outputs are independent) -----------
for sc in g01_matrix g02_guard g03_rotation_ipv6 g04_bounds_rollback; do
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
        report "g05_redis / g06_concurrency" "SKIPPED (redis not installed) -> real-Redis gates UNPROVEN"
    else
        own_port=$(free_port "${GNH_REDIS_FIRST_PORT:-6431}")
        decoy_port=$(free_port $((own_port + 1)))
        start_redis own "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
        start_redis decoy "$decoy_port"; decoy_pid=$REDIS_PID_VALUE; decoy_dir=$REDIS_DIR_VALUE
        trap 'stop_redis "${own_pid:-}"; stop_redis "${decoy_pid:-}"' EXIT
        redis-cli -h 127.0.0.1 -p "$decoy_port" set decoy:sentinel precious >/dev/null
        safety=PASS
        set +e
        # (1) a foreign server selected through inherited REDIS_HOST/REDIS_PORT, (2) wrong pid, (3) wrong dir, (4) non-empty server with matching pid+dir
        env -u GNH_ARM REDIS_HOST=127.0.0.1 REDIS_PORT=$decoy_port LAB_ROOT="$repo" php "$here/scenarios/g05_redis.php" >/dev/null 2>&1; rc1=$?
        env -u GNH_ARM WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$own_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/g05_redis.php" >/dev/null 2>&1; rc2=$?
        env -u GNH_ARM WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/g05_redis.php" >/dev/null 2>&1; rc3=$?
        env -u GNH_ARM WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$decoy_dir LAB_ROOT="$repo" php "$here/scenarios/g05_redis.php" >/dev/null 2>&1; rc4=$?
        set -e
        [[ $rc1 -eq 3 && $rc2 -eq 3 && $rc3 -eq 3 && $rc4 -eq 3 ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" get decoy:sentinel)" == "precious" ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" dbsize | tr -dc '0-9')" == "1" ]] || safety=FAIL
        report "redis_safety" "$safety (foreign/mismatched/non-empty server refused rc=$rc1,$rc2,$rc3,$rc4; decoy sentinel intact)"
        [[ $safety == PASS ]] || status=1
        if [[ $safety == PASS ]]; then
            echo RAN >"$actual/redis_status.txt"
            for sc in g05_redis g06_concurrency; do
                for a in "${arms[@]}"; do
                    name="$sc.$a"
                    for attempt in 1 2 3; do
                        stop_redis "$own_pid"
                        own_port=$(free_port "$own_port")
                        start_redis "own-$sc-$a-$attempt" "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
                        if [[ $a == S0 ]]; then
                            env -u GNH_ARM -u OQC_ARM -u WSB_CANDIDATE LAB_ROOT="$repo" REDIS_HOST=198.51.100.1 REDIS_PORT=1 WSB_REDIS_PORT="$own_port" WSB_REDIS_PID="$own_pid" WSB_REDIS_DIR="$own_dir" \
                                php "$here/scenarios/$sc.php" >"$actual/$name.jsonl" 2>"$actual/$name.stderr" || true
                        else
                            env -u OQC_ARM -u WSB_CANDIDATE GNH_ARM="$a" LAB_ROOT="$cand" REDIS_HOST=198.51.100.1 REDIS_PORT=1 WSB_REDIS_PORT="$own_port" WSB_REDIS_PID="$own_pid" WSB_REDIS_DIR="$own_dir" \
                                php "$here/scenarios/$sc.php" >"$actual/$name.jsonl" 2>"$actual/$name.stderr" || true
                        fi
                        if [[ ! -s "$actual/$name.stderr" ]] && { [[ "${UPDATE:-0}" == "1" ]] || diff -q "$expected/$name.jsonl" "$actual/$name.jsonl" >/dev/null 2>&1; }; then
                            break
                        fi
                    done
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
if php "$here/report.php" "$actual" "$actual" >"$actual/report.out" 2>"$actual/report.stderr" && [[ ! -s "$actual/report.stderr" ]]; then
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
# provenance: a candidate arm on the repository tree, the S0 arm on the candidate tree, an unknown arm and a foreign study's arm variable
selftest_exit candidate_arm_on_repo_tree 4 env GNH_ARM=GNH5 LAB_ROOT="$repo" php "$here/scenarios/g02_guard.php"
selftest_exit s0_arm_on_candidate_tree 4 env -u GNH_ARM LAB_ROOT="$cand" php "$here/scenarios/g02_guard.php"
selftest_exit unknown_arm 4 env GNH_ARM=OQCE LAB_ROOT="$cand" php "$here/scenarios/g02_guard.php"
selftest_exit foreign_arm_variable 4 env GNH_ARM=TH5 OQC_ARM=OQC LAB_ROOT="$cand" php "$here/scenarios/g02_guard.php"
# the patched pipeline itself refuses to run without a valid GNH_ARM (it never acts as S0): harness provenance bypassed on purpose
selftest_exit patched_tree_refuses_s0_label 255 env -u GNH_ARM LAB_ROOT="$cand" php -d display_errors=stderr -r 'require $argv[1]; gnhMeasure(gnhStreams()["SP1n"]["events"], "login", "pre");' "$here/lib/gnh.php"
# pinning: a drifted patch is refused by the hash pin (exit 6), and by `git apply` when the pin is bypassed (exit 4)
drift="$work/drifted.patch"
sed 's/gnh5:budget:/gnh5:budgetX:/' "$here/patches/gnh-candidates.patch" >"$drift"
selftest_exit drifted_patch_hash_pin 6 env GNH_TEST_PATCH_FILE="$drift" bash "$here/build_candidate.sh" "$work/drift-out"
rm -rf "${work:?}/drift-out"
sed '5,12d' "$here/patches/gnh-candidates.patch" >"$drift"
selftest_exit drifted_patch_does_not_apply 4 env GNH_TEST_PATCH_FILE="$drift" GNH_TEST_SKIP_PIN=1 bash "$here/build_candidate.sh" "$work/drift-out"
rm -rf "${work:?}/drift-out" "$drift"
selftest_exit wrong_baseline_target_hash 3 env GNH_TEST_EXPECT_HASH=0000000000000000000000000000000000000000000000000000000000000000 bash "$here/build_candidate.sh" "$work/drift-out"
rm -rf "${work:?}/drift-out"
# candidate assertions are findings, not evidence problems: a candidate scenario still exits 0 with a violated assertion unless GNH_STRICT=1
selftest_exit strict_mode_makes_candidate_findings_fatal 1 env GNH_ARM=GNH5 GNH_STRICT=1 GNH_SELFTEST_BREAK=1 LAB_ROOT="$cand" php "$here/scenarios/g02_guard.php"
selftest_exit s0_invariant_violation_is_fatal 1 env -u GNH_ARM GNH_SELFTEST_BREAK=1 LAB_ROOT="$repo" php "$here/scenarios/g02_guard.php"
# fail-closed evidence: delete / damage representative evidence and require report.php to refuse (exit 5) and write no PASS gate
fc_check() { # name mutation-command-on-copy
    local name=$1 dir="$actual/failclosed-$1" rc
    rm -rf "${dir:?}"
    mkdir -p "$dir"
    cp "$actual"/*.jsonl "$actual"/redis_status.txt "$dir"/ 2>/dev/null || true
    rm -f "$dir"/selftest_*.jsonl "$dir"/gates.jsonl
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
fc_check missing_s0_matrix 'rm -f "$dir/g01_matrix.S0.jsonl"'
fc_check missing_th5_matrix 'rm -f "$dir/g01_matrix.TH5.jsonl"'
fc_check missing_guard 'rm -f "$dir/g02_guard.GNH5.jsonl"'
fc_check missing_rotation 'rm -f "$dir/g03_rotation_ipv6.TH5.jsonl"'
fc_check missing_bounds 'rm -f "$dir/g04_bounds_rollback.S0.jsonl"'
fc_check empty_file ': >"$dir/g02_guard.S0.jsonl"'
fc_check corrupted_json_line 'printf "{not json\n" >>"$dir/g03_rotation_ipv6.GNH5.jsonl"'
fc_check one_matrix_cell_deleted 'grep -v "\"stream\":\"D2\",\"class\":\"benign\",\"shape\":\"login\",\"flow\":\"pre+out\"" "$dir/g01_matrix.GNH5.jsonl" >"$dir/t" && mv "$dir/t" "$dir/g01_matrix.GNH5.jsonl"'
fc_check matrix_field_removed 'sed -i.bak "s/\"headroomAdmissions\"/\"zzz\"/g" "$dir/g01_matrix.S0.jsonl" && rm -f "$dir/g01_matrix.S0.jsonl.bak"'
fc_check assertion_row_deleted 'grep -v "\"assert\":\"B1 " "$dir/g02_guard.GNH5.jsonl" >"$dir/t" && mv "$dir/t" "$dir/g02_guard.GNH5.jsonl"'
fc_check data_row_deleted 'grep -v "G02.B5.nat-exhaustion" "$dir/g02_guard.TH5.jsonl" >"$dir/t" && mv "$dir/t" "$dir/g02_guard.TH5.jsonl"'
fc_check s0_invariant_flipped 'sed -i.bak "s/\"assert\":\"S0 D2 reproduces\(.*\)\"ok\":true/\"assert\":\"S0 D2 reproduces\1\"ok\":false/" "$dir/g01_matrix.S0.jsonl" && rm -f "$dir/g01_matrix.S0.jsonl.bak"'
fc_check forged_s0_pipeline_sha256 'sed -i.bak "s/4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc/0000000000000000000000000000000000000000000000000000000000000000/" "$dir/g01_matrix.S0.jsonl" && rm -f "$dir/g01_matrix.S0.jsonl.bak"'
fc_check forged_candidate_pipeline_sha256 'sed -i.bak "s/\"pipelineSha256\":\"[0-9a-f]*\"/\"pipelineSha256\":\"4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc\"/" "$dir/g02_guard.GNH5.jsonl" && rm -f "$dir/g02_guard.GNH5.jsonl.bak"'
fc_check candidate_run_labelled_s0 'cp "$dir/g02_guard.GNH5.jsonl" "$dir/g02_guard.S0.jsonl" && sed -i.bak "s/\"arm\":\"GNH5\"/\"arm\":\"S0\"/g" "$dir/g02_guard.S0.jsonl" && rm -f "$dir/g02_guard.S0.jsonl.bak"'
fc_check arm_label_swapped 'sed -i.bak "s/\"arm\":\"TH5\"/\"arm\":\"GNH5\"/g" "$dir/g01_matrix.TH5.jsonl" && rm -f "$dir/g01_matrix.TH5.jsonl.bak"'
fc_check unknown_assertion_row 'sed "s/\"assert\":\"S1 /\"assert\":\"Z9 /" "$dir/g04_bounds_rollback.GNH5.jsonl" | grep "Z9 " >>"$dir/g04_bounds_rollback.GNH5.jsonl"'
fc_check redis_evidence_deleted 'rm -f "$dir/g05_redis.GNH5.jsonl"; echo RAN >"$dir/redis_status.txt"'
fc_check concurrency_evidence_deleted 'rm -f "$dir/g06_concurrency.TH5.jsonl"; echo RAN >"$dir/redis_status.txt"'
fc_check redis_status_missing 'rm -f "$dir/redis_status.txt"'

# gate detection: inject a violation into otherwise VALID evidence; report.php must still exit 0 and the targeted gate must turn FAIL
gate_check() { # name gate arm mutation-command-on-copy
    local name=$1 gate=$2 arm=$3 dir="$actual/gatecheck-$1" rc
    rm -rf "${dir:?}"
    mkdir -p "$dir"
    cp "$actual"/*.jsonl "$actual"/redis_status.txt "$dir"/ 2>/dev/null || true
    rm -f "$dir"/gates.jsonl
    eval "$4"
    set +e
    php "$here/report.php" "$dir" "$dir" >"$dir/report.out" 2>"$dir/report.stderr"
    rc=$?
    set -e
    if [[ $rc -eq 0 ]] && grep -q "\"gate\":\"$gate\",\"arm\":\"$arm\",[^}]*\"status\":\"FAIL\"" "$dir/gates.jsonl"; then
        report "selftest_gate_$name" "PASS ($gate $arm turns FAIL)"
    else
        report "selftest_gate_$name" "FAIL (exit $rc; $gate $arm did not turn FAIL)"
        status=1
    fi
    rm -rf "${dir:?}"
}
gate_check g1a_detects_unbounded_zone G1a GNH5 'perl -pi -e "s/(\"s\":\"G02.B1.spray-budget\",\"arm\":\"GNH5\",\"requests\":65,\"allowed\":)24/\${1}65/" "$dir/g02_guard.GNH5.jsonl"'
gate_check g1b_detects_full_collapse G1b GNH5 'perl -pi -e "s/(\"stream\":\"SP1n\",\"class\":\"attack\",\"shape\":\"login\",\"flow\":\"pre\+out\".*?\"attackReachedVerification\":)24/\${1}200/" "$dir/g01_matrix.GNH5.jsonl"'
gate_check g3a_detects_fairness_regression G3a GNH5 'perl -pi -e "s/(\"stream\":\"D2\",\"class\":\"benign\",\"shape\":\"login\",\"flow\":\"pre\+out\".*?\"legitDenied\":)93/\${1}120/" "$dir/g01_matrix.GNH5.jsonl"'

if [[ "${UPDATE:-0}" == "1" ]]; then
    report "UPDATE" "expected/ regenerated — review the git diff before committing"
fi
exit "$status"
