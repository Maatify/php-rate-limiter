#!/usr/bin/env bash
# #110 Account-Scoped Known-Device Relief (AKDR) — reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or any tracked
# file outside investigations/account-scoped-known-device-relief-110/.
#
#   bash investigations/account-scoped-known-device-relief-110/run.sh            # run, diff against expected/
#   UPDATE=1 bash investigations/account-scoped-known-device-relief-110/run.sh   # regenerate expected/ (REVIEW the git diff!)
#
# Runtime level: MAIN_RUNTIME_OBSERVED (the repository's UNPATCHED src/ at the pinned baseline, DEC-021 ACTIVE) + RELIEF_STORE_VIEW_MODEL
# (variants A..D2 are store-view emulations on that runtime, never a candidate patch). Reference stores and a controlled clock only;
# Redis, concurrency, IPv6 and secret rotation are NOT exercised.
set -euo pipefail
report() { printf '%-44s %s\n' "$1" "$2"; }
for label in WSB_CANDIDATE OQC_ARM GNH_ARM OAAA_ARM K1_SELFTEST_C_NOOP K1_SELFTEST_TTL_SHIFT TMS_SELFTEST_BREAK_SESS; do
    if [[ -n "${!label:-}" ]]; then
        report "foreign_label" "FAIL ($label='${!label}' is set; this study has no candidate arm)"
        exit 4
    fi
done

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="$here/results/actual"
expected="$here/expected"
BASE_SHA=ebeb0a32c8fd42e0d4eb4d2500f30ea14036ad92
SCENARIOS=(v01_known_attackers o01_shared_nat b01_bootstrap)
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
if php "$here/report.php" "$src/v01_known_attackers.jsonl" "$src/o01_shared_nat.jsonl" "$src/b01_bootstrap.jsonl" "$src"; then
    report "report" "PASS (evidence valid, every observation REPRODUCED)"
else
    report "report" "FAIL"
    status=1
fi
if [[ "${UPDATE:-0}" != "1" ]]; then
    for f in summary.md observations.jsonl; do
        if diff -u "$expected/$f" "$actual/$f" >"$actual/$f.diff"; then report "$f" "PASS (identical to expected/)"; else report "$f" "FAIL"; status=1; fi
    done
fi

# ---- 3. negative controls: every mutation must be rejected with its expected exit code ------------------------------------------------
st="$actual/selftest"
mkdir -p "$st"
for m in empty malformed missing_cell duplicate_cell extra_field wrong_hash bad_length bad_alphabet bad_transition relief_on_main \
    relief_on_unknown mismatched_reach source_sum gate_false o_known_mismatch o_counter_shift o_missing_cell o_token_after_success \
    o_relief_on_unknown b_carry_broken b_missing_day b_dropped_retry masked_gain masked_pool; do
    read -r pv po pb want < <(php "$here/selftest.php" "$m" "$src/v01_known_attackers.jsonl" "$src/o01_shared_nat.jsonl" "$src/b01_bootstrap.jsonl" "$st")
    set +e
    php "$here/report.php" "$pv" "$po" "$pb" "$st/out" >/dev/null 2>&1
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
    set +e
    OQC_ARM=x php "$here/scenarios/$name.php" >/dev/null 2>&1
    got=$?
    set -e
    if [[ "$got" == "4" ]]; then report "selftest_foreign_label_$name" "PASS (scenario refused, exit 4)"; else report "selftest_foreign_label_$name" "FAIL (exit $got)"; status=1; fi
done
rm -rf "${st:?}"

[[ -z "$(git -C "$repo" status --porcelain -- src tests docs composer.json composer.lock)" ]] || { report "post_run_clean" "FAIL"; exit 4; }
report "post_run_clean" "PASS"
exit $status
