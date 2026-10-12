#!/usr/bin/env bash
# Workstream B evidence runner (#97 / #100). Evidence-only: never modifies src/, docs/ or any tracked file
# outside investigations/workstream-b-correlation/.
#
#   bash investigations/workstream-b-correlation/run.sh            # run everything and diff against expected/
#   UPDATE=1 bash .../run.sh                                       # regenerate expected/ (review the diff!)
#   LAB_ROOT=/path/to/package-tree bash .../run.sh                 # exercise a different tree (e.g. a tag archive)
#   RUN_PHPUNIT=1 bash .../run.sh                                  # additionally run the 3 existing correlation test files
#   REDIS_SPOTCHECK=0 bash .../run.sh                              # skip the real-Redis stage
#   REDIS_PORT=6500 bash .../run.sh                                # first port to try for the instances run.sh itself starts
#
# Stages are independent and deterministic (controlled clock, fixed fixtures, own LCG; no wall clock, no mt_rand).
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
export LAB_ROOT=${LAB_ROOT:-$(cd "$here/../.." && pwd)}
actual="$here/results/actual"
expected="$here/expected"
mkdir -p "$actual"

if [[ ! -f "$LAB_ROOT/vendor/autoload.php" ]]; then
    echo "LAB_ROOT=$LAB_ROOT has no vendor/ — run 'composer install' there first." >&2
    exit 2
fi

{
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "composer: $(composer --version 2>/dev/null | head -1 || echo n/a)"
    echo "lab_root: ${LAB_ROOT}"
    if git -C "$LAB_ROOT" rev-parse HEAD >/dev/null 2>&1; then
        echo "lab_root_head: $(git -C "$LAB_ROOT" rev-parse HEAD)"
    fi
    echo "phpunit: $(php "$LAB_ROOT/vendor/bin/phpunit" --version 2>/dev/null | head -1 || echo n/a)"
} >"$actual/versions.txt"

stages=(
    "s01_dilution:scenarios/s01_dilution.php"
    "s02_churn:scenarios/s02_churn.php"
    "s03_recovery_boundaries:scenarios/s03_recovery_boundaries.php"
    "s04_lifecycle_commands:scenarios/s04_lifecycle_commands.php"
    "s05_dec003_cycles:scenarios/s05_dec003_cycles.php"
    "s06_scope_rotation_caps:scenarios/s06_scope_rotation_caps.php"
    "s07_compensation:scenarios/s07_compensation.php"
    "model_crosscheck:model/crosscheck.php"
    "model_matrix:model/run_matrix.php"
    "model_mechanisms:model/mechanisms.php"
    "model_regression:model/regression.php"
    "model_state_bounds:model/state_bounds.php"
)

status=0
report() { printf '%-28s %s\n' "$1" "$2"; }

compare() {
    local name=$1
    if [[ "${UPDATE:-0}" == "1" ]]; then
        mkdir -p "$expected"
        cp "$actual/$name.jsonl" "$expected/$name.jsonl"
        report "$name" "UPDATED"
    elif diff -u "$expected/$name.jsonl" "$actual/$name.jsonl" >"$actual/$name.diff"; then
        rm -f "$actual/$name.diff"
        report "$name" "PASS ($(wc -l <"$actual/$name.jsonl" | tr -d ' ') lines identical)"
    else
        report "$name" "FAIL (see results/actual/$name.diff)"
        status=1
    fi
}

