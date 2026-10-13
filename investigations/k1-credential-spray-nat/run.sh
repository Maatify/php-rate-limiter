#!/usr/bin/env bash
# #106 — K1 credential-spray / shared-NAT evidence: reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked file
# outside investigations/k1-credential-spray-nat/.
#
#   bash investigations/k1-credential-spray-nat/run.sh                 # everything, diffed against expected/
#   UPDATE=1 bash investigations/k1-credential-spray-nat/run.sh        # regenerate expected/ (REVIEW the git diff!)
#   REDIS_SPOTCHECK=0 bash investigations/k1-credential-spray-nat/run.sh   # skip the real-Redis stage (its gate becomes UNPROVEN)
#   REDIS_PORT=6500 bash .../run.sh                                    # first port to try for the instances run.sh starts
#
# Runtime level: S0_RUNTIME_OBSERVED only — the repository's UNPATCHED EvaluationPipeline (sha-256 asserted by every scenario). The isolated TEST-ONLY
# controls (arms B/C/D) live in the injected correlation store (lib/k1.php); no candidate tree and no patch exist in this study.
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="${here:?}/results/actual"
expected="${here:?}/expected"
BASE_SHA=4d9495ded05f647ce0421352781ce948ceeaa606
mkdir -p "$actual" "$expected"
find "${actual:?}" -maxdepth 1 -type f \( -name '*.jsonl' -o -name '*.diff' -o -name '*.stderr' -o -name '*.txt' -o -name 'summary.md' -o -name 'report.out' \) -delete
rm -rf "${actual:?}"/redis-* "${actual:?}"/failclosed-*

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
    echo "no vendor/ in $repo — run 'composer install' first." >&2
    exit 2
fi
status=0
report() { printf '%-48s %s\n' "$1" "$2"; }

# ---- 0. baseline + provenance ------------------------------------------------------------------------------------------------------
{
    echo "baseline_expected: $BASE_SHA"
    echo "repo_head: $(git -C "$repo" rev-parse HEAD)"
    echo "pipeline_sha256_repo: $(shasum -a 256 "$repo/src/Service/EvaluationPipeline.php" | cut -d' ' -f1)"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "composer: $(composer --version 2>/dev/null | head -1 || echo n/a)"
    echo "redis: $(redis-server --version 2>/dev/null || echo n/a)"
    echo "phpunit: $(php "$repo/vendor/bin/phpunit" --version 2>/dev/null | head -1 || echo n/a)"
} >"$actual/versions.txt"
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { report "baseline_is_ancestor" "FAIL ($BASE_SHA is not an ancestor of HEAD)"; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src composer.json composer.lock 2>/dev/null)" ]]; then
    report "baseline_src_identical" "FAIL (src/ or composer files differ from $BASE_SHA)"
    exit 4
fi
if [[ -n "$(git -C "$repo" status --porcelain -- src tests docs composer.json composer.lock)" ]]; then
    report "baseline_src_clean" "FAIL (uncommitted changes under src/ tests/ docs/ or composer files)"
    exit 4
fi
report "baseline_src_identical" "PASS (src/, composer.json, composer.lock identical to $BASE_SHA; src/tests/docs clean)"

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

# ---- 1. deterministic reference-store scenarios --------------------------------------------------------------------------------------
scenarios=(k02_matrix k03_boundaries_recovery k04_lifecycle_trust k05_ipv6_rotation_subject k06_bounds k08_seed_sensitivity)
for sc in "${scenarios[@]}"; do
    if ! env -u WSB_CANDIDATE LAB_ROOT="$repo" php "$here/scenarios/$sc.php" >"$actual/$sc.jsonl" 2>"$actual/$sc.stderr"; then
        report "$sc" "FAIL (non-zero exit; see results/actual/$sc.jsonl / .stderr)"
        status=1
        continue
    fi
    if [[ -s "$actual/$sc.stderr" ]]; then
        report "$sc" "FAIL (stderr not empty)"
        status=1
        continue
    fi
    rm -f "${actual:?}/$sc.stderr"
    compare "$sc"
done

