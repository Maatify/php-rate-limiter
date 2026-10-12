#!/usr/bin/env bash
# Workstream B / Anti-Evasion — reproduction runner (#97 / #100). Evidence-only: never modifies src/, tests/, docs/ or any tracked
# file outside investigations/workstream-b-anti-evasion/.
#
#   bash investigations/workstream-b-anti-evasion/run.sh                  # everything, diffed against expected/
#   UPDATE=1 bash .../run.sh                                              # regenerate expected/ (REVIEW the git diff!)
#   REDIS_SPOTCHECK=0 bash .../run.sh                                     # skip the real-Redis stage
#   REDIS_PORT=6500 bash .../run.sh                                       # first port to try for the instances run.sh starts
#
# Runtime levels (never merged into one result):
#   S0   = S0_RUNTIME_OBSERVED            the repository checkout, UNPATCHED EvaluationPipeline (sha256 asserted by every scenario)
#   A1/PW/P2/RL = CANDIDATE_EXPERIMENTAL_RUNTIME   an isolated copy of the pinned baseline + patches/wsb-candidates.patch (build_candidate.sh)
#   The PR #103 offline model is compared in report.php as OFFLINE PREDICTION only.
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="${here:?}/results/actual"
work="${here:?}/results/work"
expected="${here:?}/expected"
BASE_SHA=56f601cdc9a7062391395c326f1867d472a4ee49
mkdir -p "$actual" "$work" "$expected"
find "${actual:?}" -maxdepth 1 -type f \( -name '*.jsonl' -o -name '*.diff' -o -name '*.stderr' -o -name 'summary.md' \) -delete

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
    echo "no vendor/ in $repo — run 'composer install' first." >&2
    exit 2
fi
status=0
report() { printf '%-44s %s\n' "$1" "$2"; }

# ---- 0. baseline + provenance -------------------------------------------------------------------------------------------------
head_sha=$(git -C "$repo" rev-parse HEAD)
{
    echo "baseline_expected: $BASE_SHA"
    echo "repo_head: $head_sha"
    echo "pipeline_sha256_repo: $(shasum -a 256 "$repo/src/Service/EvaluationPipeline.php" | cut -d' ' -f1)"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "composer: $(composer --version 2>/dev/null | head -1 || echo n/a)"
    echo "redis: $(redis-server --version 2>/dev/null || echo n/a)"
    echo "phpunit: $(php "$repo/vendor/bin/phpunit" --version 2>/dev/null | head -1 || echo n/a)"
} >"$actual/versions.txt"
# src/ and the Composer files must be byte-identical to the pinned baseline (the branch may add files only under investigations/).
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src composer.json composer.lock 2>/dev/null)" ]]; then
    report "baseline_src_identical" "FAIL (src/ or composer files differ from $BASE_SHA)"
    exit 4
fi
report "baseline_src_identical" "PASS (src/, composer.json, composer.lock identical to $BASE_SHA)"

# ---- 1. isolated candidate runtime --------------------------------------------------------------------------------------------
cand="$work/candidate"
case "$cand" in
    "$here/results/work/"*) rm -rf "${cand:?}" ;;
    *) echo "refusing to remove $cand" >&2; exit 2 ;;
esac
bash "$here/build_candidate.sh" "$cand" >/dev/null
cp "$cand/BUILD_INFO.txt" "$actual/candidate_build_info.txt"
report "candidate_build" "PASS (target_sha256_after $(grep target_sha256_after "$cand/BUILD_INFO.txt" | cut -d' ' -f2 | cut -c1-16)...)"

variants=(S0 A1 PW P2 RL)
root_for() { if [[ $1 == S0 ]]; then echo "$repo"; else echo "$cand"; fi; }
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

# ---- 2. in-memory / reference-store scenarios, every variant ------------------------------------------------------------------
scenarios=(a01_matrix a02_boundaries a03_rotation_ephemeral_backend a04_state_bounds a06_indistinguishability a07_dec003_behavior)
for sc in "${scenarios[@]}"; do
    for v in "${variants[@]}"; do
        name="$sc.$v"
        if ! env "$(env_for "$v")" LAB_ROOT="$(root_for "$v")" php "$here/scenarios/$sc.php" >"$actual/$name.jsonl" 2>"$actual/$name.stderr"; then
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

