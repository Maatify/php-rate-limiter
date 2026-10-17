#!/usr/bin/env bash
# G2 proof acquisition (#97) — reproduction runner (PLAN.md §8). Evidence only; writes outside expected/ only into the
# git-ignored results/.
#
#   bash investigations/k2-identity-admission-97/g2/proof_acquisition/run.sh            # run, diff against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g2/proof_acquisition/run.sh   # regenerate expected/ (REVIEW the diff)
#
# Exit 0: outputs identical to expected/ and every mandatory gate passes.
# Exit 3: outputs identical to expected/, harness valid, and at least one pre-registered mandatory gate FAILS (a result).
# Exit 1: an output differs from expected/, a scenario failed, or the harness is invalid (a detector did not fire).
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
g1=$(cd "$here/../../g1" && pwd)
results="$here/results"
actual="$results/actual"
work="$results/work"
expected="$here/expected"
mkdir -p "$actual" "$expected" "$results/redis"
rm -f "$actual"/*
say() { printf '%-34s %s\n' "$1" "$2"; }
status=0

# ---- G1 DAOF pipeline tree (G1 builders, sha-256 pinned; the repository tree is never modified) ----
rm -rf "$work" && mkdir -p "$work"
bash "$g1/verify/build_verify.sh" "$work" >/dev/null
bash "$g1/verify/build_da.sh" "$work" >/dev/null
bash "$g1/verify/build_flood.sh" "$work" >/dev/null
say daof_build "PASS ($(shasum -a 256 "$work/daof/src/Service/EvaluationPipeline.php" | cut -c1-12)…)"

# ---- ephemeral Redis owned by this runner, on a free local port ----
port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", (string) stream_socket_get_name($s, false))[1];')
redis-server --port "$port" --bind 127.0.0.1 --save '' --appendonly no --daemonize yes \
    --dir "$results/redis" --pidfile "$results/redis/redis.pid" --logfile "$results/redis/redis.log"
cleanup() { redis-cli -p "$port" shutdown nosave >/dev/null 2>&1 || true; }
trap cleanup EXIT
for _ in $(seq 1 50); do redis-cli -p "$port" ping >/dev/null 2>&1 && break; sleep 0.1; done

run() { # name command...
    local n=$1; shift
    if "$@" >"$actual/$n.jsonl" 2>"$actual/$n.stderr" && [[ ! -s "$actual/$n.stderr" ]]; then
        rm -f "$actual/$n.stderr"
        say "$n" "ran ($(wc -l <"$actual/$n.jsonl" | tr -d ' ') rows)"
    else
        say "$n" "FAIL (see results/actual/$n.stderr)"
        status=1
    fi
}
pipe=(env G1_ARM=CAND G1_BUILD=DAOF LAB_ROOT="$work/daof")
run ab php "$here/scenarios/ab_acq.php"
run c php "$here/scenarios/c_integrity.php"
run d php "$here/scenarios/d_mult.php"
run e "${pipe[@]}" php "$here/scenarios/e_pipeline.php"
run g "${pipe[@]}" php "$here/scenarios/g_modes.php"
run f env G2_REDIS_PORT="$port" php "$here/scenarios/f_redis.php"

set +e
php "$here/report.php" "$actual" "$actual" >"$actual/report.out"
rc=$?
set -e
case $rc in
    0) say gates "PASS (every mandatory gate)" ;;
    3) say gates "harness valid; a pre-registered mandatory gate FAILS (see gates.json)" ;;
    *) say gates "HARNESS INVALID or report error (exit $rc)"; status=1 ;;
esac

for f in "$actual"/*.jsonl "$actual/summary.md" "$actual/gates.json"; do
    n=$(basename "$f")
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$f" "$expected/$n"
    elif ! diff -u "$expected/$n" "$f" >"$actual/$n.diff" 2>&1; then
        say "$n" "DIFFERS (results/actual/$n.diff)"
        status=1
    else
        rm -f "$actual/$n.diff"
    fi
done
[[ "${UPDATE:-0}" == "1" ]] && say expected UPDATED || say expected compared
{
    echo "head: $(git -C "$here" rev-parse HEAD)"
    php -v | head -1
    redis-server --version
} | tee "$actual/toolchain.txt"
if [[ $status -ne 0 ]]; then exit 1; fi
if [[ $rc -eq 3 ]]; then exit 3; fi
exit 0
