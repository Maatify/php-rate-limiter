#!/usr/bin/env bash
# G1 final verification (FLOOD_PLAN.md) — reproduction runner. Evidence only; never modifies tracked files outside g1/verify/.
#
#   bash investigations/k2-identity-admission-97/g1/verify/run_flood.sh            # everything, diffed against verify/expected_flood/
#   UPDATE=1 bash investigations/k2-identity-admission-97/g1/verify/run_flood.sh   # regenerate (REVIEW the git diff)
#
# Step 1 runs run_da_t1.sh (-> run_verify.sh -> ../run.sh): every earlier archived evidence set must compare clean.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
g1=$(cd "$here/.." && pwd)
actual="$g1/results/flood-actual"
work="$g1/results/verify-work"
expected="$here/expected_flood"
mkdir -p "$actual" "$expected"
rm -f "$actual"/*.jsonl "$actual"/*.diff "$actual"/*.stderr "$actual"/summary_flood.md "$actual"/gates_flood.json
status=0
say() { printf '%-48s %s\n' "$1" "$2"; }

if bash "$here/run_da_t1.sh" >"$actual/da_t1_run.out" 2>&1; then say earlier_evidence "PASS (original, addendum, DA/T1 compare clean)"; else say earlier_evidence "FAIL (see results/flood-actual/da_t1_run.out)"; status=1; fi
bash "$here/build_flood.sh" "$work" >/dev/null
say flood_build PASS

run() { # build tree scenario-path output-name
    local b=$1 t=$2 sc=$3 n=$4 o="$actual/$4.$1.jsonl" e="$actual/$4.$1.stderr"
    G1_ARM=CAND G1_BUILD=$b LAB_ROOT="$work/$t" php "$sc" >"$o" 2>"$e" || { say "$n.$b" "FAIL (exit)"; status=1; return; }
    [[ -s "$e" ]] && { say "$n.$b" "FAIL (stderr)"; status=1; return; }
    rm -f "$e"
    say "$n.$b" "ran ($(wc -l <"$o" | tr -d ' ') rows)"
}
for bt in TRACE:trace DAO:dao DAOF:daof; do
    run "${bt%%:*}" "${bt##*:}" "$here/scenarios/f01_flood_p.php" f01_flood_p
done
run DAOF daof "$here/scenarios/x01_matrix.php" x01_matrix
run DAOF daof "$here/scenarios/x02_owner.php" x02_owner
run DAOF daof "$here/scenarios/x03_unbound.php" x03_unbound
run DAOF daof "$g1/scenarios/m02_device.php" x04_m02_device
run DAOF daof "$g1/scenarios/m04_lifecycle.php" x04_m04_lifecycle

php "$here/report_flood.php" "$actual" "$actual" >"$actual/report_flood.out" || { say report_flood "FAIL (exit $?)"; status=1; }
cat "$actual/report_flood.out"

for f in "$actual"/*.jsonl "$actual/summary_flood.md" "$actual/gates_flood.json"; do
    n=$(basename "$f")
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$f" "$expected/$n"
    elif ! diff -u "$expected/$n" "$f" >"$actual/$n.diff" 2>&1; then
        say "$n" "DIFFERS (results/flood-actual/$n.diff)"
        status=1
    else
        rm -f "$actual/$n.diff"
    fi
done
[[ "${UPDATE:-0}" == "1" ]] && say flood_expected UPDATED || say flood_expected "compared"
exit $status
