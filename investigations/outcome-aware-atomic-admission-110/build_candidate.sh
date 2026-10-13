#!/usr/bin/env bash
# Builds the ISOLATED experimental-runtime tree for the #110 OAAA arm (selected at run time by env OAAA_ARM=OAAA).
#   bash investigations/outcome-aware-atomic-admission-110/build_candidate.sh <empty-out-dir>
# - verifies the sha-256 of the patch itself (exit 6) before anything else,
# - exports the pinned baseline with `git archive` (no checkout, no working-tree access),
# - verifies the sha-256 of both target files BEFORE patching and that the new interface file does not exist yet (exit 3),
# - applies patches/oaaa-candidate.patch (zero-context) with `git apply --unidiff-zero` (exit 4),
# - verifies the three patched files' hashes (exit 7) and that NO other file differs from the baseline export (exit 8),
# - copies vendor/ (Composer autoload is __DIR__-relative, so the copy loads ITS OWN src/, never the repository's),
# - writes BUILD_INFO.txt.
# The production tree is never modified; the resulting directory is a scratch artifact and is NEVER committed.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
BASE_SHA=72f6dea8ab0729e63492b622e682e334c527c966
PIPELINE=src/Service/EvaluationPipeline.php
REDIS=src/Repository/Redis/RedisFullCapabilityStore.php
IFACE=src/Repository/OutcomeAdmissionStoreInterface.php
PIPELINE_BEFORE=${OAAA_TEST_EXPECT_HASH:-4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc} # override: run.sh self-test only
REDIS_BEFORE=520574d4b0758470c0fc36ec304001825cf20a183e28bf6a999bcd41d54297a4
PATCH=${OAAA_TEST_PATCH_FILE:-$here/patches/oaaa-candidate.patch}                                   # override: run.sh self-test only
PATCH_SHA256=e8032f5de23e7185d49daa371c08dc56d7edf723268b24e2d0ece3ff24639aac
PIPELINE_AFTER=2af6f5599c809a829c0a7c792e35e49dc9e777482f2560250fa60f59067ffa20
REDIS_AFTER=a8af48c7661f1a466fe12416530598f7677583ae3683f7dd4a1eb3456002977f
IFACE_AFTER=8db98be51202774d48f540e577b8619895bf6046f45ae3b8e00d8f16058942e2
SKIP_PIN=${OAAA_TEST_SKIP_PIN:-0} # override: run.sh self-test only (lets a drifted patch reach `git apply`)
[[ -f "$PATCH" ]] || { echo "PATCH MISSING: $PATCH" >&2; exit 5; }
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$PATCH" | cut -d' ' -f1)" == "$PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH (not the committed candidate patch)" >&2; exit 6; }
fi
out=${1:?usage: build_candidate.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
git -C "$repo" cat-file -e "$BASE_SHA^{commit}" || { echo "baseline $BASE_SHA not available" >&2; exit 2; }
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$out"
[[ "$(shasum -a 256 "$out/$PIPELINE" | cut -d' ' -f1)" == "$PIPELINE_BEFORE" ]] || { echo "TARGET HASH MISMATCH: $PIPELINE" >&2; exit 3; }
[[ "$(shasum -a 256 "$out/$REDIS" | cut -d' ' -f1)" == "$REDIS_BEFORE" ]] || { echo "TARGET HASH MISMATCH: $REDIS" >&2; exit 3; }
[[ ! -e "$out/$IFACE" ]] || { echo "BASELINE ALREADY CONTAINS $IFACE" >&2; exit 3; }
git -C "$out" init -q . >/dev/null 2>&1 || true
(cd "$out" && git apply --unidiff-zero --check "$PATCH" && git apply --unidiff-zero "$PATCH") || { echo "PATCH DOES NOT APPLY (target drifted)" >&2; exit 4; }
rm -rf "${out:?}/.git"
if [[ "$SKIP_PIN" != "1" ]]; then
    [[ "$(shasum -a 256 "$out/$PIPELINE" | cut -d' ' -f1)" == "$PIPELINE_AFTER" ]] || { echo "PATCHED PIPELINE SHA-256 MISMATCH" >&2; exit 7; }
    [[ "$(shasum -a 256 "$out/$REDIS" | cut -d' ' -f1)" == "$REDIS_AFTER" ]] || { echo "PATCHED REDIS ADAPTER SHA-256 MISMATCH" >&2; exit 7; }
    [[ "$(shasum -a 256 "$out/$IFACE" | cut -d' ' -f1)" == "$IFACE_AFTER" ]] || { echo "EXPERIMENTAL INTERFACE SHA-256 MISMATCH" >&2; exit 7; }
fi
# nothing but the three patch targets may differ from the baseline export
tmp=$(mktemp -d "${TMPDIR:-/tmp}/oaaa-base.XXXXXX")
git -C "$repo" archive "$BASE_SHA" | tar -x -C "$tmp"
if diff -rq "$tmp" "$out" | grep -v -e "^Files $tmp/$PIPELINE and $out/$PIPELINE differ$" -e "^Files $tmp/$REDIS and $out/$REDIS differ$" -e "^Only in $out/src/Repository: OutcomeAdmissionStoreInterface.php$" | grep -q . ; then
    echo "UNEXPECTED DIFFERENCE OUTSIDE THE PATCH TARGETS" >&2
    rm -rf "$tmp"
    exit 8
fi
rm -rf "$tmp"
cp -R "$repo/vendor" "$out/vendor"
{
    echo "baseline_sha: $BASE_SHA"
    echo "patch_sha256: $(shasum -a 256 "$PATCH" | cut -d' ' -f1)"
    echo "pipeline_sha256_before: $PIPELINE_BEFORE"
    echo "pipeline_sha256_after: $(shasum -a 256 "$out/$PIPELINE" | cut -d' ' -f1)"
    echo "redis_adapter_sha256_before: $REDIS_BEFORE"
    echo "redis_adapter_sha256_after: $(shasum -a 256 "$out/$REDIS" | cut -d' ' -f1)"
    echo "interface_sha256: $(shasum -a 256 "$out/$IFACE" | cut -d' ' -f1)"
    echo "patch_files: $(grep -c '^+++ ' "$PATCH")"
    echo "patch_added_lines: $(grep -c '^+[^+]' "$PATCH")"
    echo "patch_removed_lines: $(grep -c '^-[^-]' "$PATCH")"
} >"$out/BUILD_INFO.txt"
echo "$out"
