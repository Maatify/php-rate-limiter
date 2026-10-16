#!/usr/bin/env bash
# #97 Host-managed client identity — reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked file
# outside investigations/ua-client-identity-97/.
#
#   bash investigations/ua-client-identity-97/run.sh            # run, diff against expected/   (~55 s)
#   UPDATE=1 bash investigations/ua-client-identity-97/run.sh   # regenerate expected/ (REVIEW the git diff!)
#
# Runtime: the repository's UNPATCHED src/ at the pinned baseline; only the DeviceIdentityResolverInterface implementation varies
# (shipped / candidate CIR / control NUA / post-hoc probe CIRL). Reference stores and a controlled clock; Redis and concurrency are NOT
# exercised (no claim of this study depends on them; see README.md).
set -euo pipefail
report() { printf '%-44s %s\n' "$1" "$2"; }
for label in WSB_CANDIDATE OQC_ARM GNH_ARM OAAA_ARM LAB_ROOT; do
    if [[ -n "${!label:-}" ]]; then
        report "foreign_label" "FAIL ($label is set; this study runs only the unpatched repository src/)"
        exit 4
    fi
done

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="$here/results/actual"
expected="$here/expected"
BASE_SHA=9e95f2fcca689440e9b0afc50cd9ef4158c096b3
SCENARIOS=(c01_matrix c02_lifecycle c03_posthoc_probe)
mkdir -p "$actual" "$expected"
find "${actual:?}" -mindepth 1 -delete

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

# ---- 1. scenarios --------------------------------------------------------------------------------------------------------------------
status=0
for name in "${SCENARIOS[@]}"; do
    php "$here/scenarios/$name.php" >"$actual/$name.jsonl"
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$actual/$name.jsonl" "$expected/$name.jsonl"
        report "$name" "UPDATED"
    elif diff -q "$expected/$name.jsonl" "$actual/$name.jsonl" >/dev/null; then
        report "$name" "PASS (identical to expected/)"
    else
        diff -u "$expected/$name.jsonl" "$actual/$name.jsonl" >"$actual/$name.diff" || true
        report "$name" "FAIL (see results/actual/$name.diff)"
        status=1
    fi
done

# ---- 2. report (fail-closed) ---------------------------------------------------------------------------------------------------------
src="$actual"
[[ "${UPDATE:-0}" == "1" ]] && src="$expected"
if php "$here/report.php" "$src/c01_matrix.jsonl" "$src/c02_lifecycle.jsonl" "$src/c03_posthoc_probe.jsonl" "$src" >"$actual/report.out"; then
    report "report" "PASS ($(cat "$actual/report.out"))"
else
    report "report" "FAIL (exit $?)"
    status=1
fi
if [[ "${UPDATE:-0}" != "1" ]]; then
    for f in summary.md gates.json; do
        if diff -u "$expected/$f" "$actual/$f" >"$actual/$f.diff"; then report "$f" "PASS (identical to expected/)"; else report "$f" "FAIL"; status=1; fi
    done
fi

# ---- 3. negative controls: every mutation must be rejected with its expected exit code ------------------------------------------------
st="$actual/selftest"
mkdir -p "$st"
for m in empty malformed missing_cell duplicate_cell extra_field wrong_hash patched_flag bad_alphabet bad_transition metric_mismatch \
    kinds_tamper missing_ctl attribution_gap c02_missing_row c02_bad_workers posthoc_unlabelled \
    first_denial_index masked_evasion masked_nat_regression baseline_drift composition_mismatch masked_k5_attribution \
    ablation_still_denied; do
    read -r p01 p02 p03 want < <(php "$here/selftest.php" "$m" "$src/c01_matrix.jsonl" "$src/c02_lifecycle.jsonl" "$src/c03_posthoc_probe.jsonl" "$st")
    set +e
    php "$here/report.php" "$p01" "$p02" "$p03" "$st/out" >/dev/null 2>&1
    got=$?
    set -e
    if [[ "$got" == "$want" ]]; then
        report "selftest_$m" "PASS (exit $got)"
    else
        report "selftest_$m" "FAIL (exit $got, expected $want)"
        status=1
    fi
done
for name in "${SCENARIOS[@]}"; do
    if OQC_ARM=x php "$here/scenarios/$name.php" >/dev/null 2>&1; then
        report "selftest_foreign_label_$name" "FAIL (scenario ran under a foreign label)"; status=1
    else
        report "selftest_foreign_label_$name" "PASS (scenario refused)"
    fi
done
rm -rf "${st:?}"

[[ -z "$(git -C "$repo" status --porcelain -- src tests docs composer.json composer.lock)" ]] || { report "post_run_clean" "FAIL"; exit 4; }
report "post_run_clean" "PASS"
exit $status
