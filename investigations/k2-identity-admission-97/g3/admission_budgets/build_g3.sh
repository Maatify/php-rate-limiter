#!/usr/bin/env bash
# Builds the G3 trees (PLAN.md §2), selected at run time with G1_ARM=CAND + G3_BUILD:
#   <out>/candidate  the unchanged G1 candidate (../../g1/build_candidate.sh, its own hash checks)       G3_BUILD=CAND
#   <out>/noesc      candidate + patches/g3-ablation-no-k4u-persist.diff                                  G3_BUILD=NOESC
# Every patch and every resulting pipeline is sha-256 pinned. The repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
out=${1:?usage: build_g3.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
TARGET=src/Service/EvaluationPipeline.php
CAND_AFTER=1840d6e2b57cd57674beb7101d25d458e97949b719d473580db28f50ac13dfd9
NOESC_PATCH_SHA256=fdd85079c633c18e844b577ac38da3d5c02780b190462724fc3f2f488942cd2b
NOESC_AFTER=2926e1c5d6ce058e62bd06a9aec9fe86e532b8870d9af54a3fe1f42ec55e1db4
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
bash "$here/../../g1/build_candidate.sh" "$out/candidate" >/dev/null
[[ "$(sha "$out/candidate/$TARGET")" == "$CAND_AFTER" ]] || { echo "CANDIDATE SHA-256 MISMATCH" >&2; exit 7; }
cp -R "$out/candidate" "$out/noesc"
patch="$here/patches/g3-ablation-no-k4u-persist.diff"
[[ "$(sha "$patch")" == "$NOESC_PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH: $patch" >&2; exit 6; }
(cd "$out/noesc" && git init -q . && git apply --check "$patch" && git apply "$patch" && rm -rf .git) || { echo "PATCH DOES NOT APPLY" >&2; exit 4; }
[[ "$(sha "$out/noesc/$TARGET")" == "$NOESC_AFTER" ]] || { echo "PATCHED SHA-256 MISMATCH" >&2; exit 7; }
n=$(diff -rq "$out/candidate" "$out/noesc" | grep -v -e "^Files $out/candidate/$TARGET and $out/noesc/$TARGET differ$" | grep -c . || true)
[[ "$n" == "0" ]] || { echo "UNEXPECTED DIFFERENCE OUTSIDE THE PIPELINE" >&2; exit 8; }
{
    echo "candidate_pipeline_sha256: $CAND_AFTER"
    echo "noesc_patch_sha256: $NOESC_PATCH_SHA256"
    echo "noesc_pipeline_sha256: $NOESC_AFTER"
} >"$out/G3_BUILD_INFO.txt"
echo "$out"
