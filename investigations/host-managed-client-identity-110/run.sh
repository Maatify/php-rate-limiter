#!/usr/bin/env bash
# #110 Host-Managed Client Instance Identity — reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked file
# outside investigations/host-managed-client-identity-110/.
#
#   bash investigations/host-managed-client-identity-110/run.sh            # run, diff against expected/
#   UPDATE=1 bash investigations/host-managed-client-identity-110/run.sh   # regenerate expected/ (REVIEW the git diff!)
#
# Runtime level: S0_ON_MAIN_RUNTIME_OBSERVED only — the repository's UNPATCHED src/ at the pinned baseline (main after DEC-021). No
# candidate tree, no patch, no arm. H02's ESTABLISHED browsers are an IDEALIZED model (see scenarios/h02_bootstrap.php). Reference
# stores and the controlled clock only; Redis and concurrency are NOT exercised here.
set -euo pipefail
report() { printf '%-40s %s\n' "$1" "$2"; }
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
SCENARIOS=(h01_input_modes h02_bootstrap)
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
echo "baseline_expected: $BASE_SHA" >"$actual/versions.txt"
echo "php: $(php -r 'echo PHP_VERSION;')" >>"$actual/versions.txt"

# ---- 1. scenarios --------------------------------------------------------------------------------------------------------------------
status=0
for name in "${SCENARIOS[@]}"; do
    php "$here/scenarios/$name.php" >"$actual/$name.jsonl"
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$actual/$name.jsonl" "$expected/$name.jsonl"
        report "$name" "UPDATED"
    elif diff -u "$expected/$name.jsonl" "$actual/$name.jsonl" >"$actual/$name.diff"; then
        report "$name" "PASS (identical to expected/)"
    else
        report "$name" "FAIL (see results/actual/$name.diff)"
        status=1
    fi
done

# ---- 2. report (fail-closed) ---------------------------------------------------------------------------------------------------------
if [[ "${UPDATE:-0}" == "1" ]]; then
    php "$here/report.php" "$expected/h01_input_modes.jsonl" "$expected/h02_bootstrap.jsonl" "$expected" || status=1
else
    php "$here/report.php" "$actual/h01_input_modes.jsonl" "$actual/h02_bootstrap.jsonl" "$actual" || status=1
    for f in summary.md observations.jsonl; do
        if diff -u "$expected/$f" "$actual/$f" >"$actual/$f.diff"; then report "$f" "PASS (identical to expected/)"; else report "$f" "FAIL"; status=1; fi
    done
fi

# ---- 3. negative controls: every mutation must be rejected with its expected exit code ------------------------------------------------
st="$actual/selftest"
mkdir -p "$st"
for m in empty malformed missing_cell duplicate_cell extra_field patched_label wrong_hash bad_length bad_alphabet bad_transition \
    corrupt_trace mismatched_reach mismatched_users wrong_conf masked_rotation \
    h02_missing_row h02_duplicate_row h02_broken_continuity h02_reach_on_non_attack h02_masked_attack_gain \
    h02_metric_preserving_arithmetic h02_attacker_reach_shift h02_event_check_tamper h02_dropped_retry; do
    read -r p01 p02 want < <(php "$here/selftest.php" "$m" "$actual/h01_input_modes.jsonl" "$actual/h02_bootstrap.jsonl" "$st")
    set +e
    php "$here/report.php" "$p01" "$p02" "$st/out" >/dev/null 2>&1
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
        report "selftest_foreign_label_$name" "FAIL (scenario ran under a candidate label)"; status=1
    else
        report "selftest_foreign_label_$name" "PASS (scenario refused)"
    fi
done
rm -rf "${st:?}"

[[ -z "$(git -C "$repo" status --porcelain -- src tests docs composer.json composer.lock)" ]] || { report "post_run_clean" "FAIL"; exit 4; }
report "post_run_clean" "PASS"
exit $status
