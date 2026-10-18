#!/usr/bin/env bash
# Builds the G4 trees (PLAN.md §1), selected at run time with G1_ARM=CAND + G4_BUILD:
#   <out>/candidate  G1 candidate (G3 builder)                                    G4_BUILD=CAND
#   <out>/noesc      CAND + G3 g3-ablation-no-k4u-persist.diff (G3 builder)       G4_BUILD=NOESC
#   <out>/g4         NOESC + patches/g4-k5-separation.diff                        G4_BUILD=G4
#   <out>/c5         CAND + patches/g4-k5-separation.diff                         G4_BUILD=C5
# Every patch and every resulting pipeline is sha-256 pinned. The repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
out=${1:?usage: build_g4.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
TARGET=src/Service/EvaluationPipeline.php
K5SEP_PATCH_SHA256=7af55e4ae1a36891c947120dc93f5a437bb5ab466aa90ee30361bea311f278b1
G4_AFTER=45d201d0ab9d107eb5299ad8f17fcd5d6c8bdc3120bae24df9e6e3e0fd0863fd
C5_AFTER=3111c315cbdc398a870ea396138c09d4b28e68127c9c955fd8a657e214ea6f79
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
tmp="$out/g3"
bash "$here/../../g3/admission_budgets/build_g3.sh" "$tmp" >/dev/null
mv "$tmp/candidate" "$out/candidate"
mv "$tmp/noesc" "$out/noesc"
cp "$tmp/G3_BUILD_INFO.txt" "$out/G3_BUILD_INFO.txt"
rm -rf "$tmp"
patch="$here/patches/g4-k5-separation.diff"
[[ "$(sha "$patch")" == "$K5SEP_PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH: $patch" >&2; exit 6; }
apply() { # base-tree new-tree expected-sha
    cp -R "$out/$1" "$out/$2"
    (cd "$out/$2" && git init -q . && git apply --check "$patch" && git apply "$patch" && rm -rf .git) || { echo "PATCH DOES NOT APPLY: $2" >&2; exit 4; }
    [[ "$(sha "$out/$2/$TARGET")" == "$3" ]] || { echo "PATCHED SHA-256 MISMATCH: $2" >&2; exit 7; }
    n=$(diff -rq "$out/$1" "$out/$2" | grep -v -e "^Files $out/$1/$TARGET and $out/$2/$TARGET differ$" | grep -c . || true)
    [[ "$n" == "0" ]] || { echo "UNEXPECTED DIFFERENCE OUTSIDE THE PIPELINE: $2" >&2; exit 8; }
}
apply noesc g4 "$G4_AFTER"
apply candidate c5 "$C5_AFTER"
{
    cat "$out/G3_BUILD_INFO.txt"
    echo "k5sep_patch_sha256: $K5SEP_PATCH_SHA256"
    echo "g4_pipeline_sha256: $G4_AFTER"
    echo "c5_pipeline_sha256: $C5_AFTER"
} >"$out/G4_BUILD_INFO.txt"
echo "$out"
