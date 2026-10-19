#!/usr/bin/env bash
# G6 budgets study (#97, step 4) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/k2-identity-admission-97/g6/budgets/. Reuses the archived G5 v2 / G5 libraries, builders, patches and scenarios, and the archived G4 v2
# scenario D-IN, read-only; the archived g5_s0 and v2_detector scenarios run unchanged and are compared byte for byte with their archives.
#
#   bash investigations/k2-identity-admission-97/g6/budgets/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g6/budgets/run.sh   # regenerate expected/ (REVIEW the git diff)
#
# Exit: 0 arm 1 SUPPORTED — LIMITED SCOPE; 3 harness valid and arm 1 NO-GO AFTER EXPERIMENT; 5 V0 / V1 / V2 / V3 / V4 miss; 6 input assertion (IN) or D-IN
# failure; 7 NP-IN failure; 4 baseline / provenance; 1 output differs or a scenario fails.
set -euo pipefail
# Every php call gets this memory limit, whatever the local php.ini says, from the very first call (the archived CGN-B scenario peaks near 200 MB and
# fails at the CLI default of 128M; G6 does not run it, but the limit is a property of the runner, not of one scenario).
PHP_MEMORY_LIMIT=1G
php() { command php -d memory_limit="$PHP_MEMORY_LIMIT" "$@"; }
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../../.." && pwd)
k2="$repo/investigations/k2-identity-admission-97"
g5="$k2/g5/identity_layer"
g5v2="$k2/g5/identity_layer_v2"
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
bash "$here/build_g6.sh" "$trees" >/dev/null
cp "$trees/G6_BUILD_INFO.txt" "$actual/build_info.txt"
say build PASS

tree_of() { case $1 in G4) echo g4 ;; G5V2) echo g5v2 ;; G6UK) echo g6uk ;; esac; }
run() { # scenario-dir build scenario
    local d=$1 b=$2 sc=$3 o="$actual/$3.$2.jsonl" e="$actual/$3.$2.stderr" rc=0
    G1_ARM=CAND G5_BUILD=$b LAB_ROOT="$trees/$(tree_of "$b")" php "$d/scenarios/$sc.php" >"$o" 2>"$e" || rc=$?
    if [[ $rc -eq 6 ]]; then say "$sc.$b" "FAIL (input assertion / D-IN)"; cat "$e"; exit 6; fi
    if [[ $rc -eq 7 ]]; then say "$sc.$b" "FAIL (NP-IN)"; cat "$e"; exit 7; fi
    [[ $rc -ne 0 ]] && { say "$sc.$b" "FAIL (exit $rc)"; cat "$e"; status=1; return; }
    [[ -s "$e" ]] && { say "$sc.$b" "FAIL (stderr)"; cat "$e"; status=1; return; }
    rm -f "$e"
    say "$sc.$b" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
# The archived G4 v2 scenario D-IN, run unchanged, is compared byte for byte with the archive (PLAN.md §3).
archived() { # scenario
    local sc=$1 o="$actual/$1.G4.jsonl" e="$actual/$1.G4.stderr" rc=0
    G1_ARM=CAND G4_BUILD=G4 LAB_ROOT="$trees/g4" php "$archive/scenarios/$sc.php" >"$o" 2>"$e" || rc=$?
    if [[ $rc -eq 6 ]]; then say "archived $sc" "FAIL (D-IN)"; cat "$e"; exit 6; fi
    [[ $rc -ne 0 || -s "$e" ]] && { say "archived $sc" "FAIL (exit $rc)"; status=1; return; }
    rm -f "$e"
    if cmp -s "$archive/expected/$sc.G4.jsonl" "$o"; then say "archived $sc" "ran, byte-identical to the G4 v2 archive"; else say "archived $sc" "DIFFERS from the G4 v2 archive"; status=1; fi
}
archived v2_detector
# The archived G5 scenario g5_s0 (DEC-021 reference at the pipeline level), unchanged, compared with the G5 v2 archive (V2).
(rc=0; php "$g5/scenarios/g5_s0.php" >"$actual/g5_s0.S0.jsonl" 2>"$actual/g5_s0.S0.stderr" || rc=$?
 [[ $rc -eq 0 && ! -s "$actual/g5_s0.S0.stderr" ]] && { rm -f "$actual/g5_s0.S0.stderr"; exit 0; } || { cat "$actual/g5_s0.S0.stderr"; exit 1; }) \
    && say g5_s0.S0 "ran ($(wc -l <"$actual/g5_s0.S0.jsonl" | tr -d ' ') rows)" || { say g5_s0.S0 FAIL; status=1; }
# The S0 TMS family in its own process (arm 2 reference at this baseline; V4).
(rc=0; php "$here/scenarios/g6_tms_s0.php" >"$actual/g6_tms_s0.S0.jsonl" 2>"$actual/g6_tms_s0.S0.stderr" || rc=$?
 [[ $rc -eq 0 && ! -s "$actual/g6_tms_s0.S0.stderr" ]] && { rm -f "$actual/g6_tms_s0.S0.stderr"; exit 0; } || { cat "$actual/g6_tms_s0.S0.stderr"; exit 1; }) \
    && say g6_tms_s0.S0 "ran ($(wc -l <"$actual/g6_tms_s0.S0.jsonl" | tr -d ' ') rows)" || { say g6_tms_s0.S0 FAIL; status=1; }
# Arm 1 (build G6UK) and arm 2 (builds G4 and G5V2).
run "$here" G6UK g6_streams
run "$here" G6UK g6_fixtures
run "$here" G6UK g6_pipeline
run "$here" G6UK g6_posthoc
run "$here" G4 g6_tms
run "$here" G5V2 g6_tms
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
