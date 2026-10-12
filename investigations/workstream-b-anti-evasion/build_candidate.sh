#!/usr/bin/env bash
# Builds the ISOLATED experimental-runtime tree used for the candidate runs (A1 / PW / P2).
#   bash investigations/workstream-b-anti-evasion/build_candidate.sh [out-dir]
# - exports the pinned baseline with `git archive` (no checkout, no working-tree access),
# - verifies the target file hash BEFORE patching and fails explicitly on any mismatch,
# - applies patches/wsb-candidates.patch (zero-context: no blank context lines) with `git apply --unidiff-zero` (touches only src/Service/EvaluationPipeline.php),
# - copies vendor/ (Composer autoload is __DIR__-relative, so the copy loads ITS OWN src/, never the repository's),
# - writes BUILD_INFO.txt (baseline SHA, hashes before/after, diffstat).
# The production tree is never modified; the resulting directory is a scratch artifact and is NEVER committed.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
BASE_SHA=56f601cdc9a7062391395c326f1867d472a4ee49
TARGET=src/Service/EvaluationPipeline.php
BASE_HASH=${WSB_TEST_EXPECT_HASH:-4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc} # override: run.sh self-test only
PATCH=${WSB_TEST_PATCH_FILE:-$here/patches/wsb-candidates.patch}                                      # override: run.sh self-test only
out=${1:-$(mktemp -d "${TMPDIR:-/tmp}/wsb-candidate.XXXXXX")}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
git -C "$repo" cat-file -e "$BASE_SHA^{commit}" || { echo "baseline $BASE_SHA not available" >&2; exit 2; }
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$out"
actual=$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)
[[ "$actual" == "$BASE_HASH" ]] || { echo "TARGET HASH MISMATCH: expected $BASE_HASH got $actual" >&2; exit 3; }
git -C "$out" init -q . >/dev/null 2>&1 || true
(cd "$out" && git apply --unidiff-zero --check "$PATCH" && git apply --unidiff-zero "$PATCH") || { echo "PATCH DOES NOT APPLY (target drifted)" >&2; exit 4; }
rm -rf "${out:?}/.git"
cp -R "$repo/vendor" "$out/vendor"
{
    echo "baseline_sha: $BASE_SHA"
    echo "patch_sha256: $(shasum -a 256 "$PATCH" | cut -d' ' -f1)"
    echo "target: $TARGET"
    echo "target_sha256_before: $BASE_HASH"
    echo "target_sha256_after: $(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)"
    echo "patch_files: $(grep -c '^+++ ' "$PATCH")"
    echo "patch_added_lines: $(grep -c '^+[^+]' "$PATCH")"
    echo "patch_removed_lines: $(grep -c '^-[^-]' "$PATCH")"
} >"$out/BUILD_INFO.txt"
echo "$out"
