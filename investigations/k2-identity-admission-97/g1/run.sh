#!/usr/bin/env bash
# G1 isolated comparative study (#97) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked file
# outside investigations/k2-identity-admission-97/g1/.
#
#   bash investigations/k2-identity-admission-97/g1/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g1/run.sh   # regenerate expected/ (REVIEW the git diff)
#   REDIS=0 bash .../run.sh                                           # skip real Redis (M06 rows become UNPROVEN, never PASS)
#
# Runtimes (never merged): S0 = the repository src/ (unpatched, sha-256 asserted); CAND = isolated tree from build_candidate.sh,
# selected with G1_ARM=CAND (the patched pipeline refuses to run without it).
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../.." && pwd)
actual="$here/results/actual"
work="$here/results/work"
expected="$here/expected"
BASE_SHA=4c6ca618d8206ba502961eaf06ffb82a5d61b50d
mkdir -p "$actual" "$work" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.diff "$actual"/*.stderr "$actual"/summary.md "$actual"/gates.json
status=0
say() { printf '%-48s %s\n' "$1" "$2"; }

[[ -f "$repo/vendor/autoload.php" ]] || { echo "no vendor/ — run 'composer install' first" >&2; exit 2; }
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { say baseline_is_ancestor FAIL; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src tests composer.json docs)" || -n "$(git -C "$repo" status --porcelain -- src tests composer.json docs)" ]]; then
    say baseline_src_identical "FAIL (src/, tests/, docs/ or composer.json differ from $BASE_SHA or are dirty)"
    exit 4
fi
say baseline_src_identical "PASS ($BASE_SHA)"
{
    echo "baseline: $BASE_SHA"
    echo "repo_head: $(git -C "$repo" rev-parse HEAD)"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "redis: $(redis-server --version 2>/dev/null | cut -d' ' -f3 || echo n/a)"
} >"$actual/versions.txt"

cand="$work/candidate"
case "$cand" in "$here/results/work/"*) rm -rf "${cand:?}" ;; *) exit 2 ;; esac
bash "$here/build_candidate.sh" "$cand" >/dev/null
cp "$cand/BUILD_INFO.txt" "$actual/candidate_build_info.txt"
say candidate_build PASS

run() { # runtime scenario
    local rt=$1 sc=$2 o="$actual/$2.$1.jsonl" e="$actual/$2.$1.stderr"
    if [[ $rt == S0 ]]; then
        env -u G1_ARM LAB_ROOT="$repo" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || { say "$sc.$rt" "FAIL (exit)"; status=1; return; }
    else
        G1_ARM=CAND LAB_ROOT="$cand" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || { say "$sc.$rt" "FAIL (exit)"; status=1; return; }
    fi
    [[ -s "$e" ]] && { say "$sc.$rt" "FAIL (stderr)"; status=1; return; }
    rm -f "$e"
    say "$sc.$rt" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
for sc in m01_matrix m02_device m04_lifecycle; do run S0 "$sc"; done
for sc in m01_matrix m02_device m03_capacity m04_lifecycle m05_host_modes m07_source_ceiling; do run CAND "$sc"; done

# Real Redis: an instance owned by this script (no persistence, loopback, own directory), stopped on exit.
if [[ "${REDIS:-1}" == "1" ]] && command -v redis-server >/dev/null; then
    port=6790
    while (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; do port=$((port + 1)); done
    rdir="$actual/redis"
    rm -rf "$rdir"; mkdir -p "$rdir"
    redis-server --port "$port" --bind 127.0.0.1 --protected-mode yes --save "" --appendonly no --dir "$rdir" --daemonize yes --pidfile "$rdir/redis.pid" >/dev/null
    trap 'redis-cli -p '"$port"' shutdown nosave >/dev/null 2>&1 || true' EXIT
    for i in $(seq 1 50); do redis-cli -p "$port" ping >/dev/null 2>&1 && break; sleep 0.2; done
    G1_REDIS_PORT=$port php "$here/scenarios/m06_redis.php" >"$actual/m06_redis.jsonl" 2>"$actual/m06_redis.stderr" || status=1
else
    php "$here/scenarios/m06_redis.php" >"$actual/m06_redis.jsonl" 2>"$actual/m06_redis.stderr" || status=1
fi
[[ -s "$actual/m06_redis.stderr" ]] && { say m06_redis "FAIL (stderr)"; status=1; } || { rm -f "$actual/m06_redis.stderr"; say m06_redis ran; }

php "$here/report.php" "$actual" "$actual" >"$actual/report.out" || { say report "FAIL (exit $?)"; status=1; }
cat "$actual/report.out"

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
[[ "${UPDATE:-0}" == "1" ]] && say expected UPDATED || say expected "compared"
exit $status
