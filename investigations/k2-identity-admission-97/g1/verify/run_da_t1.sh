#!/usr/bin/env bash
# G1 targeted step (DA_T1_PLAN.md) — reproduction runner. Evidence only; never modifies tracked files outside g1/verify/.
#
#   bash investigations/k2-identity-admission-97/g1/verify/run_da_t1.sh            # everything, diffed against verify/expected_da_t1/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g1/verify/run_da_t1.sh   # regenerate (REVIEW the git diff)
#
# Step 1 runs run_verify.sh (which itself re-runs the original ../run.sh): both archived evidence sets must compare clean.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
g1=$(cd "$here/.." && pwd)
repo=$(cd "$g1/../../.." && pwd)
actual="$g1/results/da-t1-actual"
work="$g1/results/verify-work"
expected="$here/expected_da_t1"
mkdir -p "$actual" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.diff "$actual"/*.stderr "$actual"/summary_da_t1.md "$actual"/gates_da_t1.json
status=0
say() { printf '%-48s %s\n' "$1" "$2"; }

if bash "$here/run_verify.sh" >"$actual/verify_run.out" 2>&1; then say original_and_addendum "PASS (both compare clean)"; else say original_and_addendum "FAIL (see results/da-t1-actual/verify_run.out)"; status=1; fi
bash "$here/build_da.sh" "$work" >/dev/null
say da_build PASS

run() { # build tree scenario-path output-name
    local b=$1 t=$2 sc=$3 n=$4 o="$actual/$4.$1.jsonl" e="$actual/$4.$1.stderr"
    G1_ARM=CAND G1_BUILD=$b LAB_ROOT="$work/$t" php "$sc" >"$o" 2>"$e" || { say "$n.$b" "FAIL (exit)"; status=1; return; }
    [[ -s "$e" ]] && { say "$n.$b" "FAIL (stderr)"; status=1; return; }
    rm -f "$e"
    say "$n.$b" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
for bt in TRACE:trace DAM:dam DAO:dao; do
    b=${bt%%:*}; t=${bt##*:}
    run "$b" "$t" "$here/scenarios/x01_matrix.php" x01_matrix
    run "$b" "$t" "$here/scenarios/x02_owner.php" x02_owner
    run "$b" "$t" "$g1/scenarios/m02_device.php" x04_m02_device
    run "$b" "$t" "$g1/scenarios/m04_lifecycle.php" x04_m04_lifecycle
done
run TRACE trace "$here/scenarios/x03_unbound.php" x03_unbound
run DAO dao "$here/scenarios/x03_unbound.php" x03_unbound

if [[ "${REDIS:-1}" == "1" ]] && command -v redis-server >/dev/null; then
    port=6790
    while (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; do port=$((port + 1)); done
    rdir="$actual/redis"
    rm -rf "$rdir"; mkdir -p "$rdir"
    redis-server --port "$port" --bind 127.0.0.1 --protected-mode yes --save "" --appendonly no --dir "$rdir" --daemonize yes --pidfile "$rdir/redis.pid" >/dev/null
    trap 'redis-cli -p '"$port"' shutdown nosave >/dev/null 2>&1 || true' EXIT
    for i in $(seq 1 50); do redis-cli -p "$port" ping >/dev/null 2>&1 && break; sleep 0.2; done
    G1_REDIS_PORT=$port php "$here/scenarios/t1_fixtures.php" >"$actual/t1_fixtures.jsonl" 2>"$actual/t1_fixtures.stderr" || status=1
else
    php "$here/scenarios/t1_fixtures.php" >"$actual/t1_fixtures.jsonl" 2>"$actual/t1_fixtures.stderr" || status=1
fi
[[ -s "$actual/t1_fixtures.stderr" ]] && { say t1_fixtures "FAIL (stderr)"; status=1; } || { rm -f "$actual/t1_fixtures.stderr"; say t1_fixtures ran; }

php "$here/report_da_t1.php" "$actual" "$actual" >"$actual/report_da_t1.out" || { say report_da_t1 "FAIL (exit $?)"; status=1; }
cat "$actual/report_da_t1.out"

for f in "$actual"/*.jsonl "$actual/summary_da_t1.md" "$actual/gates_da_t1.json"; do
    n=$(basename "$f")
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$f" "$expected/$n"
    elif ! diff -u "$expected/$n" "$f" >"$actual/$n.diff" 2>&1; then
        say "$n" "DIFFERS (results/da-t1-actual/$n.diff)"
        status=1
    else
        rm -f "$actual/$n.diff"
    fi
done
[[ "${UPDATE:-0}" == "1" ]] && say da_t1_expected UPDATED || say da_t1_expected "compared"
exit $status
