#!/usr/bin/env bash
# Unified Security Feasibility (#97 / #100) — reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/workstream-b-anti-evasion/feasibility/.
#
#   bash investigations/workstream-b-anti-evasion/feasibility/run.sh                 # everything, diffed against expected/
#   UPDATE=1 bash .../feasibility/run.sh                                             # regenerate expected/ (REVIEW the git diff!)
#   REDIS_SPOTCHECK=0 bash .../feasibility/run.sh                                    # skip the real-Redis stage (its gates become UNPROVEN)
#   FSB_REDIS_LIVE=0 bash .../feasibility/run.sh                                     # skip only the 63 s live-recovery checks (UNPROVEN)
#   REDIS_PORT=6500 bash .../feasibility/run.sh                                      # first port to try for the instances run.sh starts
#
# Runtime levels (never merged into one result):
#   S0                = S0_RUNTIME_OBSERVED              the repository checkout, UNPATCHED EvaluationPipeline (sha-256 asserted by every scenario)
#   EQL / EQV         = CANDIDATE_EXPERIMENTAL_RUNTIME   isolated copy of the pinned baseline + PR #105's patch (design/patches/eq-candidates.patch, read only)
#   PSC / PSCA        = CANDIDATE_EXPERIMENTAL_RUNTIME   isolated copy of the pinned baseline + patches/psc-candidate.patch (this study's single candidate)
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
parent=$(cd "$here/.." && pwd)
repo=$(cd "$here/../../.." && pwd)
actual="${here:?}/results/actual"
work="${here:?}/results/work"
expected="${here:?}/expected"
BASE_SHA=b34f12eb3f892c2dd6fa423280ead0cdaafc868f
mkdir -p "$actual" "$work" "$expected"
find "${actual:?}" -maxdepth 1 -type f \( -name '*.jsonl' -o -name '*.diff' -o -name '*.stderr' -o -name '*.txt' -o -name 'summary.md' -o -name 'report.out' \) -delete

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
    echo "no vendor/ in $repo — run 'composer install' first." >&2
    exit 2
fi
status=0
report() { printf '%-52s %s\n' "$1" "$2"; }

# ---- 0. baseline + provenance --------------------------------------------------------------------------------------------------
{
    echo "baseline_expected: $BASE_SHA"
    echo "repo_head: $(git -C "$repo" rev-parse HEAD)"
    echo "pipeline_sha256_repo: $(shasum -a 256 "$repo/src/Service/EvaluationPipeline.php" | cut -d' ' -f1)"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "composer: $(composer --version 2>/dev/null | head -1 || echo n/a)"
    echo "redis: $(redis-server --version 2>/dev/null || echo n/a)"
} >"$actual/versions.txt"
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { report "baseline_is_ancestor" "FAIL ($BASE_SHA is not an ancestor of HEAD)"; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src tests docs composer.json composer.lock phpunit.xml phpstan.neon 2>/dev/null)" ]]; then
    report "baseline_production_identical" "FAIL (src/, tests/, docs/ or Composer files differ from $BASE_SHA)"
    exit 4
fi
report "baseline_production_identical" "PASS (src/, tests/, docs/, composer.* identical to $BASE_SHA)"

# ---- 1. isolated candidate runtimes ----------------------------------------------------------------------------------------------
psc="$work/psc"
eq="$work/eq"
for d in "$psc" "$eq"; do
    case "$d" in "$here/results/work/"*) rm -rf "${d:?}" ;; *) echo "refusing to remove $d" >&2; exit 2 ;; esac
done
bash "$here/build_candidate.sh" psc "$psc" >/dev/null
bash "$here/build_candidate.sh" eq "$eq" >/dev/null
cp "$psc/BUILD_INFO.txt" "$actual/build_info_psc.txt"
cp "$eq/BUILD_INFO.txt" "$actual/build_info_eq.txt"
report "candidate_build" "PASS (psc $(grep target_sha256_after "$psc/BUILD_INFO.txt" | cut -d' ' -f2 | cut -c1-12)..., eq $(grep target_sha256_after "$eq/BUILD_INFO.txt" | cut -d' ' -f2 | cut -c1-12)...)"

exp_dir="$work/baseline_export"
case "$exp_dir" in "$here/results/work/"*) rm -rf "${exp_dir:?}" ;; *) exit 2 ;; esac
mkdir -p "$exp_dir"
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$exp_dir"
: >"$actual/candidate_diff.txt"
for t in psc eq; do
    d="$work/$t"
    { diff -rq "$exp_dir" "$d" -x vendor -x BUILD_INFO.txt | sed "s#$exp_dir#BASELINE#g; s#$d#CANDIDATE_${t}#g" | sort || true; } >>"$actual/candidate_diff.txt"
