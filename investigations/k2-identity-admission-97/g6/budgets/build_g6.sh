#!/usr/bin/env bash
# Builds the G6 trees (PLAN.md §2.1). <out> receives, by the archived builders run UNCHANGED, the G4 / G5 / G5V2 trees
# (candidate, noesc, g4, c5, g5, g5-*, g5v2, g5v2-*), and on top of them:
#   <out>/g6uk          g5v2 + patches/g6-uk-lane.diff (a third study lane value `UK`: the U door of a Host-known request with the DEC-021 allowance,
#                       K4 overflow, watcher and budget on their own `_uk` / `k4uk` state; inert when the lane is never requested)         G5_BUILD=G6UK
# The patch and the resulting pipeline are sha-256 pinned. The resolver and the DTO of the G6UK tree are the G5 ones (asserted).
# The repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
g5v2=$(cd "$here/../../g5/identity_layer_v2" && pwd)
out=${1:?usage: build_g6.sh <empty-out-dir>}
mkdir -p "$out"
[[ -z "$(ls -A "$out")" ]] || { echo "out-dir must be empty: $out" >&2; exit 2; }
PIPE=src/Service/EvaluationPipeline.php
RES=src/Service/DeviceIdentityResolver.php
DTO=src/DTO/DeviceIdentityDTO.php
G6_PATCH_SHA256=d7e5d4aa9df3c871206daef855b2e7f04d5c8a87ab7bcf21f2953e80d2c1b050
G6_PIPE=924ce11890c8c0e5186a6740c574ca3cd4f2992eb8f7a3c453f8493ed4f8e7a7
G5V2_PIPE=dcf1161afe779bed2cbd22c3c9e5897449f1ef048cab5f68cd8aeaf0caa53b77
G5_RES=d0687dfbffee90b553126737e50b59c0596afa3e980f775a57a82bc67051924f
G5_DTO=2144979163664229217fbb7cab14edee9ce216b1487fc4bbb76b5f2171060994
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
bash "$g5v2/build_g5v2.sh" "$out" >/dev/null
[[ "$(sha "$out/g5v2/$PIPE")" == "$G5V2_PIPE" ]] || { echo "G5V2 BASE PIPELINE SHA-256 MISMATCH" >&2; exit 7; }
patch="$here/patches/g6-uk-lane.diff"
[[ "$(sha "$patch")" == "$G6_PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH: $patch" >&2; exit 6; }
cp -R "$out/g5v2" "$out/g6uk"
(cd "$out/g6uk" && git init -q . && git apply --check "$patch" && git apply "$patch" && rm -rf .git) || { echo "PATCH DOES NOT APPLY: g6uk" >&2; exit 4; }
[[ "$(sha "$out/g6uk/$PIPE")" == "$G6_PIPE" ]] || { echo "G6UK PATCHED SHA-256 MISMATCH" >&2; exit 7; }
[[ "$(sha "$out/g6uk/$RES")" == "$G5_RES" && "$(sha "$out/g6uk/$DTO")" == "$G5_DTO" ]] || { echo "G6UK RESOLVER / DTO ARE NOT THE G5 ONES" >&2; exit 7; }
n=$(diff -rq "$out/g5v2" "$out/g6uk" | grep -v -F "Files $out/g5v2/$PIPE and $out/g6uk/$PIPE differ" | grep -c . || true)
[[ "$n" == "0" ]] || { echo "UNEXPECTED DIFFERENCE OUTSIDE THE PATCH TARGET: g6uk" >&2; exit 8; }
{
    cat "$out/G5V2_BUILD_INFO.txt"
    echo "g6_patch_sha256: $G6_PATCH_SHA256"
    echo "g6_pipeline_sha256: $G6_PIPE"
} >"$out/G6_BUILD_INFO.txt"
echo "$out"
