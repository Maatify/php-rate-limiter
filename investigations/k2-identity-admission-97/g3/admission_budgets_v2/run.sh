#!/usr/bin/env bash
# G3 v2 (#97) — reproduction runner. Evidence only: never modifies src/, tests/, docs/ or any tracked file outside
# investigations/k2-identity-admission-97/g3/admission_budgets_v2/. Reuses the v1 builder and library read-only.
#
#   bash investigations/k2-identity-admission-97/g3/admission_budgets_v2/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g3/admission_budgets_v2/run.sh   # regenerate expected/ (REVIEW the git diff)
#
# Exit: 0 all gates pass; 3 harness valid and a gate fails or a detector does not fire; 5 V0 miss; 1 output differs or a
# scenario fails.
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
bash "$here/../admission_budgets/build_g3.sh" "$trees" >/dev/null
cp "$trees/G3_BUILD_INFO.txt" "$actual/build_info.txt"
say build PASS

run() { # build scenario output-name
    local b=$1 sc=$2 o="$actual/$3.jsonl" e="$actual/$3.stderr" tree
    tree=$([[ $b == CAND ]] && echo candidate || echo noesc)
    G1_ARM=CAND G3_BUILD=$b LAB_ROOT="$trees/$tree" php "$here/scenarios/$sc.php" >"$o" 2>"$e" || { say "$3" "FAIL (exit)"; status=1; return; }
    [[ -s "$e" ]] && { say "$3" "FAIL (stderr)"; status=1; return; }
    rm -f "$e"
    say "$3" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
run CAND v2_fc v2_fc.CAND
run NOESC v2_tb v2_tb.NOESC

set +e
php "$here/report.php" "$actual" "$actual" >"$actual/report.out"
rep=$?
set -e
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
[[ $status -ne 0 ]] && exit 1
exit $rep
