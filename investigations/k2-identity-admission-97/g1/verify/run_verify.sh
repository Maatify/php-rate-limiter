#!/usr/bin/env bash
# G1 verification addendum (VERIFY_PLAN.md) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked
# file outside investigations/k2-identity-admission-97/g1/verify/.
#
#   bash investigations/k2-identity-admission-97/g1/verify/run_verify.sh            # everything, diffed against verify/expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g1/verify/run_verify.sh   # regenerate verify/expected/ (REVIEW the git diff)
#   REDIS=0 ...                                                                     # skip real Redis (R3-H becomes UNPROVEN)
#
# Step 1 re-runs the ORIGINAL study (../run.sh) and requires its archived evidence to compare clean: the addendum never replaces it.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
g1=$(cd "$here/.." && pwd)
repo=$(cd "$g1/../../.." && pwd)
actual="$g1/results/verify-actual"
work="$g1/results/verify-work"
expected="$here/expected"
mkdir -p "$actual" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.diff "$actual"/*.stderr "$actual"/summary_verify.md "$actual"/gates_verify.json
status=0
say() { printf '%-48s %s\n' "$1" "$2"; }

if [[ "${SKIP_ORIGINAL:-0}" != "1" ]]; then
    if bash "$g1/run.sh" >"$actual/original_run.out" 2>&1; then say original_study "PASS (../expected compares clean)"; else say original_study "FAIL (see results/verify-actual/original_run.out)"; status=1; fi
fi

case "$work" in "$g1/results/"*) rm -rf "${work:?}" ;; *) exit 2 ;; esac
bash "$here/build_verify.sh" "$work" >/dev/null
cp "$work/VERIFY_BUILD_INFO.txt" "$actual/verify_build_info.txt"
say verify_build PASS

run() { # build scenario
    local b=$1 sc=$2 o="$actual/$2.$1.jsonl" e="$actual/$2.$1.stderr"
    case $b in
        S0) env -u G1_ARM -u G1_BUILD LAB_ROOT="$repo" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || { say "$sc.$b" "FAIL (exit)"; status=1; return; } ;;
        CAND) env -u G1_BUILD G1_ARM=CAND LAB_ROOT="$work/candidate" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || { say "$sc.$b" "FAIL (exit)"; status=1; return; } ;;
        TRACE) G1_ARM=CAND G1_BUILD=TRACE LAB_ROOT="$work/trace" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || { say "$sc.$b" "FAIL (exit)"; status=1; return; } ;;
        MUTANT) G1_ARM=CAND G1_BUILD=MUTANT LAB_ROOT="$work/mutant" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || { say "$sc.$b" "FAIL (exit)"; status=1; return; } ;;
    esac
    [[ -s "$e" ]] && { say "$sc.$b" "FAIL (stderr)"; status=1; return; }
    rm -f "$e"
    say "$sc.$b" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
run TRACE v01_matrix
run CAND v02_c9_mixed
run CAND v03_c4_boundary
run CAND v04_detectors
run MUTANT v05_c10_mutant
run S0 v07_sweep
run CAND v07_sweep

# Real Redis: an instance owned by this script (no persistence, loopback, own directory), stopped on exit.
if [[ "${REDIS:-1}" == "1" ]] && command -v redis-server >/dev/null; then
    port=6790
    while (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; do port=$((port + 1)); done
    rdir="$actual/redis"
    rm -rf "$rdir"; mkdir -p "$rdir"
    redis-server --port "$port" --bind 127.0.0.1 --protected-mode yes --save "" --appendonly no --dir "$rdir" --daemonize yes --pidfile "$rdir/redis.pid" >/dev/null
    trap 'redis-cli -p '"$port"' shutdown nosave >/dev/null 2>&1 || true' EXIT
    for i in $(seq 1 50); do redis-cli -p "$port" ping >/dev/null 2>&1 && break; sleep 0.2; done
    G1_REDIS_PORT=$port php "$here/scenarios/v06_redis.php" >"$actual/v06_redis.jsonl" 2>"$actual/v06_redis.stderr" || status=1
else
    php "$here/scenarios/v06_redis.php" >"$actual/v06_redis.jsonl" 2>"$actual/v06_redis.stderr" || status=1
fi
[[ -s "$actual/v06_redis.stderr" ]] && { say v06_redis "FAIL (stderr)"; status=1; } || { rm -f "$actual/v06_redis.stderr"; say v06_redis ran; }

php "$here/report_verify.php" "$actual" "$actual" >"$actual/report_verify.out" || { say report_verify "FAIL (exit $?)"; status=1; }
cat "$actual/report_verify.out"

for f in "$actual"/*.jsonl "$actual/summary_verify.md" "$actual/gates_verify.json"; do
    n=$(basename "$f")
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$f" "$expected/$n"
    elif ! diff -u "$expected/$n" "$f" >"$actual/$n.diff" 2>&1; then
        say "$n" "DIFFERS (results/verify-actual/$n.diff)"
        status=1
    else
        rm -f "$actual/$n.diff"
    fi
done
[[ "${UPDATE:-0}" == "1" ]] && say verify_expected UPDATED || say verify_expected "compared"
exit $status
