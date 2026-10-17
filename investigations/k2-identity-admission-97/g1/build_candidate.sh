#!/usr/bin/env bash
# Builds the ISOLATED G1 candidate runtime (selected at run time by env G1_ARM=CAND).
#   bash investigations/k2-identity-admission-97/g1/build_candidate.sh <empty-out-dir>
# - verifies the sha-256 of the committed patch (exit 6),
# - exports the pinned baseline with `git archive` (no checkout, no working-tree access),
# - verifies the target file's sha-256 BEFORE patching (exit 3), applies the patch (exit 4) and verifies it AFTER (exit 7),
# - verifies that NO other file differs from the baseline export (exit 8),
# - copies vendor/ (the autoloader is __DIR__-relative, so the copy loads ITS OWN src/, never the repository's),
# - writes BUILD_INFO.txt.
# The repository tree is never modified; the output directory is scratch and is never committed.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../../.." && pwd)
BASE_SHA=4c6ca618d8206ba502961eaf06ffb82a5d61b50d
TARGET=src/Service/EvaluationPipeline.php
TARGET_BEFORE=402139452040cb319208ca7a441b2f70210bf1acee4bd4a0ad666e8101ef6eab
TARGET_AFTER=1840d6e2b57cd57674beb7101d25d458e97949b719d473580db28f50ac13dfd9
PATCH="$here/patches/g1-candidate.diff"
PATCH_SHA256=0caafe66ade9631ac5186b3c215947899aec8e91a5969b5e8a1f9db6af790d57
[[ -f "$PATCH" ]] || { echo "PATCH MISSING: $PATCH" >&2; exit 5; }
[[ "$(shasum -a 256 "$PATCH" | cut -d' ' -f1)" == "$PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH" >&2; exit 6; }
out=${1:?usage: build_candidate.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
[[ -f "$repo/vendor/autoload.php" ]] || { echo "no vendor/ in $repo — run 'composer install' first" >&2; exit 2; }
git -C "$repo" cat-file -e "$BASE_SHA^{commit}" || { echo "baseline $BASE_SHA not available" >&2; exit 2; }
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$out"
[[ "$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)" == "$TARGET_BEFORE" ]] || { echo "TARGET HASH MISMATCH BEFORE PATCH" >&2; exit 3; }
(cd "$out" && git init -q . && git apply --check "$PATCH" && git apply "$PATCH" && rm -rf .git) || { echo "PATCH DOES NOT APPLY" >&2; exit 4; }
[[ "$(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)" == "$TARGET_AFTER" ]] || { echo "PATCHED TARGET SHA-256 MISMATCH" >&2; exit 7; }
tmp=$(mktemp -d "${TMPDIR:-/tmp}/g1-base.XXXXXX")
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$tmp"
if diff -rq "$tmp" "$out" | grep -v -e "^Files $tmp/$TARGET and $out/$TARGET differ$" | grep -q . ; then
    echo "UNEXPECTED DIFFERENCE OUTSIDE THE PATCH TARGET" >&2
    rm -rf "$tmp"
    exit 8
fi
rm -rf "$tmp"
cp -R "$repo/vendor" "$out/vendor"
{
    echo "baseline_sha: $BASE_SHA"
    echo "patch_sha256: $PATCH_SHA256"
    echo "pipeline_sha256_before: $TARGET_BEFORE"
    echo "pipeline_sha256_after: $(shasum -a 256 "$out/$TARGET" | cut -d' ' -f1)"
    echo "patch_added_lines: $(grep -c '^+[^+]' "$PATCH")"
    echo "patch_removed_lines: $(grep -c '^-[^-]' "$PATCH")"
} >"$out/BUILD_INFO.txt"
echo "$out"