for stage in "${stages[@]}"; do
    name=${stage%%:*}
    script=${stage#*:}
    if ! php "$here/$script" >"$actual/$name.jsonl" 2>"$actual/$name.stderr"; then
        report "$name" "FAIL (non-zero exit: an in-script assertion failed or the script crashed; see results/actual/$name.jsonl / .stderr)"
        status=1
        continue
    fi
    if [[ -s "$actual/$name.stderr" ]]; then
        report "$name" "FAIL (stderr not empty: results/actual/$name.stderr)"
        status=1
        continue
    fi
    rm -f "$actual/$name.stderr"
    compare "$name"
done

# Cross-check gate: every compared observable of the model's S0 must equal the real pipeline (see crosscheck.php for what is NOT compared).
if grep -vE '"mismatches":\{"blocked":0,"reached":0,"stepDecision":0,"stepLevel":0,"stepRetry":0,"k2Remaining":0,"k3Remaining":0\},.*"cycleMismatch":0' "$actual/model_crosscheck.jsonl" >/dev/null; then
    report "model_crosscheck" "FAIL (model diverges from the real pipeline)"
    status=1
fi

# --- Real-Redis stage (F103-01): only against Redis instances THIS script starts, on loopback, in a directory it creates. ---
# The PHP stage never reads REDIS_HOST/REDIS_PORT and never flushes; it accepts the server only if its process_id and working
# directory match what this script started and it is empty. REDIS_PORT here only chooses the first port to try.
free_port() {
    local p=$1
    while (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; do p=$((p + 1)); done
    echo "$p"
}
start_redis() { # name port -> sets REDIS_PID_VALUE REDIS_DIR_VALUE
    local name=$1 port=$2
    REDIS_DIR_VALUE="$actual/redis-$name"
    rm -rf "$REDIS_DIR_VALUE"
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
stop_redis() { # pid: SIGTERM, then wait for the process to exit so its pidfile cleanup cannot race a successor
    local pid=${1:-} i=0
    [[ -n "$pid" ]] || return 0
    kill "$pid" 2>/dev/null || return 0
    while kill -0 "$pid" 2>/dev/null && [[ $i -lt 50 ]]; do i=$((i + 1)); sleep 0.1; done
    return 0
}

if [[ "${REDIS_SPOTCHECK:-1}" == "1" ]]; then
    if ! command -v redis-server >/dev/null 2>&1 || ! command -v redis-cli >/dev/null 2>&1; then
        report "s08_redis_spotcheck" "SKIPPED (redis-server/redis-cli not installed)"
    else
        own_port=$(free_port "${REDIS_PORT:-6399}")
        decoy_port=$(free_port $((own_port + 1)))
        start_redis own "$own_port"
        own_pid=$REDIS_PID_VALUE
        own_dir=$REDIS_DIR_VALUE
        start_redis decoy "$decoy_port"
        decoy_pid=$REDIS_PID_VALUE
        trap 'stop_redis "${own_pid:-}"; stop_redis "${decoy_pid:-}"' EXIT

        # Safety proof: a FOREIGN server (decoy holding a sentinel key) selected through inherited REDIS_HOST/REDIS_PORT, or
        # through a WSB_REDIS_PORT whose pid/dir do not match, must be refused and left untouched.
        redis-cli -h 127.0.0.1 -p "$decoy_port" set decoy:sentinel precious >/dev/null
        safety=PASS
        set +e
        REDIS_HOST=127.0.0.1 REDIS_PORT=$decoy_port php "$here/scenarios/s08_redis_spotcheck.php" >/dev/null 2>&1; rc1=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$own_pid WSB_REDIS_DIR=$own_dir php "$here/scenarios/s08_redis_spotcheck.php" >/dev/null 2>&1; rc2=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$own_dir php "$here/scenarios/s08_redis_spotcheck.php" >/dev/null 2>&1; rc3=$?
        WSB_REDIS_PORT=$decoy_port WSB_REDIS_PID=$decoy_pid WSB_REDIS_DIR=$REDIS_DIR_VALUE php "$here/scenarios/s08_redis_spotcheck.php" >/dev/null 2>&1; rc4=$?
        set -e
        [[ $rc1 -eq 3 && $rc2 -eq 3 && $rc3 -eq 3 ]] || safety=FAIL
        # rc4: matching pid+dir but a non-empty server (sentinel present) must also be refused.
        [[ $rc4 -eq 3 ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" dbsize)" == "(integer) 1" || "$(redis-cli -h 127.0.0.1 -p "$decoy_port" dbsize)" == "1" ]] || safety=FAIL
        [[ "$(redis-cli -h 127.0.0.1 -p "$decoy_port" get decoy:sentinel)" == "precious" ]] || safety=FAIL
        report "s08_redis_safety" "$safety (foreign/mismatched/non-empty server refused rc=$rc1,$rc2,$rc3,$rc4; decoy sentinel intact)"
        [[ $safety == PASS ]] || status=1

        # Real run on a FRESH owned instance (retried on a fresh instance if Redis-owned time ticks mid-scenario), with a
        # hostile inherited REDIS_HOST that MUST be ignored.
        if [[ $safety == PASS ]]; then
            stop_redis "$own_pid"
            ok_run=0
            for attempt in 1 2 3; do
                own_port=$(free_port "$own_port")
                start_redis "own-run$attempt" "$own_port"
                own_pid=$REDIS_PID_VALUE
                own_dir=$REDIS_DIR_VALUE
                REDIS_HOST=198.51.100.1 REDIS_PORT=1 WSB_REDIS_PORT=$own_port WSB_REDIS_PID=$own_pid WSB_REDIS_DIR=$own_dir \
                    php "$here/scenarios/s08_redis_spotcheck.php" >"$actual/s08_redis_spotcheck.jsonl" 2>"$actual/s08_redis_spotcheck.stderr" || true
                if [[ ! -s "$actual/s08_redis_spotcheck.stderr" ]] && diff -q "$expected/s08_redis_spotcheck.jsonl" "$actual/s08_redis_spotcheck.jsonl" >/dev/null 2>&1; then
                    ok_run=1
                    break
                fi
                [[ "${UPDATE:-0}" == "1" && ! -s "$actual/s08_redis_spotcheck.stderr" ]] && { ok_run=1; break; }
                stop_redis "$own_pid"
            done
            if [[ -s "$actual/s08_redis_spotcheck.stderr" ]]; then
                report "s08_redis_spotcheck" "FAIL (stderr: results/actual/s08_redis_spotcheck.stderr)"
                status=1
            else
                rm -f "$actual/s08_redis_spotcheck.stderr"
                compare "s08_redis_spotcheck"
            fi
        fi
    fi
fi

if [[ "${RUN_PHPUNIT:-0}" == "1" ]]; then
    (cd "$LAB_ROOT" && php vendor/bin/phpunit \
        --log-junit "$actual/phpunit-correlation.xml" \
        tests/Integration/Correlation/CorrelationBehaviorTest.php \
        tests/Integration/Correlation/BoundedCorrelationCoverageTest.php \
        tests/Integration/Correlation/Ipv6AdaptiveAggregationTest.php >"$actual/phpunit-correlation.txt" 2>&1) || status=1
    php -r '$x = simplexml_load_file($argv[1]); $t = $x->testsuite; printf("phpunit-correlation: tests=%s assertions=%s failures=%s errors=%s\n", $t["tests"], $t["assertions"], $t["failures"], $t["errors"]);' "$actual/phpunit-correlation.xml"
fi

exit "$status"
