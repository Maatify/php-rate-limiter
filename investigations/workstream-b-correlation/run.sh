#!/usr/bin/env bash
# Workstream B evidence runner (#97 / #100). Evidence-only: never modifies src/, docs/ or any tracked file
# outside investigations/workstream-b-correlation/.
#
#   bash investigations/workstream-b-correlation/run.sh            # run everything and diff against expected/
#   UPDATE=1 bash .../run.sh                                       # regenerate expected/ (review the diff!)
#   LAB_ROOT=/path/to/package-tree bash .../run.sh                 # exercise a different tree (e.g. a tag archive)
#   RUN_PHPUNIT=1 bash .../run.sh                                  # additionally run the 3 existing correlation test files
#   REDIS_SPOTCHECK=0 bash .../run.sh                              # skip the real-Redis stage
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
    php "$here/$script" >"$actual/$name.jsonl" 2>"$actual/$name.stderr"
    if [[ -s "$actual/$name.stderr" ]]; then
        report "$name" "FAIL (stderr not empty: results/actual/$name.stderr)"
        status=1
        continue
    fi
    rm -f "$actual/$name.stderr"
    compare "$name"
done

# Cross-check gate: the offline model's S0 must equal the real pipeline on every stream.
if grep -v '"mismatches":0' "$actual/model_crosscheck.jsonl" >/dev/null; then
    report "model_crosscheck" "FAIL (model diverges from the real pipeline)"
    status=1
fi

if [[ "${REDIS_SPOTCHECK:-1}" == "1" ]]; then
    port=${REDIS_PORT:-6399}
    if ! command -v redis-server >/dev/null 2>&1; then
        report "s08_redis_spotcheck" "SKIPPED (redis-server not installed)"
    elif (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
        report "s08_redis_spotcheck" "SKIPPED (port $port already in use; this stage FLUSHDBs its own instance only — set REDIS_PORT)"
    else
        redis-server --port "$port" --save "" --appendonly no --dir "$actual" --daemonize yes --pidfile "$actual/redis.pid" >/dev/null
        trap 'kill "$(cat "$actual/redis.pid" 2>/dev/null)" 2>/dev/null || true' EXIT
        sleep 1
        REDIS_PORT=$port php "$here/scenarios/s08_redis_spotcheck.php" >"$actual/s08_redis_spotcheck.jsonl" 2>"$actual/s08_redis_spotcheck.stderr" || true
        if [[ -s "$actual/s08_redis_spotcheck.stderr" ]]; then
            report "s08_redis_spotcheck" "FAIL (stderr: results/actual/s08_redis_spotcheck.stderr)"
            status=1
        else
            rm -f "$actual/s08_redis_spotcheck.stderr"
            compare "s08_redis_spotcheck"
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
