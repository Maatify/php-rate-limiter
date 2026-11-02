#!/usr/bin/env bash
# #181 S1: compare-provenance.php must reject a changed decision row and a changed non-SHA provenance field, and accept a
# changed SHA field only (the inputs reach the comparison).
#   compare-selftest.sh <expected-dir> <file.jsonl>
set -euo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
expected="${1:?usage: compare-selftest.sh <expected-dir> <file.jsonl>}"
file="${2:?usage: compare-selftest.sh <expected-dir> <file.jsonl>}"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
fail=0
check() { # case want
    if php "$here/compare-provenance.php" "$expected" "$tmp/$1" > /dev/null; then got=accepted; else got=rejected; fi
    if [[ "$got" == "$2" ]]; then echo "ok $1: $got"; else echo "FAIL $1: $got, expected $2"; fail=1; fi
}
for c in decision provenance_field sha_only; do mkdir -p "$tmp/$c"; cp "$expected"/*.jsonl "$expected"/*.md "$tmp/$c/"; done
# swap the 2nd and 3rd decision rows (lines 3 and 4; line 1 is provenance)
awk 'NR==3{h=$0; next} NR==4{print; print h; next} {print}' "$expected/$file" > "$tmp/decision/$file"
php -r '$l = file($argv[1]); $r = json_decode($l[0], true); $r["level"] .= "-X"; $l[0] = json_encode($r, JSON_UNESCAPED_SLASHES) . "\n"; file_put_contents($argv[2], implode("", $l));' "$expected/$file" "$tmp/provenance_field/$file"
php -r '$l = file($argv[1]); $r = json_decode($l[0], true); $r["baseSha"] = str_repeat("0", 40); $l[0] = json_encode($r, JSON_UNESCAPED_SLASHES) . "\n"; file_put_contents($argv[2], implode("", $l));' "$expected/$file" "$tmp/sha_only/$file"
check decision rejected
check provenance_field rejected
check sha_only accepted
exit "$fail"