# ---- 3. real Redis (instances owned and proven by this script; PR #103 F103-01 safety model) --------------------------------------
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
        for v in "${variants[@]}"; do report "a05_redis.$v" "SKIPPED (redis not installed) -> real-Redis claims UNPROVEN"; done
    else
        own_port=$(free_port "${REDIS_PORT:-6399}")
        decoy_port=$(free_port $((own_port + 1)))
        start_redis own "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
        start_redis decoy "$decoy_port"; decoy_pid=$REDIS_PID_VALUE; decoy_dir=$REDIS_DIR_VALUE
        trap 'stop_redis "${own_pid:-}"; stop_redis "${decoy_pid:-}"' EXIT
        redis-cli -h 127.0.0.1 -p "$decoy_port" set decoy:sentinel precious >/dev/null
        safety=PASS
        set +e
        REDIS_HOST=127.0.0.1 REDIS_PORT=$decoy_port LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc1=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$own_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc2=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc3=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$decoy_dir LAB_ROOT="$repo" php "$here/scenarios/a05_redis.php" >/dev/null 2>&1; rc4=$?
        set -e
        [[ $rc1 -eq 3 && $rc2 -eq 3 && $rc3 -eq 3 && $rc4 -eq 3 ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" get decoy:sentinel)" == "precious" ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" dbsize | tr -dc '0-9')" == "1" ]] || safety=FAIL
        report "a05_redis_safety" "$safety (foreign/mismatched/non-empty server refused rc=$rc1,$rc2,$rc3,$rc4; decoy sentinel intact)"
        [[ $safety == PASS ]] || status=1
        if [[ $safety == PASS ]]; then
            echo RAN >"$actual/redis_status.txt"
            for v in "${variants[@]}"; do
                name="a05_redis.$v"
                for attempt in 1 2 3; do
                    stop_redis "$own_pid"
                    own_port=$(free_port "$own_port")
                    start_redis "own-$v-$attempt" "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
                    env "$(env_for "$v")" LAB_ROOT="$(root_for "$v")" REDIS_HOST=198.51.100.1 REDIS_PORT=1 WSB_REDIS_PORT="$own_port" WSB_REDIS_PID="$own_pid" WSB_REDIS_DIR="$own_dir" \
                        php "$here/scenarios/a05_redis.php" >"$actual/$name.jsonl" 2>"$actual/$name.stderr" || true
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
    report "gates" "FAIL (report.php; see results/actual/report.stderr)"
    status=1
fi

# ---- 5. self-tests: the assertions must FAIL when a security outcome / storage cap / recovery boundary changes ------------------
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
selftest remove_storage_cap env WSB_STRICT=1 WSB_MUTATE=uncap WSB_CANDIDATE=PW LAB_ROOT="$cand" php "$here/scenarios/a04_state_bounds.php"
selftest remove_freshness_recovery env WSB_STRICT=1 WSB_MUTATE=no_fresh WSB_CANDIDATE=PW LAB_ROOT="$cand" php "$here/scenarios/a02_boundaries.php"
selftest remove_correlation_s0 env WSB_SELFTEST_CORR_OFF=1 LAB_ROOT="$repo" php "$here/scenarios/a01_matrix.php"
selftest move_recovery_boundary_s0 env WSB_SELFTEST_BLOCK_SHIFT=1 LAB_ROOT="$repo" php "$here/scenarios/a02_boundaries.php"
selftest dec003_cycle_pause_semantics_s0 env WSB_SELFTEST_BLOCK_SHIFT=1 LAB_ROOT="$repo" php "$here/scenarios/a07_dec003_behavior.php"
# Provenance guard: a candidate label pointed at the repository (baseline) tree must be REFUSED (exit 4), never mislabelled.
set +e
env WSB_CANDIDATE=PW LAB_ROOT="$repo" php "$here/scenarios/a01_matrix.php" >/dev/null 2>"$actual/selftest_provenance.stderr"
rc=$?
set -e
if [[ $rc -eq 4 ]]; then
    report "selftest_provenance_guard" "PASS (candidate label on the baseline tree refused, exit 4)"
else
    report "selftest_provenance_guard" "FAIL (exit $rc)"
    status=1
fi

# ---- build recipe: absent / modified / drifted inputs must be REFUSED (F104-01) -------------------------------------------------------
tamper="$work/tampered.patch"
awk 'BEGIN{done=0} /^-[^-]/ && !done {print $0 " /* drift */"; done=1; next} {print}' "$here/patches/wsb-candidates.patch" >"$tamper"
build_rc() { # extra env assignments are passed as arguments; prints the exit code of build_candidate.sh
    local d="$work/rc-build"
    rm -rf "${d:?}"
    set +e
    env "$@" bash "$here/build_candidate.sh" "$d" >/dev/null 2>&1
    local rc=$?
    set -e
    rm -rf "${d:?}"
    echo "$rc"
}
rc_hash=$(build_rc WSB_TEST_EXPECT_HASH=0000000000000000000000000000000000000000000000000000000000000000)
rc_missing=$(build_rc WSB_TEST_PATCH_FILE="$work/does-not-exist.patch")
rc_modified=$(build_rc WSB_TEST_PATCH_FILE="$tamper")
rc_drift=$(build_rc WSB_TEST_PATCH_FILE="$tamper" WSB_TEST_SKIP_PIN=1)
rm -f "${tamper:?}"
if [[ $rc_hash -eq 3 && $rc_missing -eq 5 && $rc_modified -eq 6 && $rc_drift -eq 4 ]]; then
    report "selftest_build_recipe_refuses_bad_inputs" "PASS (target hash mismatch=3, patch absent=5, patch sha-256 modified=6, patch drift=4)"
