#!/usr/bin/env bash
# G5 identity-layer study (#97, #110) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/k2-identity-admission-97/g5/identity_layer/. Reuses the archived G4 v2 library and scenarios (and through them G4, G3, G1)
# read-only.
#
#   bash investigations/k2-identity-admission-97/g5/identity_layer/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g5/identity_layer/run.sh   # regenerate expected/ (REVIEW the git diff)
#
# Exit: 0 SUPPORTED — LIMITED SCOPE; 3 harness valid and NO-GO AFTER EXPERIMENT; 5 V0 or V1 miss; 6 input assertion (IN) or D-IN failure;
# 4 baseline / provenance; 1 output differs or a scenario fails.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../../.." && pwd)
k2="$repo/investigations/k2-identity-admission-97"
archive="$k2/g4/integrated_v2"
actual="$here/results/actual"
work="$here/results/work"
expected="$here/expected"
BASE_SHA=4c6ca618d8206ba502961eaf06ffb82a5d61b50d
mkdir -p "$actual" "$work" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.diff "$actual"/*.stderr "$actual"/summary.md "$actual"/gates.json
status=0
say() { printf '%-34s %s\n' "$1" "$2"; }
# Every php call gets this memory limit, whatever the local php.ini says (the lesson of the G4 v2 review: the archived CGN-B scenario
# peaks near 200 MB and fails at the CLI default of 128M).
PHP_MEMORY_LIMIT=1G
php() { command php -d memory_limit="$PHP_MEMORY_LIMIT" "$@"; }

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
bash "$here/build_g5.sh" "$trees" >/dev/null
cp "$trees/G5_BUILD_INFO.txt" "$actual/build_info.txt"
say build PASS

tree_of() {
    case $1 in
        CAND) echo candidate ;; G4) echo g4 ;; G5) echo g5 ;; G5-NOALW) echo g5-noalw ;; G5-NOOVF) echo g5-noovf ;;
        G5-NOK5) echo g5-nok5 ;; G5-NOAE) echo g5-noae ;;
    esac
}
run() { # build scenario
    local b=$1 sc=$2 o="$actual/$2.$1.jsonl" e="$actual/$2.$1.stderr" rc=0
    G1_ARM=CAND G5_BUILD=$b LAB_ROOT="$trees/$(tree_of "$b")" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || rc=$?
    if [[ $rc -eq 6 ]]; then say "$sc.$b" "FAIL (input assertion / D-IN)"; cat "$e"; exit 6; fi
    [[ $rc -ne 0 ]] && { say "$sc.$b" "FAIL (exit $rc)"; status=1; return; }
    [[ -s "$e" ]] && { say "$sc.$b" "FAIL (stderr)"; status=1; return; }
    rm -f "$e"
    say "$sc.$b" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
# The archived G4 v2 scenarios D-IN and CGN-B, run unchanged, are compared byte for byte with the archive (PLAN.md §3, §4.1).
archived() { # scenario
    local sc=$1 o="$actual/$1.G4.jsonl" e="$actual/$1.G4.stderr" rc=0
    G1_ARM=CAND G4_BUILD=G4 LAB_ROOT="$trees/g4" php "$archive/scenarios/$sc.php" >"$o" 2>"$e" || rc=$?
    if [[ $rc -eq 6 ]]; then say "archived $sc" "FAIL (D-IN)"; cat "$e"; exit 6; fi
    [[ $rc -ne 0 || -s "$e" ]] && { say "archived $sc" "FAIL (exit $rc)"; status=1; return; }
    rm -f "$e"
    if cmp -s "$archive/expected/$sc.G4.jsonl" "$o"; then say "archived $sc" "ran, byte-identical to the G4 v2 archive"; else say "archived $sc" "DIFFERS from the G4 v2 archive"; status=1; fi
}
archived v2_detector
archived v2_cgn
for b in CAND G4 G5; do run "$b" g5_streams; done
for b in G4 G5; do run "$b" g5_fixtures; done
run G5 g5_posthoc
for b in G4 G5 G5-NOALW G5-NOOVF G5-NOK5 G5-NOAE; do run "$b" g5_pipeline; done
(rc=0; php "$here/scenarios/g5_s0.php" >"$actual/g5_s0.S0.jsonl" 2>"$actual/g5_s0.S0.stderr" || rc=$?
 [[ $rc -eq 0 && ! -s "$actual/g5_s0.S0.stderr" ]] && { rm -f "$actual/g5_s0.S0.stderr"; exit 0; } || { cat "$actual/g5_s0.S0.stderr"; exit 1; }) \
    && say g5_s0.S0 "ran ($(wc -l <"$actual/g5_s0.S0.jsonl" | tr -d ' ') rows)" || { say g5_s0.S0 FAIL; status=1; }
[[ $status -ne 0 ]] && exit 1

set +e
php "$here/report.php" "$actual" "$actual" >"$actual/report.out"
rep=$?
set -e
cat "$actual/report.out"
[[ $rep -eq 5 || $rep -eq 6 ]] && exit $rep

for f in "$actual"/*.jsonl "$actual/summary.md" "$actual/gates.json" "$actual/build_info.txt" "$actual/versions.txt"; do
    n=$(basename "$f")
    [[ "$n" == "versions.txt" ]] && continue
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
