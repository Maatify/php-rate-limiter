#!/usr/bin/env bash
# #160 provenance check (PLAN.md §12.2 LT-160-08, §13.2 BC-5): the original at
# 8b79e9b must hash to the recorded SHA-256, and every copy listed in
# PROVENANCE.txt, without its line 2, must equal it byte for byte; an entry
# marked adapted:<diff> must instead differ from it by exactly that recorded
# diff. Prints one line per file; exit 1 on any mismatch.
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
status=0
while read -r copy original sha adapted; do
    [[ -z "$copy" || "$copy" == \#* ]] && continue
    body="$(sed '2d' "$here/$copy" | shasum -a 256 | cut -d' ' -f1)"
    at_head="$(git -C "$root" show "8b79e9b:$original" | shasum -a 256 | cut -d' ' -f1)"
    if [[ -n "${adapted:-}" ]]; then
        recorded="$here/${adapted#adapted:}"
        if [[ "$at_head" == "$sha" ]] && cmp -s <(diff <(git -C "$root" show "8b79e9b:$original") <(sed '2d' "$here/$copy")) "$recorded"; then
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
