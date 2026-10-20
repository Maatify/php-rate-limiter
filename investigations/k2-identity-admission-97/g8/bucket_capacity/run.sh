#!/usr/bin/env bash
# G8 bucket capacity (#97, before 5-c) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/k2-identity-admission-97/g8/bucket_capacity/. Every request is decided by the production engine of main@05922dd through the 5-b3b
# harness (tests/Support/Admission), used unchanged; the archives are read, never written.
#
#   bash investigations/k2-identity-admission-97/g8/bucket_capacity/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g8/bucket_capacity/run.sh   # regenerate expected/ (REVIEW the git diff)
#
# Exit: 0 valid run (gate outcomes in gates.json / summary.md); 5 harness validity (V1-V3) or detector D failed; 6 input assertion (IN);
# 4 baseline; 1 an output differs from expected/ or a scenario fails; 2 setup.
set -euo pipefail
# Every php call: no scan directory (no local conf.d), 1G, from the very first call. The binary is resolved once: under bash 3.2 a
# `command php … || rc=$?` inside a background function ends that subshell before it records the exit code.
export PHP_INI_SCAN_DIR=
PHP_BIN=$(command -v php)
php() { "$PHP_BIN" -d memory_limit=1G "$@"; }
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../../.." && pwd)
actual="$here/results/actual"
expected="$here/expected"
BASE_SHA=05922dd9ebff00d770397af124db789bc12c1607
mkdir -p "$actual" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.stderr "$actual"/summary.md "$actual"/gates.json "$actual"/SHA256SUMS "$actual"/*.diff "$actual"/report.out
status=0
say() { printf '%-36s %s\n' "$1" "$2"; }

[[ -f "$repo/vendor/autoload.php" ]] || { echo "no vendor/ — run 'composer install' first" >&2; exit 2; }
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { say baseline_is_ancestor FAIL; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src tests composer.json)" || -n "$(git -C "$repo" status --porcelain -- src tests composer.json)" ]]; then
    say baseline_identical "FAIL (src/, tests/ or composer.json differ from $BASE_SHA or are dirty)"
    exit 4
fi
say baseline_identical "PASS (src/, tests/, composer.json = $BASE_SHA)"
say php "$(php -r 'echo PHP_VERSION, ", memory_limit ", ini_get("memory_limit"), ", scanned ini: ", php_ini_scanned_files() ?: "none";')"

# One process per arm and value of C (PLAN.md §2), four at a time; each output depends only on its own arguments.
jobs_list=()
for c in 2 3 4 1000000; do
    for arm in FULL2 PX4; do
        jobs_list+=("$arm:$c")
    done
done
run_one() {
    local arm=${1%%:*} c=${1##*:} rc=0
    php "$here/scenarios/g8_replays.php" "$arm" "$c" "$actual" 2>"$actual/$arm.C$c.stderr" || rc=$?
    echo "$rc" >"$actual/$arm.C$c.rc"
}
for i in "${!jobs_list[@]}"; do
    run_one "${jobs_list[$i]}" &
    if (((i + 1) % 4 == 0)); then wait; fi
done
wait
for j in "${jobs_list[@]}"; do
    arm=${j%%:*} c=${j##*:}
    rc=$(cat "$actual/$arm.C$c.rc"); rm -f "$actual/$arm.C$c.rc"
    e="$actual/$arm.C$c.stderr"
    if [[ $rc -eq 6 ]]; then say "replays $arm C$c" "FAIL (input assertion IN)"; cat "$e"; exit 6; fi
    if [[ $rc -ne 0 || -s "$e" ]]; then say "replays $arm C$c" "FAIL (exit $rc)"; cat "$e"; status=1; continue; fi
    rm -f "$e"
    say "replays $arm C$c" "ran ($(($(wc -l <"$actual/g8_streams.$arm.C$c.jsonl") - 1)) stream rows, $(wc -l <"$actual/g8_fixtures.$arm.C$c.jsonl" | tr -d ' ') fixture rows)"
done
[[ $status -ne 0 ]] && exit 1

set +e
php "$here/report.php" "$actual" "$actual" >"$actual/report.out"
rep=$?
set -e
cat "$actual/report.out"
rm -f "$actual/report.out"
[[ $rep -ne 0 && $rep -ne 5 ]] && { say report "FAIL (exit $rep)"; exit 1; }

# sha-256 of every output file, sorted by name.
(cd "$actual" && for f in $(ls *.jsonl summary.md gates.json | LC_ALL=C sort); do php -r 'echo hash_file("sha256", $argv[1]), "  ", $argv[1], "\n";' "$f"; done) >"$actual/SHA256SUMS"
for f in "$actual"/*.jsonl "$actual/summary.md" "$actual/gates.json" "$actual/SHA256SUMS"; do
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
[[ "${UPDATE:-0}" == "1" ]] && say expected "written ($(ls "$expected" | wc -l | tr -d ' ') files)" || { [[ $status -eq 0 ]] && say expected "every output byte-identical to expected/"; }
[[ $status -ne 0 ]] && exit 1
exit $rep
