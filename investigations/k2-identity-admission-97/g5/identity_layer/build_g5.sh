#!/usr/bin/env bash
# Builds the G5 trees (PLAN.md §2.1), selected at run time with G1_ARM=CAND + G5_BUILD:
#   <out>/candidate, <out>/noesc, <out>/g4, <out>/c5   the archived G4 builder, unchanged       G5_BUILD=CAND | G4
#   <out>/g5          g4 + patches/g5-k5-id-material.diff (DTO, resolver, pipeline)             G5_BUILD=G5
#   <out>/g5-noalw    g5 + patches/g5-ablation-noalw.diff   (allowance consumption disabled)   G5_BUILD=G5-NOALW
#   <out>/g5-noovf    g5 + patches/g5-ablation-noovf.diff   (K4 overflow delta not applied)    G5_BUILD=G5-NOOVF
#   <out>/g5-nok5     g5 + patches/g5-ablation-nok5.diff    (K5 failure delta forced to 0)     G5_BUILD=G5-NOK5
#   <out>/g5-noae     g5 + patches/g5-ablation-noae.diff    (device watcher disabled)          G5_BUILD=G5-NOAE
# Every patch and every resulting pipeline / resolver / DTO is sha-256 pinned. The repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
out=${1:?usage: build_g5.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
PIPE=src/Service/EvaluationPipeline.php
RES=src/Service/DeviceIdentityResolver.php
DTO=src/DTO/DeviceIdentityDTO.php
G5_PATCH_SHA256=4e4a9878d17f87dc6922211ded88207c1b3a620b0cb220bf53ce1f2ecf3c047c
G5_PIPE=6ca8cec495cf3553ce8857cdb8fce4b0e774e912e32a201ac20e4d351f56c1b6
G5_RES=d0687dfbffee90b553126737e50b59c0596afa3e980f775a57a82bc67051924f
G5_DTO=2144979163664229217fbb7cab14edee9ce216b1487fc4bbb76b5f2171060994
abl_patch() { case $1 in
    noalw) echo 8b114c87223640ef6dc5af335ddb4629689122cf23bbfa1df4bc671e55af514f ;;
    noovf) echo 0f85a48c7063938545396b85ed0b15212cba3924ec7c50d1f155e03e1a24fffc ;;
    nok5) echo 6374778b333fd21342696880008ce0e27efe19ed8d81c4a6de41497758f3f049 ;;
    noae) echo c78d8ac8e6ea4f8e2a9d43ec2af37dd63fece3e0d2651a9dd278035546004832 ;;
esac; }
abl_pipe() { case $1 in
    noalw) echo cbcd798118cc4c15161377409fb2bee95dc7b055c85c7ceb1599ef80fd79aec4 ;;
    noovf) echo 8b1da7781045cc518bbf87174dc61064851d7f921b56d5265c285616c317c65f ;;
    nok5) echo 5808e750c08ebe4ee10f15c1df584cdafc1c815643ed895db6cef32d4842cc99 ;;
    noae) echo c3522d1f7622e0535754dba15290b0feed39073b16f0678a50f69315e44f33c1 ;;
esac; }
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
bash "$here/../../g4/integrated/build_g4.sh" "$out" >/dev/null
apply() { # base-tree new-tree patch expected-patch-sha ; leaves the new tree patched, checks nothing else differs
    local base=$1 new=$2 patch="$here/patches/$3" want=$4
    [[ "$(sha "$patch")" == "$want" ]] || { echo "PATCH SHA-256 MISMATCH: $patch" >&2; exit 6; }
    cp -R "$out/$base" "$out/$new"
    (cd "$out/$new" && git init -q . && git apply --check "$patch" && git apply "$patch" && rm -rf .git) || { echo "PATCH DOES NOT APPLY: $new" >&2; exit 4; }
}
only() { # base new file... : the new tree differs from the base in exactly these files
    local base=$1 new=$2; shift 2
    local n
    n=$(diff -rq "$out/$base" "$out/$new" | grep -v -F -e "$(for f in "$@"; do echo "Files $out/$base/$f and $out/$new/$f differ"; done)" | grep -c . || true)
    [[ "$n" == "0" ]] || { echo "UNEXPECTED DIFFERENCE OUTSIDE THE PATCH TARGETS: $new" >&2; exit 8; }
}
apply g4 g5 g5-k5-id-material.diff "$G5_PATCH_SHA256"
[[ "$(sha "$out/g5/$PIPE")" == "$G5_PIPE" && "$(sha "$out/g5/$RES")" == "$G5_RES" && "$(sha "$out/g5/$DTO")" == "$G5_DTO" ]] || { echo "G5 PATCHED SHA-256 MISMATCH" >&2; exit 7; }
only g4 g5 "$PIPE" "$RES" "$DTO"
{
    cat "$out/G4_BUILD_INFO.txt"
    echo "g5_patch_sha256: $G5_PATCH_SHA256"
    echo "g5_pipeline_sha256: $G5_PIPE"
    echo "g5_resolver_sha256: $G5_RES"
    echo "g5_dto_sha256: $G5_DTO"
} >"$out/G5_BUILD_INFO.txt"
for v in noalw noovf nok5 noae; do
    apply g5 "g5-$v" "g5-ablation-$v.diff" "$(abl_patch $v)"
    [[ "$(sha "$out/g5-$v/$PIPE")" == "$(abl_pipe $v)" ]] || { echo "ABLATION PATCHED SHA-256 MISMATCH: $v" >&2; exit 7; }
    only g5 "g5-$v" "$PIPE"
    {
        echo "g5_${v}_patch_sha256: $(abl_patch $v)"
        echo "g5_${v}_pipeline_sha256: $(abl_pipe $v)"
    } >>"$out/G5_BUILD_INFO.txt"
done
echo "$out"