done
{
    echo "store_operations_called_by_added_lines (psc patch):"
    grep '^+' "$here/patches/psc-candidate.patch" | grep -o 'correlationStore->[A-Za-z]*' | sort -u
    echo "internal_helpers_called_by_added_lines (psc patch):"
    grep '^+' "$here/patches/psc-candidate.patch" | grep -o 'this->addBoundedCorrelation\|this->hashKey' | sort -u
    echo "non_private_declarations_added (psc patch; public/protected/interface/abstract/const/use):"
    grep '^+' "$here/patches/psc-candidate.patch" | grep -E '^\+\s*(public|protected|abstract|interface|const|use|final)\b' | sed 's/^+//' || true
} >"$actual/store_calls.txt"
two_files=$(grep -c . "$actual/candidate_diff.txt" || true)
report "candidates_differ_in_one_file_each" "$([[ "$two_files" == "2" ]] && ! grep -v 'Service/EvaluationPipeline.php' "$actual/candidate_diff.txt" | grep -q . && echo PASS || echo "FAIL ($two_files lines)")"
[[ "$two_files" == "2" ]] || status=1

variants=(S0 EQL EQV PSC PSCA)
root_for() { case $1 in S0) echo "$repo" ;; EQL | EQV) echo "$eq" ;; *) echo "$psc" ;; esac; }
env_for() { if [[ $1 == S0 ]]; then echo "WSB_CANDIDATE="; else echo "WSB_CANDIDATE=$1"; fi; }

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
run_scenario() { # name script variant [extra env...]
    local name=$1 script=$2 v=$3
    shift 3
    if ! env "$(env_for "$v")" LAB_ROOT="$(root_for "$v")" "$@" php "$script" >"$actual/$name.jsonl" 2>"$actual/$name.stderr"; then
        report "$name" "FAIL (non-zero exit; see results/actual/$name.jsonl / .stderr)"
        status=1
        return 1
    fi
    if [[ -s "$actual/$name.stderr" ]]; then
        report "$name" "FAIL (stderr not empty)"
        status=1
        return 1
    fi
    rm -f "${actual:?}/$name.stderr"
    compare "$name"
}

# ---- 2. in-memory / reference-store scenarios, every variant -----------------------------------------------------------------------
# f0* are this study's scenarios; a06 (indistinguishability) and a07 (DEC-003 behaviour) are PR #104's, run UNCHANGED on every variant.
scenarios=("$here/scenarios/f01_matrix" "$here/scenarios/f03_recovery_boundaries" "$here/scenarios/f04_rotation_ephemeral_failure" "$here/scenarios/f05_state_cost" "$here/scenarios/f07_poisoning_cost" "$parent/scenarios/a06_indistinguishability" "$parent/scenarios/a07_dec003_behavior")
for path in "${scenarios[@]}"; do
    sc=$(basename "$path")
    for v in "${variants[@]}"; do
        run_scenario "$sc.$v" "$path.php" "$v" || true
    done
done

# Sensitivity of the SAME candidate to its window cap (test-only knobs; PSC only).
for cap in 3 4 6 12; do
    run_scenario "f02_sensitivity.PSC.churn$cap" "$here/scenarios/f02_sensitivity.php" PSC FSB_KNOB=PSC_CAP_CHURN PSC_CAP_CHURN=$cap || true
done
for cap in 6 8 12 24; do
    run_scenario "f02_sensitivity.PSC.dilution$cap" "$here/scenarios/f02_sensitivity.php" PSC FSB_KNOB=PSC_CAP_DILUTION PSC_CAP_DILUTION=$cap || true
done

