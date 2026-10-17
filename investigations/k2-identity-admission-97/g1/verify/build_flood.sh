#!/usr/bin/env bash
# Builds the flood ablation tree (FLOOD_PLAN.md §1) on top of the DA trees (build_da.sh):
#   <out>/daof  dao + patches/g1-ablation-no-flood-punishment.diff (G1_BUILD=DAOF)
# Patch and resulting pipeline are sha-256 pinned; the repository tree is never modified.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
out=${1:?usage: build_flood.sh <verify-work-dir-with-dao>}
TARGET=src/Service/EvaluationPipeline.php
DAO_AFTER=fa29130ab27955a46081ffa098c5a8071b11bc69e3c6daed72a6325c20e0ca96
PATCH=g1-ablation-no-flood-punishment.diff
PATCH_SHA256=8a6453d0487753c9abf6be93efe1c5ed0b50f58f326331f92c382c3e91248f6d
AFTER=0b6a52a72fcbf85757b3ba605e15288ae19662d9fd645f10ea5e153183d1984c
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
[[ "$(sha "$out/dao/$TARGET")" == "$DAO_AFTER" ]] || { echo "DAO TREE MISSING OR WRONG" >&2; exit 3; }
[[ "$(sha "$here/patches/$PATCH")" == "$PATCH_SHA256" ]] || { echo "PATCH SHA-256 MISMATCH" >&2; exit 6; }
rm -rf "${out:?}/daof"
cp -R "$out/dao" "$out/daof"
(cd "$out/daof" && git init -q . && git apply --check "$here/patches/$PATCH" && git apply "$here/patches/$PATCH" && rm -rf .git) || { echo "PATCH DOES NOT APPLY" >&2; exit 4; }
[[ "$(sha "$out/daof/$TARGET")" == "$AFTER" ]] || { echo "PATCHED SHA-256 MISMATCH" >&2; exit 7; }
n=$(diff -rq "$out/dao" "$out/daof" | grep -v -e "^Files $out/dao/$TARGET and $out/daof/$TARGET differ$" | grep -c . || true)
[[ "$n" == "0" ]] || { echo "UNEXPECTED DIFFERENCE OUTSIDE THE PIPELINE" >&2; exit 8; }
echo "$out"
