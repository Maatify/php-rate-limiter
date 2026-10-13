#!/usr/bin/env bash
# Builds the ISOLATED experimental-runtime tree for the #110 plan-1 (OQC) candidate arms (OQC / OQCW / OQCE; selected at run time by env OQC_ARM).
#   bash investigations/nat-remediation-110/build_candidate.sh <empty-out-dir>
# - exports the pinned baseline with `git archive` (no checkout, no working-tree access),
# - verifies the sha-256 of the target file BEFORE patching (exit 3) and of the patch itself (exit 6),
# - applies patches/oqc-candidates.patch (zero-context) to src/Service/EvaluationPipeline.php only with `git apply --unidiff-zero` (exit 4),
# - verifies the patched target hash (exit 7) and that NO other file differs from the baseline export (exit 8),
# - copies vendor/ (Composer autoload is __DIR__-relative, so the copy loads ITS OWN src/, never the repository's),
# - writes BUILD_INFO.txt.
# The production tree is never modified; the resulting directory is a scratch artifact and is NEVER committed.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
BASE_SHA=63fbb4abf0a6d7899d063d1fbd5d6fc7a1920ba1
TARGET=src/Service/EvaluationPipeline.php
BASE_HASH=${OQC_TEST_EXPECT_HASH:-4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc} # override: run.sh self-test only
PATCH=${OQC_TEST_PATCH_FILE:-$here/patches/oqc-candidates.patch}                                      # override: run.sh self-test only
PATCH_SHA256=8b6223fc5fcd01bd40cbf8beb521756b153156de398d04a80b881f80c6b2d2d5
TARGET_AFTER_SHA256=189f6e41020f983cc7e58531e3248b49fe84b8d39f08c3e283950c4d442f776f
SKIP_PIN=${OQC_TEST_SKIP_PIN:-0} # override: run.sh self-test only (lets a drifted patch reach `git apply`)
[[ -f "$PATCH" ]] || { echo "PATCH MISSING: $PATCH" >&2; exit 5; }
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$PATCH" | cut -d' ' -f1)" == "$PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH (not the committed candidate patch)" >&2; exit 6; }
fi
out=${1:?usage: build_candidate.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
git -C "$repo" cat-file -e "$BASE_SHA^{commit}" || { echo "baseline $BASE_SHA not available" >&2; exit 2; }
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$out"
actual=$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)
[[ "$actual" == "$BASE_HASH" ]] || { echo "TARGET HASH MISMATCH: expected $BASE_HASH got $actual" >&2; exit 3; }
git -C "$out" init -q . >/dev/null 2>&1 || true
(cd "$out" && git apply --unidiff-zero --check "$PATCH" && git apply --unidiff-zero "$PATCH") || { echo "PATCH DOES NOT APPLY (target drifted)" >&2; exit 4; }
rm -rf "${out:?}/.git"
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)" == "$TARGET_AFTER_SHA256" ]] || { echo "PATCHED TARGET SHA-256 MISMATCH" >&2; exit 7; }
fi
# nothing but the target may differ from the baseline export
tmp=$(mktemp -d "${TMPDIR:-/tmp}/oqc-base.XXXXXX")
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$tmp"
if ! diff -rq "$tmp" "$out" | grep -v "^Files $tmp/$TARGET and $out/$TARGET differ$" | grep -q . ; then :; else echo "UNEXPECTED DIFFERENCE OUTSIDE $TARGET" >&2; rm -rf "$tmp"; exit 8; fi
rm -rf "$tmp"
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
