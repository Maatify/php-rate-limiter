#!/usr/bin/env bash
# PROVENANCE: copy of investigations/flaky-160/phase2/harness/provenance_check.sh at d37f9a3, sha256 f948b156b93e2bf690bf17afd2f6ae9ea28dffc39c9a3d279d33845350ba6c21, ADAPTED: the originals are read at d37f9a3 instead of 8b79e9b; the difference is recorded in PROVENANCE-provenance_check.sh.diff. Delete this line and reverse that diff to get the original byte for byte.
# #160 phase 3 provenance check (PLAN.md §7, §13 LT-160D-07): the original at
# d37f9a3 must hash to the recorded SHA-256, and every copy listed in
# PROVENANCE.txt, without its line 2, must equal it byte for byte; an entry
# marked adapted:<diff> must instead differ from it by exactly that recorded
# diff. Prints one line per file; exit 1 on any mismatch.
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../../.." && pwd)"
status=0
while read -r copy original sha adapted; do
    [[ -z "$copy" || "$copy" == \#* ]] && continue
    body="$(sed '2d' "$here/$copy" | shasum -a 256 | cut -d' ' -f1)"
    at_head="$(git -C "$root" show "d37f9a3:$original" | shasum -a 256 | cut -d' ' -f1)"
    if [[ -n "${adapted:-}" ]]; then
        recorded="$here/${adapted#adapted:}"
        if [[ "$at_head" == "$sha" ]] && cmp -s <(diff -U0 <(git -C "$root" show "d37f9a3:$original") <(sed '2d' "$here/$copy") | sed '1,2d') "$recorded"; then
            echo "OK   $copy = $original ($sha) + exactly ${adapted#adapted:}"
        else
            echo "FAIL $copy: not the original ($at_head, recorded $sha) plus exactly ${adapted#adapted:}"
            status=1
        fi
    elif [[ "$body" == "$sha" && "$at_head" == "$sha" ]]; then
        echo "OK   $copy = $original ($sha)"
    else
        echo "FAIL $copy: copy-without-line-2 $body, original $at_head, recorded $sha"
        status=1
    fi
done < "$here/PROVENANCE.txt"
exit "$status"