# ---- 3. real Redis (instances owned and proven by this script; PR #103 F103-01 safety model) ----------------------------------------
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
echo "${FSB_REDIS_LIVE:-1}" >"$actual/redis_live.txt"
if [[ "${REDIS_SPOTCHECK:-1}" == "1" ]]; then
    if ! command -v redis-server >/dev/null 2>&1 || ! command -v redis-cli >/dev/null 2>&1; then
        for v in "${variants[@]}"; do report "f06_redis.$v" "SKIPPED (redis not installed) -> real-Redis claims UNPROVEN"; done
    else
        own_port=$(free_port "${REDIS_PORT:-6399}")
        decoy_port=$(free_port $((own_port + 1)))
        start_redis own "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
        start_redis decoy "$decoy_port"; decoy_pid=$REDIS_PID_VALUE; decoy_dir=$REDIS_DIR_VALUE
        trap 'stop_redis "${own_pid:-}"; stop_redis "${decoy_pid:-}"' EXIT
        redis-cli -h 127.0.0.1 -p "$decoy_port" set decoy:sentinel precious >/dev/null
        safety=PASS
        set +e
        REDIS_HOST=127.0.0.1 REDIS_PORT=$decoy_port LAB_ROOT="$repo" php "$here/scenarios/f06_redis.php" >/dev/null 2>&1; rc1=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$own_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/f06_redis.php" >/dev/null 2>&1; rc2=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/f06_redis.php" >/dev/null 2>&1; rc3=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$decoy_dir LAB_ROOT="$repo" php "$here/scenarios/f06_redis.php" >/dev/null 2>&1; rc4=$?
        set -e
        [[ $rc1 -eq 3 && $rc2 -eq 3 && $rc3 -eq 3 && $rc4 -eq 3 ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" get decoy:sentinel)" == "precious" ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" dbsize | tr -dc '0-9')" == "1" ]] || safety=FAIL
        report "f06_redis_safety" "$safety (foreign/mismatched/non-empty server refused rc=$rc1,$rc2,$rc3,$rc4; decoy sentinel intact)"
        echo "$safety rc=$rc1,$rc2,$rc3,$rc4" >"$actual/redis_safety.txt"
        [[ $safety == PASS ]] || status=1
        if [[ $safety == PASS ]]; then
            echo RAN >"$actual/redis_status.txt"
            for v in "${variants[@]}"; do
                name="f06_redis.$v"
                for attempt in 1 2 3; do
                    stop_redis "$own_pid"
                    own_port=$(free_port "$own_port")
                    start_redis "own-$v-$attempt" "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
                    env "$(env_for "$v")" LAB_ROOT="$(root_for "$v")" REDIS_HOST=198.51.100.1 REDIS_PORT=1 WSB_REDIS_PORT="$own_port" WSB_REDIS_PID="$own_pid" WSB_REDIS_DIR="$own_dir" \
                        php "$here/scenarios/f06_redis.php" >"$actual/$name.jsonl" 2>"$actual/$name.stderr" || true
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
        fi
    fi
fi

# ---- 4. gates + summary ---------------------------------------------------------------------------------------------------------
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
if [[ "${UPDATE:-0}" == "1" ]]; then
    cp "$actual/candidate_diff.txt" "$expected/candidate_diff.txt"
    cp "$actual/store_calls.txt" "$expected/store_calls.txt"
else
    for f in candidate_diff.txt store_calls.txt; do
        if diff -q "$expected/$f" "$actual/$f" >/dev/null 2>&1; then report "$f" "PASS (identical)"; else report "$f" "FAIL (differs from expected/$f)"; status=1; fi
    done
fi

# ---- 5. self-tests: the assertions must FAIL when a security outcome / recovery boundary / provenance changes -----------------------
selftest() { # name command...
    local name=$1
    shift
    if "$@" >"$actual/selftest_$name.jsonl" 2>/dev/null; then
        report "selftest_$name" "FAIL (mutation NOT detected: the run still passed)"
        status=1
    elif grep -q '"ok":false' "$actual/selftest_$name.jsonl"; then
        report "selftest_$name" "PASS (detected: $(grep -c '"ok":false' "$actual/selftest_$name.jsonl") assertion(s) violated, exit non-zero)"
    else
        report "selftest_$name" "FAIL (non-zero exit without a recorded violated assertion)"
        status=1
    fi
}
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
# the corroboration rule is the candidate: removing the qualification (S0 behaviour) or lengthening the window to the 600 s set horizon must each be caught
selftest remove_corroboration env WSB_STRICT=1 PSC_MUTATE=ungated WSB_CANDIDATE=PSC LAB_ROOT="$psc" php "$here/scenarios/f03_recovery_boundaries.php"
selftest lengthen_window env WSB_STRICT=1 PSC_MUTATE=long_window WSB_CANDIDATE=PSC LAB_ROOT="$psc" php "$here/scenarios/f03_recovery_boundaries.php"
selftest remove_correlation_s0 env WSB_SELFTEST_CORR_OFF=1 LAB_ROOT="$repo" php "$here/scenarios/f01_matrix.php"
selftest move_block_boundary_dec003_s0 env WSB_SELFTEST_BLOCK_SHIFT=1 LAB_ROOT="$repo" php "$parent/scenarios/a07_dec003_behavior.php"
# provenance: a candidate label on the baseline tree, an unlabelled run on a candidate tree and the wrong candidate tree must all be refused with exit 4
selftest_exit label_candidate_on_baseline 4 env WSB_CANDIDATE=PSC LAB_ROOT="$repo" php "$here/scenarios/f04_rotation_ephemeral_failure.php"
selftest_exit label_baseline_on_candidate 4 env WSB_CANDIDATE= LAB_ROOT="$psc" php "$here/scenarios/f04_rotation_ephemeral_failure.php"
selftest_exit label_psc_on_eq_tree 4 env WSB_CANDIDATE=PSC LAB_ROOT="$eq" php "$here/scenarios/f04_rotation_ephemeral_failure.php"
selftest_exit label_eql_on_psc_tree 4 env WSB_CANDIDATE=EQL LAB_ROOT="$psc" php "$here/scenarios/f04_rotation_ephemeral_failure.php"
# build recipe: wrong baseline hash (3), absent patch (5), patch bytes modified (6), patched-target hash mismatch (7)
selftest_exit build_hash_mismatch 3 env FSB_TEST_EXPECT_HASH=0000 bash "$here/build_candidate.sh" psc "$work/st_build_a"
selftest_exit build_patch_absent 5 env FSB_TEST_PATCH_FILE="$work/does-not-exist.patch" bash "$here/build_candidate.sh" psc "$work/st_build_b"
modified="$work/modified.patch"
cp "$here/patches/psc-candidate.patch" "$modified"
echo "# tamper" >>"$modified"
selftest_exit build_patch_modified 6 env FSB_TEST_PATCH_FILE="$modified" bash "$here/build_candidate.sh" psc "$work/st_build_c"
selftest_exit build_patched_hash_mismatch 7 env FSB_TEST_EXPECT_AFTER=0000 bash "$here/build_candidate.sh" psc "$work/st_build_d"
rm -rf "${work:?}"/st_build_? "$modified"

