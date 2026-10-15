#!/usr/bin/env bash
# #110 Host-Managed Client Instance Identity — reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked file
# outside investigations/host-managed-client-identity-110/.
#
#   bash investigations/host-managed-client-identity-110/run.sh            # run, diff against expected/
#   UPDATE=1 bash investigations/host-managed-client-identity-110/run.sh   # regenerate expected/ (REVIEW the git diff!)
#
# Runtime level: S0_ON_MAIN_RUNTIME_OBSERVED only — the repository's UNPATCHED src/ at the pinned baseline (main after DEC-021). No
# candidate tree, no patch, no arm. Reference stores and the controlled clock only; Redis and concurrency are NOT exercised here.
set -euo pipefail
report() { printf '%-36s %s\n' "$1" "$2"; }
for label in WSB_CANDIDATE OQC_ARM GNH_ARM OAAA_ARM; do
    if [[ -n "${!label:-}" ]]; then
        report "candidate_label" "FAIL ($label='${!label}' is set; this study has no candidate arm)"
        exit 4
    fi
done

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="$here/results/actual"
expected="$here/expected"
BASE_SHA=a6c79f45e79171e26031fc720822428e09e6e36c
mkdir -p "$actual" "$expected"
find "${actual:?}" -maxdepth 1 -type f -delete

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
    echo "no vendor/ in $repo — run 'composer install' first." >&2
    exit 2
fi

# ---- 0. baseline + provenance --------------------------------------------------------------------------------------------------------
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { report "baseline_is_ancestor" "FAIL ($BASE_SHA is not an ancestor of HEAD)"; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src tests docs composer.json composer.lock 2>/dev/null)" ]]; then
    report "baseline_identical" "FAIL (src/ tests/ docs/ or composer files differ from $BASE_SHA)"
    exit 4
fi
if [[ -n "$(git -C "$repo" status --porcelain -- src tests docs composer.json composer.lock)" ]]; then
    report "baseline_clean" "FAIL (uncommitted changes under src/ tests/ docs/ or composer files)"
    exit 4
fi
report "baseline_identical" "PASS (src/ tests/ docs/ composer files identical to $BASE_SHA and clean)"
{
    echo "baseline_expected: $BASE_SHA"
    echo "php: $(php -r 'echo PHP_VERSION;')"
} >"$actual/versions.txt"

# ---- 1. scenario ---------------------------------------------------------------------------------------------------------------------
php "$here/scenarios/h01_input_modes.php" >"$actual/h01_input_modes.jsonl"
status=0
if [[ "${UPDATE:-0}" == "1" ]]; then
    cp "$actual/h01_input_modes.jsonl" "$expected/h01_input_modes.jsonl"
    report "h01_input_modes" "UPDATED"
elif diff -u "$expected/h01_input_modes.jsonl" "$actual/h01_input_modes.jsonl" >"$actual/h01_input_modes.diff"; then
    report "h01_input_modes" "PASS (identical to expected/)"
else
    report "h01_input_modes" "FAIL (see results/actual/h01_input_modes.diff)"
    status=1
fi

# ---- 2. report (fail-closed) ---------------------------------------------------------------------------------------------------------
if [[ "${UPDATE:-0}" == "1" ]]; then
    php "$here/report.php" "$expected/h01_input_modes.jsonl" "$expected" || status=1
else
    php "$here/report.php" "$actual/h01_input_modes.jsonl" "$actual" || status=1
    for f in summary.md observations.jsonl; do
        if diff -u "$expected/$f" "$actual/$f" >"$actual/$f.diff"; then report "$f" "PASS (identical to expected/)"; else report "$f" "FAIL"; status=1; fi
    done
fi

# ---- 3. fail-closed self-tests (negative controls) -----------------------------------------------------------------------------------
selftest() { # name, mutated-file
    if php "$here/report.php" "$2" "$actual/selftest" >/dev/null 2>&1; then
        report "selftest_$1" "FAIL (report accepted invalid evidence)"
        status=1
    else
        report "selftest_$1" "PASS (rejected)"
    fi
}
mkdir -p "$actual/selftest"
src="$actual/h01_input_modes.jsonl"
: >"$actual/st_empty.jsonl";                                       selftest empty "$actual/st_empty.jsonl"
grep -v '"stream":"OFF100","class":"benign","mode":"M1"' "$src" >"$actual/st_missing_cell.jsonl";  selftest missing_cell "$actual/st_missing_cell.jsonl"
sed 's/"patched":false/"patched":true/' "$src" >"$actual/st_patched.jsonl";                        selftest patched_label "$actual/st_patched.jsonl"
sed 's/402139452040cb31/0000000000000000/' "$src" >"$actual/st_hash.jsonl";                         selftest wrong_hash "$actual/st_hash.jsonl"
sed '5s/"check":"A/"check":"AA/' "$src" >"$actual/st_truncated.jsonl";                             selftest bad_request_string "$actual/st_truncated.jsonl"
sed '3s/^{/{x/' "$src" >"$actual/st_malformed.jsonl";                                              selftest malformed "$actual/st_malformed.jsonl"
if OQC_ARM=x php "$here/scenarios/h01_input_modes.php" >/dev/null 2>&1; then
    report "selftest_foreign_label" "FAIL (scenario ran under a candidate label)"; status=1
else
    report "selftest_foreign_label" "PASS (scenario refused, exit 4)"
fi
rm -rf "${actual:?}/selftest" "$actual"/st_*.jsonl

[[ -z "$(git -C "$repo" status --porcelain -- src tests docs composer.json composer.lock)" ]] || { report "post_run_clean" "FAIL"; exit 4; }
report "post_run_clean" "PASS"
exit $status
