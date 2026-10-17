#!/usr/bin/env bash
# Builds the verification trees (VERIFY_PLAN.md §1), all selected at run time with G1_ARM=CAND + G1_BUILD:
#   <out>/candidate  the unchanged G1 candidate (../build_candidate.sh, its own hash checks)
#   <out>/trace      candidate + patches/g1-trace.diff        (instrumentation only; G1_BUILD=TRACE)
#   <out>/mutant     trace + patches/g1-mutant-correlation.diff (C10 detector control; G1_BUILD=MUTANT)
# Every patch and every resulting pipeline is sha-256 pinned. The repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
out=${1:?usage: build_verify.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
TARGET=src/Service/EvaluationPipeline.php
CAND_AFTER=1840d6e2b57cd57674beb7101d25d458e97949b719d473580db28f50ac13dfd9
TRACE_PATCH_SHA256=54502e61519edee47be1fd21ecf5bb3bcae80f031f242f589713e4f792924262
TRACE_AFTER=9c23ec24c11b89d2e8e8376a473f76270590b42e7828526b30206d3d25aecf6d
MUTANT_PATCH_SHA256=186dda4c5e04f1e7590fc7a14bfa6aba8ba695d2ccdc5c6b85652c3e20e28165
MUTANT_AFTER=ecc9928d211cfd4366fce1975a72c9ab7dfaf1eedb39a2cde61c8c45615c4661
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
apply() { # tree patch expected-patch-sha expected-after-sha
    [[ "$(sha "$2")" == "$3" ]] || { echo "PATCH SHA-256 MISMATCH: $2" >&2; exit 6; }
    (cd "$1" && git init -q . && git apply --check "$2" && git apply "$2" && rm -rf .git) || { echo "PATCH DOES NOT APPLY: $2" >&2; exit 4; }
    [[ "$(sha "$1/$TARGET")" == "$4" ]] || { echo "PATCHED SHA-256 MISMATCH: $1" >&2; exit 7; }
}
bash "$here/../build_candidate.sh" "$out/candidate" >/dev/null
[[ "$(sha "$out/candidate/$TARGET")" == "$CAND_AFTER" ]] || { echo "CANDIDATE SHA-256 MISMATCH" >&2; exit 7; }
cp -R "$out/candidate" "$out/trace"
apply "$out/trace" "$here/patches/g1-trace.diff" "$TRACE_PATCH_SHA256" "$TRACE_AFTER"
cp -R "$out/trace" "$out/mutant"
apply "$out/mutant" "$here/patches/g1-mutant-correlation.diff" "$MUTANT_PATCH_SHA256" "$MUTANT_AFTER"
for t in trace mutant; do
    n=$(diff -rq "$out/candidate" "$out/$t" | grep -v -e "^Files $out/candidate/$TARGET and $out/$t/$TARGET differ$" -e "BUILD_INFO" | grep -c . || true)
    [[ "$n" == "0" ]] || { echo "UNEXPECTED DIFFERENCE OUTSIDE THE PIPELINE IN $t" >&2; exit 8; }
done
{
    echo "candidate_pipeline_sha256: $CAND_AFTER"
    echo "trace_patch_sha256: $TRACE_PATCH_SHA256"
    echo "trace_pipeline_sha256: $TRACE_AFTER"
    echo "mutant_patch_sha256: $MUTANT_PATCH_SHA256"
    echo "mutant_pipeline_sha256: $MUTANT_AFTER"
} >"$out/VERIFY_BUILD_INFO.txt"
echo "$out"
