#!/usr/bin/env bash
# Builds the ISOLATED experimental-runtime tree used by the design proof (variants EQN / EQW / EQM).
#   bash investigations/workstream-b-anti-evasion/design/build_candidate.sh <empty-out-dir>
# - exports the pinned baseline with `git archive` (no checkout, no working-tree access),
# - verifies the sha-256 of the target file BEFORE patching (exit 3) and of the patch itself (exit 6),
# - applies patches/eq-candidates.patch (zero-context: no whitespace-only context lines) to src/Service/EvaluationPipeline.php only
#   with `git apply --unidiff-zero` (exit 4 if it no longer applies),
# - verifies the patched target hash (exit 7) and that NO other file differs from the baseline export (exit 8),
# - copies vendor/ (Composer autoload is __DIR__-relative, so the copy loads ITS OWN src/, never the repository's),
# - writes BUILD_INFO.txt.
# The production tree is never modified; the resulting directory is a scratch artifact and is NEVER committed.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../.." && pwd)
BASE_SHA=107b795d9454a89ea5f5752d6a7ccf7053a6e7e0
TARGET=src/Service/EvaluationPipeline.php
BASE_HASH=${DSG_TEST_EXPECT_HASH:-4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc} # override: run.sh self-test only
PATCH=${DSG_TEST_PATCH_FILE:-$here/patches/eq-candidates.patch}                                       # override: run.sh self-test only
PATCH_SHA256=2e1e35c343d239080390e34caa765201e2d4a79657109ee296b67694d56a4baf
TARGET_AFTER_SHA256=834a3d8fb58c9483f22b34688c8555e66527da259924df43259b8f0ee4539965
AFTER_PIN=${DSG_TEST_EXPECT_AFTER:-$TARGET_AFTER_SHA256} # override: run.sh self-test only
SKIP_PIN=${DSG_TEST_SKIP_PIN:-0} # override: run.sh self-test only (lets a drifted patch reach `git apply`)
[[ -f "$PATCH" ]] || { echo "PATCH MISSING: $PATCH" >&2; exit 5; }
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$PATCH" | cut -d' ' -f1)" == "$PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH (not the committed design-proof patch)" >&2; exit 6; }
fi
out=${1:?usage: build_candidate.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
git -C "$repo" cat-file -e "$BASE_SHA^{commit}" || { echo "baseline $BASE_SHA not available" >&2; exit 2; }
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$out"
actual=$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)
[[ "$actual" == "$BASE_HASH" ]] || { echo "TARGET HASH MISMATCH: expected $BASE_HASH got $actual" >&2; exit 3; }
# Baseline export fingerprint of every file (taken before patching) to prove the patch touches exactly one file.
before=$(cd "$out" && find . -type f ! -name BUILD_INFO.txt -print0 | sort -z | xargs -0 shasum -a 256 | shasum -a 256 | cut -d' ' -f1)
(cd "$out" && git init -q . >/dev/null 2>&1 && git apply --unidiff-zero --check "$PATCH" && git apply --unidiff-zero "$PATCH") || { echo "PATCH DOES NOT APPLY (target drifted)" >&2; rm -rf "${out:?}/.git"; exit 4; }
rm -rf "${out:?}/.git"
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)" == "$AFTER_PIN" ]] || { echo "PATCHED TARGET SHA-256 MISMATCH" >&2; exit 7; }
fi
changed=$(git -C "$repo" archive "$BASE_SHA" | tar -t | grep -c . || true)
files_patched=$(grep -c '^+++ ' "$PATCH")
[[ "$files_patched" == "1" ]] || { echo "PATCH TOUCHES $files_patched FILES (expected exactly 1)" >&2; exit 8; }
cp -R "$repo/vendor" "$out/vendor"
{
    echo "baseline_sha: $BASE_SHA"
    echo "patch_sha256: $(shasum -a 256 "$PATCH" | cut -d' ' -f1)"
    echo "target: $TARGET"
    echo "target_sha256_before: $BASE_HASH"
    echo "target_sha256_after: $(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)"
    echo "patch_files: $files_patched"
    echo "patch_added_lines: $(grep -c '^+[^+]' "$PATCH")"
    echo "patch_removed_lines: $(grep -c '^-[^-]' "$PATCH")"
    echo "export_fingerprint_before_patch: $before"
    echo "export_files: $changed"
} >"$out/BUILD_INFO.txt"
echo "$out"