# ---- 2. real Redis (instances owned and proven by this script; PR #103 F103-01 safety model) --------------------------------------------
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
        report "k07_redis" "SKIPPED (redis not installed) -> real-Redis claims UNPROVEN"
    else
        own_port=$(free_port "${REDIS_PORT:-6399}")
        decoy_port=$(free_port $((own_port + 1)))
        start_redis own "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
        start_redis decoy "$decoy_port"; decoy_pid=$REDIS_PID_VALUE; decoy_dir=$REDIS_DIR_VALUE
        trap 'stop_redis "${own_pid:-}"; stop_redis "${decoy_pid:-}"' EXIT
        redis-cli -h 127.0.0.1 -p "$decoy_port" set decoy:sentinel precious >/dev/null
        safety=PASS
        set +e
        # (1) a foreign server selected through inherited REDIS_HOST/REDIS_PORT, (2) wrong pid, (3) wrong dir, (4) non-empty server with matching pid+dir
        REDIS_HOST=127.0.0.1 REDIS_PORT=$decoy_port LAB_ROOT="$repo" php "$here/scenarios/k07_redis.php" >/dev/null 2>&1; rc1=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$own_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/k07_redis.php" >/dev/null 2>&1; rc2=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$own_dir LAB_ROOT="$repo" php "$here/scenarios/k07_redis.php" >/dev/null 2>&1; rc3=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$decoy_dir LAB_ROOT="$repo" php "$here/scenarios/k07_redis.php" >/dev/null 2>&1; rc4=$?
        set -e
        [[ $rc1 -eq 3 && $rc2 -eq 3 && $rc3 -eq 3 && $rc4 -eq 3 ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" get decoy:sentinel)" == "precious" ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" dbsize | tr -dc '0-9')" == "1" ]] || safety=FAIL
        report "k07_redis_safety" "$safety (foreign/mismatched/non-empty server refused rc=$rc1,$rc2,$rc3,$rc4; decoy sentinel intact)"
        [[ $safety == PASS ]] || status=1
        if [[ $safety == PASS ]]; then
            echo RAN >"$actual/redis_status.txt"
            name="k07_redis"
            for attempt in 1 2 3; do
                stop_redis "$own_pid"
                own_port=$(free_port "$own_port")
                start_redis "own-$attempt" "$own_port"; own_pid=$REDIS_PID_VALUE; own_dir=$REDIS_DIR_VALUE
                env -u WSB_CANDIDATE LAB_ROOT="$repo" REDIS_HOST=198.51.100.1 REDIS_PORT=1 WSB_REDIS_PORT="$own_port" WSB_REDIS_PID="$own_pid" WSB_REDIS_DIR="$own_dir" \
                    php "$here/scenarios/k07_redis.php" >"$actual/$name.jsonl" 2>"$actual/$name.stderr" || true
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
        fi
    fi
fi

# ---- 3. gates + summary ---------------------------------------------------------------------------------------------------------------
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

# ---- 4. self-tests: the assertions and gates must FAIL when the evidence, a control or a boundary is damaged ------------------------------
selftest() { # name command...  (the command must exit non-zero AND record at least one violated assertion)
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
# the isolated control must really disable K1 spray; the K1 window boundary must be exact
selftest broken_control_C_does_not_disable_spray env K1_SELFTEST_C_NOOP=1 LAB_ROOT="$repo" php "$here/scenarios/k02_matrix.php"
selftest moved_600s_window_boundary env K1_SELFTEST_TTL_SHIFT=1 LAB_ROOT="$repo" php "$here/scenarios/k03_boundaries_recovery.php"
# provenance: a candidate label must be refused with exit 4 (this study never runs a patched tree)
selftest_exit candidate_label_refused 4 env WSB_CANDIDATE=EQL LAB_ROOT="$repo" php "$here/scenarios/k02_matrix.php"
# fail-closed evidence: delete / damage representative evidence and require report.php to refuse (exit 5) and write no PASS gate
fc_check() { # name mutation-command-on-copy
    local name=$1 dir="$actual/failclosed-$1" rc
    rm -rf "${dir:?}"
    mkdir -p "$dir"
    cp "$actual"/*.jsonl "$actual"/redis_status.txt "$dir"/ 2>/dev/null || true
    rm -f "$dir"/selftest_*.jsonl
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
fc_check missing_matrix 'rm -f "$dir/k02_matrix.jsonl"'
fc_check missing_boundaries 'rm -f "$dir/k03_boundaries_recovery.jsonl"'
fc_check missing_trust 'rm -f "$dir/k04_lifecycle_trust.jsonl"'
fc_check missing_ipv6_rotation 'rm -f "$dir/k05_ipv6_rotation_subject.jsonl"'
fc_check missing_bounds 'rm -f "$dir/k06_bounds.jsonl"'
fc_check missing_seed_sensitivity 'rm -f "$dir/k08_seed_sensitivity.jsonl"'
fc_check one_matrix_cell_deleted 'grep -v "\"stream\":\"D2\",\"class\":\"benign\",\"shape\":\"login\",\"flow\":\"pre+out\"" "$dir/k02_matrix.jsonl" >"$dir/t" && mv "$dir/t" "$dir/k02_matrix.jsonl"'
fc_check matrix_field_removed 'sed -i.bak "s/\"sprayThresholdRequests\"/\"zzz\"/g" "$dir/k02_matrix.jsonl" && rm -f "$dir/k02_matrix.jsonl.bak"'
fc_check assertion_row_deleted 'grep -v "\"assert\":\"B5 " "$dir/k03_boundaries_recovery.jsonl" >"$dir/t" && mv "$dir/t" "$dir/k03_boundaries_recovery.jsonl"'
fc_check assertion_failed 'sed -i.bak "s/\"ok\":true/\"ok\":false/" "$dir/k04_lifecycle_trust.jsonl" && rm -f "$dir/k04_lifecycle_trust.jsonl.bak"'
fc_check redis_evidence_deleted 'rm -f "$dir/k07_redis.jsonl"; echo RAN >"$dir/redis_status.txt"'

if [[ "${UPDATE:-0}" == "1" ]]; then
    report "UPDATE" "expected/ regenerated — review the git diff before committing"
fi
exit "$status"