# fail-closed report: delete or damage required evidence; the report must exit 5 and never print a verdict for the damaged evidence
fc="$work/failclosed"
rm -rf "${fc:?}"
failclosed() { # name mutate-command
    local name=$1 mut=$2 rc
    rm -rf "${fc:?}" && mkdir -p "$fc"
    cp "$actual"/*.jsonl "$actual"/*.txt "$fc"/ 2>/dev/null || true
    eval "$mut"
    set +e
    php "$here/report.php" "$fc" "$fc/out" >"$fc/report.out" 2>/dev/null
    rc=$?
    set -e
    if [[ $rc -eq 5 ]]; then report "selftest_failclosed_$name" "PASS (report exit 5, evidence problem raised)"; else report "selftest_failclosed_$name" "FAIL (report exit $rc)"; status=1; fi
}
failclosed delete_matrix 'rm -f "$fc/f01_matrix.PSC.jsonl"'
failclosed empty_recovery ': >"$fc/f03_recovery_boundaries.PSC.jsonl"'
failclosed damage_rotation 'echo "{broken" >>"$fc/f04_rotation_ephemeral_failure.PSC.jsonl"'
failclosed delete_state_cost 'rm -f "$fc/f05_state_cost.PSC.jsonl"'
failclosed delete_a06 'rm -f "$fc/a06_indistinguishability.PSC.jsonl"'
failclosed delete_poisoning 'rm -f "$fc/f07_poisoning_cost.PSC.jsonl"'
failclosed delete_dec003 'rm -f "$fc/a07_dec003_behavior.PSC.jsonl"'
failclosed delete_sensitivity 'rm -f "$fc/f02_sensitivity.PSC.churn4.jsonl"'
failclosed delete_redis_status 'rm -f "$fc/redis_status.txt"'
failclosed drop_matrix_field "python3 - \"\$fc/f01_matrix.PSC.jsonl\" <<'PY'
import json, sys
p = sys.argv[1]
rows = [json.loads(l) for l in open(p)]
for r in rows:
    r.pop('legitDeniedByCorrelation', None)
open(p, 'w').write('\n'.join(json.dumps(r, separators=(',', ':')) for r in rows) + '\n')
PY"
failclosed drop_one_cell "python3 - \"\$fc/f01_matrix.PSC.jsonl\" <<'PY'
import json, sys
p = sys.argv[1]
rows = [json.loads(l) for l in open(p)]
drop = next(i for i, r in enumerate(rows) if r.get('s') == 'F01.matrix' and r['stream'] == 'D1' and r['shape'] == 'api' and r['flow'] == 'pre')
del rows[drop]
open(p, 'w').write('\n'.join(json.dumps(r, separators=(',', ':')) for r in rows) + '\n')
PY"
failclosed delete_a06_assertions "python3 - \"\$fc/a06_indistinguishability.PSC.jsonl\" <<'PY'
import json, sys
p = sys.argv[1]
rows = [json.loads(l) for l in open(p)]
rows = [r for r in rows if 'assert' not in r]
open(p, 'w').write('\n'.join(json.dumps(r, separators=(',', ':')) for r in rows) + '\n')
PY"
rm -rf "${fc:?}"

if [[ $status -ne 0 ]]; then
    echo "RESULT: FAIL (see lines above)"
else
    echo "RESULT: PASS"
fi
exit $status
