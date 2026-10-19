#!/usr/bin/env bash
# G7 cross-account P study (#97, step 4) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/k2-identity-admission-97/g7/cross_account_p/. Reuses the archived G5 v2 builder and the archived G6 library read-only; the archived v2_detector and
# g6_tms_s0 scenarios run unchanged and are compared byte for byte with their archives.
#
#   bash investigations/k2-identity-admission-97/g7/cross_account_p/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g7/cross_account_p/run.sh   # regenerate expected/ (REVIEW the git diff)
#
# Exit: 0 SUPPORTED — LIMITED SCOPE; 3 harness valid and NO-GO AFTER EXPERIMENT; 5 V0 / V1 / V2 miss; 6 input assertion (IN) or D-IN failure; 7 NP-IN failure;
# 4 baseline / provenance; 1 output differs or a scenario fails.
set -euo pipefail
# Every php call gets this memory limit, whatever the local php.ini says, from the very first call.
PHP_MEMORY_LIMIT=1G
php() { command php -d memory_limit="$PHP_MEMORY_LIMIT" "$@"; }
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../../.." && pwd)
k2="$repo/investigations/k2-identity-admission-97"
g5v2="$k2/g5/identity_layer_v2"
g6="$k2/g6/budgets"
archive="$k2/g4/integrated_v2"
actual="$here/results/actual"
work="$here/results/work"
expected="$here/expected"
BASE_SHA=4c6ca618d8206ba502961eaf06ffb82a5d61b50d
mkdir -p "$actual" "$work" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.diff "$actual"/*.stderr "$actual"/summary.md "$actual"/gates.json
status=0
say() { printf '%-34s %s\n' "$1" "$2"; }

[[ -f "$repo/vendor/autoload.php" ]] || { echo "no vendor/ — run 'composer install' first" >&2; exit 2; }
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { say baseline_is_ancestor FAIL; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src composer.json composer.lock)" || -n "$(git -C "$repo" status --porcelain -- src composer.json composer.lock)" ]]; then
    say baseline_src_identical "FAIL (src/ or composer files differ from $BASE_SHA or are dirty)"
    exit 4
fi
say baseline_src_identical "PASS ($BASE_SHA)"
echo "php: $(php -r 'echo PHP_VERSION;'), memory_limit $(php -r 'echo ini_get("memory_limit");')" >"$actual/versions.txt"

trees="$work/trees"
case "$trees" in "$here/results/work/"*) rm -rf "${trees:?}" ;; *) exit 2 ;; esac
bash "$g5v2/build_g5v2.sh" "$trees" >/dev/null
cp "$trees/G5V2_BUILD_INFO.txt" "$actual/build_info.txt"
say build PASS

tree_of() { case $1 in G4) echo g4 ;; G5V2) echo g5v2 ;; esac; }
run() { # build scenario
    local b=$1 sc=$2 o="$actual/$2.$1.jsonl" e="$actual/$2.$1.stderr" rc=0
    G1_ARM=CAND G5_BUILD=$b LAB_ROOT="$trees/$(tree_of "$b")" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || rc=$?
    if [[ $rc -eq 6 ]]; then say "$sc.$b" "FAIL (input assertion / D-IN)"; cat "$e"; exit 6; fi
    if [[ $rc -eq 7 ]]; then say "$sc.$b" "FAIL (NP-IN)"; cat "$e"; exit 7; fi
    [[ $rc -ne 0 ]] && { say "$sc.$b" "FAIL (exit $rc)"; cat "$e"; status=1; return; }
    [[ -s "$e" ]] && { say "$sc.$b" "FAIL (stderr)"; cat "$e"; status=1; return; }
    rm -f "$e"
    say "$sc.$b" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
# The archived G4 v2 scenario D-IN, run unchanged, is compared byte for byte with the archive (PLAN.md §3).
rc=0
G1_ARM=CAND G4_BUILD=G4 LAB_ROOT="$trees/g4" php "$archive/scenarios/v2_detector.php" >"$actual/v2_detector.G4.jsonl" 2>"$actual/v2_detector.G4.stderr" || rc=$?
if [[ $rc -eq 6 ]]; then say "archived v2_detector" "FAIL (D-IN)"; cat "$actual/v2_detector.G4.stderr"; exit 6; fi
if [[ $rc -eq 0 && ! -s "$actual/v2_detector.G4.stderr" ]] && cmp -s "$archive/expected/v2_detector.G4.jsonl" "$actual/v2_detector.G4.jsonl"; then
    rm -f "$actual/v2_detector.G4.stderr"; say "archived v2_detector" "ran, byte-identical to the G4 v2 archive"
else say "archived v2_detector" "FAIL"; status=1; fi
# The archived G6 scenario g6_tms_s0 (S0 at this baseline), unchanged, in its own process, compared with the G6 archive (V2).
rc=0
php "$g6/scenarios/g6_tms_s0.php" >"$actual/g6_tms_s0.S0.jsonl" 2>"$actual/g6_tms_s0.S0.stderr" || rc=$?
if [[ $rc -eq 0 && ! -s "$actual/g6_tms_s0.S0.stderr" ]]; then rm -f "$actual/g6_tms_s0.S0.stderr"; say g6_tms_s0.S0 "ran ($(wc -l <"$actual/g6_tms_s0.S0.jsonl" | tr -d ' ') rows)"; else cat "$actual/g6_tms_s0.S0.stderr"; say g6_tms_s0.S0 FAIL; status=1; fi
run G5V2 g7_streams
run G5V2 g7_fixtures
[[ $status -ne 0 ]] && exit 1

set +e
php "$here/report.php" "$actual" "$actual" >"$actual/report.out"
rep=$?
set -e
cat "$actual/report.out"
[[ $rep -eq 5 || $rep -eq 6 || $rep -eq 7 ]] && exit $rep

for f in "$actual"/*.jsonl "$actual/summary.md" "$actual/gates.json" "$actual/build_info.txt"; do
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
[[ $status -ne 0 ]] && exit 1
exit $rep