else
    report "selftest_build_recipe_refuses_bad_inputs" "FAIL (hash=$rc_hash missing=$rc_missing modified=$rc_modified drift=$rc_drift)"
    status=1
fi

# ---- gate evaluation must FAIL CLOSED on missing / malformed / skipped evidence (F104-02) ---------------------------------------------
# Each case copies the evidence directory, removes or damages one required input, runs report.php and requires: exit 5 (or exit 0 for an
# INTENTIONAL Redis skip) AND no PASS verdict for the named variant+gate.
fc_case() { # name expected-rc variant gate-regex mutate-function
    local name=$1 want=$2 variant=$3 re=$4 mut=$5
    local d="$work/ev-$name"
    rm -rf "${d:?}"
    mkdir -p "$d"
    cp "$actual"/*.jsonl "$actual"/redis_status.txt "$d"/
    "$mut" "$d"
    set +e
    php "$here/report.php" "$d" "$d" >/dev/null 2>&1
    local rc=$?
    set -e
    local passes
    passes=$(php -r '$n=0; foreach (file($argv[1]) as $l) { $g=json_decode($l,true); if ($g["variant"]===$argv[2] && preg_match($argv[3], $g["gate"]) && $g["status"]==="PASS") { $n++; } } echo $n;' "$d/gates.jsonl" "$variant" "$re" 2>/dev/null || echo 99)
    local present
    present=$(php -r '$n=0; foreach (file($argv[1]) as $l) { $g=json_decode($l,true); if ($g["variant"]===$argv[2] && preg_match($argv[3], $g["gate"]) && $g["status"]==="UNPROVEN") { $n++; } } echo $n;' "$d/gates.jsonl" "$variant" "$re" 2>/dev/null || echo 0)
    rm -rf "${d:?}"
    if [[ "$rc" == "$want" && "$passes" == "0" && "$present" -ge 1 ]]; then
        report "selftest_failclosed_$name" "PASS (report exit $rc, $variant gate /$re/ UNPROVEN, no PASS)"
    else
        report "selftest_failclosed_$name" "FAIL (exit $rc want $want; PASS rows=$passes UNPROVEN rows=$present)"
        status=1
    fi
}
m_del_a04() { rm -f "$1/a04_state_bounds.PW.jsonl"; }
m_del_a05() { rm -f "$1/a05_redis.PW.jsonl"; }
m_del_a02() { rm -f "$1/a02_boundaries.S0.jsonl"; }
m_del_a03() { rm -f "$1/a03_rotation_ephemeral_backend.P2.jsonl"; }
m_del_a07() { rm -f "$1/a07_dec003_behavior.RL.jsonl"; }
m_del_cells() { grep -v '"stream":"X2"' "$1/a01_matrix.PW.jsonl" >"$1/x" && mv "$1/x" "$1/a01_matrix.PW.jsonl"; }
m_malformed() { printf '{"s":"A04.S3",broken\n' >>"$1/a04_state_bounds.A1.jsonl"; }
m_skip_redis() { echo SKIPPED >"$1/redis_status.txt"; rm -f "$1"/a05_redis.*.jsonl; }
m_no_status() { rm -f "$1/redis_status.txt"; }
fc_case a04_missing 5 PW '/^A\.4 /' m_del_a04
fc_case a05_missing 5 PW '/^A\.(4|5) /' m_del_a05
fc_case a02_missing 5 PW '/^A\.2 /' m_del_a02
fc_case a03_missing 5 P2 '/^A\.(1|3) /' m_del_a03
fc_case a07_missing 5 RL '/^A\.7 /' m_del_a07
fc_case matrix_cells_missing 5 PW '/^(A\.6|B\.|C\.|D\. )/' m_del_cells
fc_case malformed_json 5 A1 '/^A\.4 /' m_malformed
fc_case redis_intentionally_skipped 0 PW '/^A\.(4|5) /' m_skip_redis
fc_case redis_status_missing 5 PW '/^A\.4 /' m_no_status
# A recorded evidence problem in a REAL run must never be hidden by the golden diff: the real report must exit 0.
if [[ -s "$actual/report.stderr" ]]; then
    report "evidence_complete" "FAIL ($(head -c 200 "$actual/evidence_problems.txt" 2>/dev/null))"
    status=1
else
    report "evidence_complete" "PASS (no missing / malformed required evidence; redis_status=$(cat "$actual/redis_status.txt"))"
fi

exit "$status"
