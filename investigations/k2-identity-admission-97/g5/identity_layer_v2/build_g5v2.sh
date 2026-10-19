#!/usr/bin/env bash
# Builds the G5 v2 trees (PLAN.md §2.1). <out> receives, by the archived builders run UNCHANGED, the G4 / G5 trees
# (candidate, noesc, g4, c5, g5, g5-noalw, g5-noovf, g5-nok5, g5-noae), and on top of them:
#   <out>/g5v2          g5 + patches/g5v2-k4p-scope.diff (a settable scope for the account-wide P penalty state; inert when null)   G5_BUILD=G5V2
#   <out>/g5v2-noalw    g5v2 + the G5 ablation patch noalw, unchanged                                                              G5_BUILD=G5V2-NOALW
#   <out>/g5v2-noovf    g5v2 + the G5 ablation patch noovf, unchanged                                                              G5_BUILD=G5V2-NOOVF
#   <out>/g5v2-nok5     g5v2 + the G5 ablation patch nok5, unchanged                                                               G5_BUILD=G5V2-NOK5
#   <out>/g5v2-noae     g5v2 + the G5 ablation patch noae, unchanged                                                               G5_BUILD=G5V2-NOAE
# Every patch and every resulting pipeline is sha-256 pinned. The resolver and the DTO of every G5V2 tree are the G5 ones (asserted).
# The repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
g5=$(cd "$here/../identity_layer" && pwd)
out=${1:?usage: build_g5v2.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
PIPE=src/Service/EvaluationPipeline.php
RES=src/Service/DeviceIdentityResolver.php
DTO=src/DTO/DeviceIdentityDTO.php
G5V2_PATCH_SHA256=ab8d13c0050c5c3c646737148a7a950987df488fe507cf49374de17c901e7b89
G5V2_PIPE=dcf1161afe779bed2cbd22c3c9e5897449f1ef048cab5f68cd8aeaf0caa53b77
G5_RES=d0687dfbffee90b553126737e50b59c0596afa3e980f775a57a82bc67051924f
G5_DTO=2144979163664229217fbb7cab14edee9ce216b1487fc4bbb76b5f2171060994
# the G5 ablation patches (pinned by the G5 builder) and their resulting pipelines on the G5V2 tree
abl_patch() { case $1 in
    noalw) echo 8b114c87223640ef6dc5af335ddb4629689122cf23bbfa1df4bc671e55af514f ;;
    noovf) echo 5fede023edcafbb1090f923fcc83707aa23717cb2789cdda09db27b4abe33764 ;;
    nok5) echo 6374778b333fd21342696880008ce0e27efe19ed8d81c4a6de41497758f3f049 ;;
    noae) echo c78d8ac8e6ea4f8e2a9d43ec2af37dd63fece3e0d2651a9dd278035546004832 ;;
esac; }
abl_pipe() { case $1 in
    noalw) echo f59ce0084c697f3c71fbb01e5df7e493cf76767a9c34a4757225b0118e54fdce ;;
    noovf) echo b181947652606968609c43d9aa8f95dc9edcda9cd073dbeae6440d8b786a71e1 ;;
    nok5) echo 497596412ceb4ec947351389f4ed9888677bdbf38453b9f33ad57c554af43d71 ;;
    noae) echo dc77958d08a4a0bc3674cac6686214de02134156ac89ed3a7acf9990c17234b0 ;;
esac; }
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
bash "$g5/build_g5.sh" "$out" >/dev/null
apply() { # base-tree new-tree patch-path expected-patch-sha
    local base=$1 new=$2 patch=$3 want=$4
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
apply g5 g5v2 "$here/patches/g5v2-k4p-scope.diff" "$G5V2_PATCH_SHA256"
[[ "$(sha "$out/g5v2/$PIPE")" == "$G5V2_PIPE" ]] || { echo "G5V2 PATCHED SHA-256 MISMATCH" >&2; exit 7; }
[[ "$(sha "$out/g5v2/$RES")" == "$G5_RES" && "$(sha "$out/g5v2/$DTO")" == "$G5_DTO" ]] || { echo "G5V2 RESOLVER / DTO ARE NOT THE G5 ONES" >&2; exit 7; }
only g5 g5v2 "$PIPE"
{
    cat "$out/G5_BUILD_INFO.txt"
    echo "g5v2_patch_sha256: $G5V2_PATCH_SHA256"
    echo "g5v2_pipeline_sha256: $G5V2_PIPE"
} >"$out/G5V2_BUILD_INFO.txt"
for v in noalw noovf nok5 noae; do
    apply g5v2 "g5v2-$v" "$g5/patches/g5-ablation-$v.diff" "$(abl_patch $v)"
    [[ "$(sha "$out/g5v2-$v/$PIPE")" == "$(abl_pipe $v)" ]] || { echo "ABLATION PATCHED SHA-256 MISMATCH: $v" >&2; exit 7; }
    only g5v2 "g5v2-$v" "$PIPE"
    {
        echo "g5v2_${v}_pipeline_sha256: $(abl_pipe $v)"
    } >>"$out/G5V2_BUILD_INFO.txt"
done
echo "$out"
