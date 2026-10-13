#!/usr/bin/env bash
# #110 trust family — the R23-01 counter-case measured on S0 ONLY: reproduction runner. Evidence-only: never modifies src/, tests/, docs/ or
# any tracked file outside investigations/trusted-multi-session-s0/.
#
#   bash investigations/trusted-multi-session-s0/run.sh            # everything, diffed against expected/
#   UPDATE=1 bash investigations/trusted-multi-session-s0/run.sh   # regenerate expected/ (REVIEW the git diff!)
#
# Runtime level: S0_RUNTIME_OBSERVED only — the repository's UNPATCHED EvaluationPipeline (sha-256 asserted by every scenario). There is no
# candidate tree, no patch and no arm in this study; a candidate label (WSB_CANDIDATE / OQC_ARM) is refused with exit 4. Reference stores and
# the controlled clock only (sequential decision parity of this pipeline with real Redis was proven in #108 / #111; concurrency is out of scope).
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
actual="${here:?}/results/actual"
expected="${here:?}/expected"
BASE_SHA=ce89cb26241a660d58e9d896ee2f9ae56f0239c8
mkdir -p "$actual" "$expected"
find "${actual:?}" -maxdepth 1 -type f \( -name '*.jsonl' -o -name '*.diff' -o -name '*.stderr' -o -name '*.txt' -o -name 'summary.md' -o -name 'report.out' \) -delete
rm -rf "${actual:?}"/failclosed-*

if [[ ! -f "$repo/vendor/autoload.php" ]]; then
    echo "no vendor/ in $repo — run 'composer install' first." >&2
    exit 2
fi
status=0
report() { printf '%-48s %s\n' "$1" "$2"; }

# ---- 0. baseline + provenance ------------------------------------------------------------------------------------------------------
{
    echo "baseline_expected: $BASE_SHA"
    echo "repo_head: $(git -C "$repo" rev-parse HEAD)"
    echo "pipeline_sha256_repo: $(shasum -a 256 "$repo/src/Service/EvaluationPipeline.php" | cut -d' ' -f1)"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "composer: $(composer --version 2>/dev/null | head -1 || echo n/a)"
} >"$actual/versions.txt"
git -C "$repo" merge-base --is-ancestor "$BASE_SHA" HEAD || { report "baseline_is_ancestor" "FAIL ($BASE_SHA is not an ancestor of HEAD)"; exit 4; }
if [[ -n "$(git -C "$repo" diff --name-only "$BASE_SHA" -- src composer.json composer.lock 2>/dev/null)" ]]; then
    report "baseline_src_identical" "FAIL (src/ or composer files differ from $BASE_SHA)"
    exit 4
fi
if [[ -n "$(git -C "$repo" status --porcelain -- src tests docs composer.json composer.lock)" ]]; then
    report "baseline_src_clean" "FAIL (uncommitted changes under src/ tests/ docs/ or composer files)"
    exit 4
fi
report "baseline_src_identical" "PASS (src/, composer.json, composer.lock identical to $BASE_SHA; src/tests/docs clean)"

compare() { # name
    local name=$1
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$actual/$name.jsonl" "$expected/$name.jsonl"
        report "$name" "UPDATED"
    elif diff -u "$expected/$name.jsonl" "$actual/$name.jsonl" >"$actual/$name.diff" 2>&1; then
        rm -f "${actual:?}/$name.diff"
        report "$name" "PASS ($(wc -l <"$actual/$name.jsonl" | tr -d ' ') lines identical)"
    else
        report "$name" "FAIL (see results/actual/$name.diff)"
        status=1
    fi
}

# ---- 1. deterministic reference-store scenarios, S0 only -----------------------------------------------------------------------------
for sc in t01_matrix t02_trace; do
    if ! env -u WSB_CANDIDATE -u OQC_ARM LAB_ROOT="$repo" php "$here/scenarios/$sc.php" >"$actual/$sc.jsonl" 2>"$actual/$sc.stderr"; then
        report "$sc" "FAIL (non-zero exit; see results/actual/$sc.jsonl / .stderr)"
        status=1
        continue
    fi
    if [[ -s "$actual/$sc.stderr" ]]; then
        report "$sc" "FAIL (stderr not empty)"
        status=1
        continue
    fi
    rm -f "${actual:?}/$sc.stderr"
    compare "$sc"
done

