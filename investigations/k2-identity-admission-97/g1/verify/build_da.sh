#!/usr/bin/env bash
# Builds the DA ablation trees (DA_T1_PLAN.md §1) on top of the verification trees (build_verify.sh):
#   <out>/dam  trace + patches/g1-ablation-da-members.diff     (CAND-DA-M; G1_BUILD=DAM)
#   <out>/dao  trace + patches/g1-ablation-no-da-punishment.diff (CAND-no-DA-punishment; G1_BUILD=DAO)
# Patches and resulting pipelines are sha-256 pinned; the repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
out=${1:?usage: build_da.sh <verify-work-dir-with-trace>}
TARGET=src/Service/EvaluationPipeline.php
TRACE_AFTER=9c23ec24c11b89d2e8e8376a473f76270590b42e7828526b30206d3d25aecf6d
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
[[ "$(sha "$out/trace/$TARGET")" == "$TRACE_AFTER" ]] || { echo "TRACE TREE MISSING OR WRONG" >&2; exit 3; }
apply() { # name patch patch-sha after-sha
    rm -rf "${out:?}/$1"
    cp -R "$out/trace" "$out/$1"
    [[ "$(sha "$here/patches/$2")" == "$3" ]] || { echo "PATCH SHA-256 MISMATCH: $2" >&2; exit 6; }
    (cd "$out/$1" && git init -q . && git apply --check "$here/patches/$2" && git apply "$here/patches/$2" && rm -rf .git) || { echo "PATCH DOES NOT APPLY: $2" >&2; exit 4; }
    [[ "$(sha "$out/$1/$TARGET")" == "$4" ]] || { echo "PATCHED SHA-256 MISMATCH: $1" >&2; exit 7; }
    n=$(diff -rq "$out/trace" "$out/$1" | grep -v -e "^Files $out/trace/$TARGET and $out/$1/$TARGET differ$" | grep -c . || true)
    [[ "$n" == "0" ]] || { echo "UNEXPECTED DIFFERENCE OUTSIDE THE PIPELINE IN $1" >&2; exit 8; }
}
apply dam g1-ablation-da-members.diff 95f66fd9565c1274bf2cc1918a6768d6924320af0aed1f463b60ed881c97e77d abd2e51f2ccba4a4da1bff973c532387a8f13e87f1970e49547732c65fafe6e7
apply dao g1-ablation-no-da-punishment.diff c56e7dff7ba6dd4f34bfd80d90d1322e948e899839f90a6ea6a50ece70844355 fa29130ab27955a46081ffa098c5a8071b11bc69e3c6daed72a6325c20e0ca96
echo "$out"
