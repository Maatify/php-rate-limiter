#!/usr/bin/env bash
# Builds an ISOLATED experimental-runtime tree for the unified feasibility study (#97 / #100).
#   bash investigations/workstream-b-anti-evasion/feasibility/build_candidate.sh <psc|eq> <empty-out-dir>
#     psc : baseline + patches/psc-candidate.patch            (variants PSC, PSCA)   -- this study's candidate
#     eq  : baseline + ../design/patches/eq-candidates.patch  (variants EQL, EQV)    -- PR #105's published patch, read in place, never modified
# - exports the pinned baseline with `git archive` (no checkout, no working-tree access),
# - verifies the sha-256 of the target file BEFORE patching (exit 3) and of the patch itself (exit 6),
# - applies the zero-context patch to src/Service/EvaluationPipeline.php only (`git apply --unidiff-zero`; exit 4 if it does not apply),
# - verifies the patched target hash (exit 7) and that the patch touches exactly one file (exit 8),
# - copies vendor/ (Composer autoload is __DIR__-relative, so the copy loads ITS OWN src/, never the repository's),
# - writes BUILD_INFO.txt. The production tree is never modified; the output directory is a scratch artifact and is NEVER committed.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../.." && pwd)
BASE_SHA=b34f12eb3f892c2dd6fa423280ead0cdaafc868f
TARGET=src/Service/EvaluationPipeline.php
BASE_HASH=${FSB_TEST_EXPECT_HASH:-4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc} # override: run.sh self-test only
family=${1:?usage: build_candidate.sh <psc|eq> <empty-out-dir>}
out=${2:?usage: build_candidate.sh <psc|eq> <empty-out-dir>}
case "$family" in
    psc)
        PATCH=${FSB_TEST_PATCH_FILE:-$here/patches/psc-candidate.patch}
        PATCH_SHA256=9d454875c87b28e2f4792bd44c52ddb177fd9aa1d487ccc3fbf41166efbddb85
        TARGET_AFTER_SHA256=86beb1632f023778b74f916f95aa39fc48bdffe931e9fe3450dd54d13ee3fbe5
        ;;
    eq)
        PATCH=${FSB_TEST_PATCH_FILE:-$here/../design/patches/eq-candidates.patch}
        PATCH_SHA256=62eafa5a34781da1ab934d4ecb8d924e6bfac27eb16040f3b08a281817ef1bb4
        TARGET_AFTER_SHA256=98d95cc97836066ea8e76cf8399a41ede457c1e3dca156fdaa05b1b3096813c4
        ;;
    *) echo "unknown family: $family" >&2; exit 2 ;;
esac
AFTER_PIN=${FSB_TEST_EXPECT_AFTER:-$TARGET_AFTER_SHA256} # override: run.sh self-test only
SKIP_PIN=${FSB_TEST_SKIP_PIN:-0}                         # override: run.sh self-test only (lets a drifted patch reach `git apply`)
[[ -f "$PATCH" ]] || { echo "PATCH MISSING: $PATCH" >&2; exit 5; }
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$PATCH" | cut -d' ' -f1)" == "$PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH (not the committed patch)" >&2; exit 6; }
fi
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
git -C "$repo" cat-file -e "$BASE_SHA^{commit}" || { echo "baseline $BASE_SHA not available" >&2; exit 2; }
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$out"
actual=$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)
[[ "$actual" == "$BASE_HASH" ]] || { echo "TARGET HASH MISMATCH: expected $BASE_HASH got $actual" >&2; exit 3; }
before=$(cd "$out" && find . -type f ! -name BUILD_INFO.txt -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256 | cut -d' ' -f1)
(cd "$out" && git init -q . >/dev/null 2>&1 && git apply --unidiff-zero --check "$PATCH" && git apply --unidiff-zero "$PATCH") || { echo "PATCH DOES NOT APPLY (target drifted)" >&2; rm -rf "${out:?}/.git"; exit 4; }
rm -rf "${out:?}/.git"
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)" == "$AFTER_PIN" ]] || { echo "PATCHED TARGET SHA-256 MISMATCH" >&2; exit 7; }
fi
files_patched=$(grep -c '^+++ ' "$PATCH")
[[ "$files_patched" == "1" ]] || { echo "PATCH TOUCHES $files_patched FILES (expected exactly 1)" >&2; exit 8; }
cp -R "$repo/vendor" "$out/vendor"
{
    echo "family: $family"
    echo "baseline_sha: $BASE_SHA"
    echo "patch_sha256: $(shasum -a 256 "$PATCH" | cut -d' ' -f1)"
    echo "target: $TARGET"
    echo "target_sha256_before: $BASE_HASH"
    echo "target_sha256_after: $(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)"
    echo "patch_files: $files_patched"
    echo "patch_added_lines: $(grep -c '^+[^+]' "$PATCH")"
    echo "patch_removed_lines: $(grep -c '^-[^-]' "$PATCH")"
    echo "export_fingerprint_before_patch: $before"
} >"$out/BUILD_INFO.txt"
echo "$out"