# ---- 2. gates + summary --------------------------------------------------------------------------------------------------------------
if php "$here/report.php" "$actual" "$actual" >"$actual/report.out" 2>"$actual/report.stderr" && [[ ! -s "$actual/report.stderr" ]]; then
    compare "gates"
    if [[ "${UPDATE:-0}" == "1" ]]; then
        cp "$actual/summary.md" "$expected/summary.md"
        report "summary.md" "UPDATED"
    elif diff -q "$expected/summary.md" "$actual/summary.md" >/dev/null 2>&1; then
        report "summary.md" "PASS (identical)"
    else
        report "summary.md" "FAIL (differs from expected/summary.md)"
        status=1
    fi
else
    report "gates" "FAIL (report.php; see results/actual/report.stderr / report.out)"
    status=1
fi

# ---- 3. self-tests: provenance and fail-closed evidence must refuse damage ------------------------------------------------------------
selftest_exit() { # name expected-exit command...
    local name=$1 want=$2 rc
    shift 2
    set +e
    "$@" >"$actual/selftest_$name.out" 2>"$actual/selftest_$name.err"
    rc=$?
    set -e
    rm -f "$actual/selftest_$name.out" "$actual/selftest_$name.err"
    if [[ $rc -eq $want ]]; then report "selftest_$name" "PASS (refused with exit $rc)"; else report "selftest_$name" "FAIL (expected exit $want, got $rc)"; status=1; fi
}
# provenance: a candidate label must be refused with exit 4 (this study never runs a patched tree)
selftest_exit candidate_label_refused 4 env WSB_CANDIDATE=EQL LAB_ROOT="$repo" php "$here/scenarios/t01_matrix.php"
selftest_exit oqc_arm_refused 4 env OQC_ARM=OQC LAB_ROOT="$repo" php "$here/scenarios/t01_matrix.php"
# fail-closed evidence: delete / damage representative evidence and require report.php to refuse (exit 5) and write no PASS gate
fc_check() { # name mutation-command-on-copy
    local name=$1 dir="$actual/failclosed-$1" rc
    rm -rf "${dir:?}"
    mkdir -p "$dir"
    cp "$actual"/t01_matrix.jsonl "$actual"/t02_trace.jsonl "$dir"/
    eval "$2"
    set +e
    php "$here/report.php" "$dir" "$dir" >"$dir/report.out" 2>"$dir/report.stderr"
    rc=$?
    set -e
    if [[ $rc -eq 5 ]] && ! grep -q '"status":"PASS"' "$dir/gates.jsonl" 2>/dev/null; then
        report "selftest_failclosed_$name" "PASS (report.php exit 5; no PASS gate written)"
    else
        report "selftest_failclosed_$name" "FAIL (exit $rc; expected 5 with no PASS gate)"
        status=1
    fi
    rm -rf "${dir:?}"
}
fc_check missing_matrix 'rm -f "$dir/t01_matrix.jsonl"'
fc_check missing_trace 'rm -f "$dir/t02_trace.jsonl"'
fc_check one_matrix_cell_deleted 'grep -v "\"stream\":\"TMSd\",\"class\":\"attack\",\"shape\":\"login\",\"flow\":\"pre+out\"" "$dir/t01_matrix.jsonl" >"$dir/t" && mv "$dir/t" "$dir/t01_matrix.jsonl"'
fc_check matrix_field_removed 'sed -i.bak "s/\"attackReachedFirst600\"/\"zzz\"/g" "$dir/t01_matrix.jsonl" && rm -f "$dir/t01_matrix.jsonl.bak"'
fc_check assertion_row_deleted 'grep -v "\"assert\":\"S0 SP5 " "$dir/t01_matrix.jsonl" >"$dir/t" && mv "$dir/t" "$dir/t01_matrix.jsonl"'
fc_check forged_pipeline_sha256 'sed -i.bak "s/4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc/0000000000000000000000000000000000000000000000000000000000000000/" "$dir/t01_matrix.jsonl" && rm -f "$dir/t01_matrix.jsonl.bak"'
fc_check divergence_row_deleted 'grep -v "\"s\":\"T02.divergence\",\"flow\":\"pre\"," "$dir/t02_trace.jsonl" >"$dir/t" && mv "$dir/t" "$dir/t02_trace.jsonl"'
fc_check trace_deleted 'grep -v "\"s\":\"T02.trace\",\"stream\":\"TMX\",\"flow\":\"out\"" "$dir/t02_trace.jsonl" >"$dir/t" && mv "$dir/t" "$dir/t02_trace.jsonl"'

echo "----"
if [[ $status -eq 0 ]]; then echo "ALL EVIDENCE REPRODUCED (PASS = evidence matches expected/; it is not a design verdict)"; else echo "FAILURES PRESENT"; fi
exit $status
