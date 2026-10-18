#!/usr/bin/env bash
# G4 v2 (#97, #110) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/k2-identity-admission-97/g4/integrated_v2/. Reuses the archived G4 library and builder, and through them the
# G1 / G3 / G3 v2 libraries, read-only.
#
#   bash investigations/k2-identity-admission-97/g4/integrated_v2/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g4/integrated_v2/run.sh   # regenerate expected/ (REVIEW the git diff)
#
# Exit: 0 FULL2 supported for pre+out; 3 harness valid and FULL2 NO-GO; 5 V0 or V1 miss; 6 input assertion (IN) or detector
# (D-IN) failure; 1 output differs or a scenario fails.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../../.." && pwd)
actual="$here/results/actual"
work="$here/results/work"
expected="$here/expected"
BASE_SHA=4c6ca618d8206ba502961eaf06ffb82a5d61b50d
mkdir -p "$actual" "$work" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.diff "$actual"/*.stderr "$actual"/summary.md "$actual"/gates.json
status=0
say() { printf '%-32s %s\n' "$1" "$2"; }

[[ -f "$repo/vendor/autoload.php" ]] || { echo "no vendor/ — run 'composer install' first" >&2; exit 2; }
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { say baseline_is_ancestor FAIL; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src composer.json composer.lock)" || -n "$(git -C "$repo" status --porcelain -- src composer.json composer.lock)" ]]; then
    say baseline_src_identical "FAIL (src/ or composer files differ from $BASE_SHA or are dirty)"
    exit 4
fi
say baseline_src_identical "PASS ($BASE_SHA)"
echo "php: $(php -r 'echo PHP_VERSION;')" >"$actual/versions.txt"

trees="$work/trees"
case "$trees" in "$here/results/work/"*) rm -rf "${trees:?}" ;; *) exit 2 ;; esac
bash "$here/../integrated/build_g4.sh" "$trees" >/dev/null
cp "$trees/G4_BUILD_INFO.txt" "$actual/build_info.txt"
say build PASS

run() { # build scenario
    local b=$1 sc=$2 o="$actual/$2.$1.jsonl" e="$actual/$2.$1.stderr" tree
    case $b in CAND) tree=candidate ;; NOESC) tree=noesc ;; G4) tree=g4 ;; C5) tree=c5 ;; esac
    local rc=0
    G1_ARM=CAND G4_BUILD=$b LAB_ROOT="$trees/$tree" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || rc=$?
    if [[ $rc -eq 6 ]]; then say "$sc.$b" "FAIL (input assertion / D-IN)"; cat "$e"; exit 6; fi
    [[ $rc -ne 0 ]] && { say "$sc.$b" "FAIL (exit $rc)"; status=1; return; }
    [[ -s "$e" ]] && { say "$sc.$b" "FAIL (stderr)"; status=1; return; }
    rm -f "$e"
    say "$sc.$b" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
run G4 v2_detector
for b in CAND G4 NOESC C5; do run "$b" v2_streams; done
for b in G4 NOESC C5; do run "$b" v2_fixtures; done
run G4 v2_cgn
[[ $status -ne 0 ]] && exit 1

set +e
php "$here/report.php" "$actual" "$actual" >"$actual/report.out"
rep=$?
set -e
cat "$actual/report.out"
[[ $rep -eq 5 || $rep -eq 6 ]] && exit $rep

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
[[ $status -ne 0 ]] && exit 1
exit $rep
