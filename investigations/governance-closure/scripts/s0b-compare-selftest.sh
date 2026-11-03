#!/usr/bin/env bash
# #181 S1 M-S0b: s0b-compare.php must fail on a changed `out` row and a changed `pre` row, pass on a changed `pre+out`
# row (outside the prediction) and on a changed provenance row, and refuse a deleted row (the inputs reach the comparison).
#   s0b-compare-selftest.sh <main-dir>
set -euo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
main="${1:?usage: s0b-compare-selftest.sh <main-dir>}"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
fail=0
check() { # case want-exit
    set +e; php "$here/s0b-compare.php" "$main" "$tmp/$1" > /dev/null 2>&1; got=$?; set -e
    if [[ "$got" == "$2" ]]; then echo "ok $1: exit $got"; else echo "FAIL $1: exit $got, expected $2"; fail=1; fi
}
# edit <case> <file> <php condition on $r> <field>: change <field> (int + 1, string + "-X") in the first matching row
edit() {
    mkdir -p "$tmp/$1"; cp "$main"/t01_matrix.jsonl "$main"/t02_trace.jsonl "$tmp/$1/"
    php -r '$l = file($argv[1]); foreach ($l as $i => $x) { $r = json_decode($x, true); if (eval("return " . $argv[3] . ";")) { $r[$argv[4]] = is_int($r[$argv[4]]) ? $r[$argv[4]] + 1 : $r[$argv[4]] . "-X"; $l[$i] = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"; break; } } file_put_contents($argv[2], implode("", $l));' \
        "$main/$2" "$tmp/$1/$2" "$3" "$4"
    cmp -s "$main/$2" "$tmp/$1/$2" && { echo "FAIL $1: edit did not change $2"; fail=1; } || true
}
edit out_row t01_matrix.jsonl '($r["flow"] ?? null) === "out"' attackAttempts
edit pre_row t02_trace.jsonl '($r["flow"] ?? null) === "pre"' requests
edit pre_out_row t01_matrix.jsonl '($r["flow"] ?? null) === "pre+out"' attackAttempts
edit provenance_row t01_matrix.jsonl 'str_ends_with($r["s"], ".provenance")' baseSha
mkdir -p "$tmp/deleted_row"; cp "$main"/t01_matrix.jsonl "$main"/t02_trace.jsonl "$tmp/deleted_row/"
sed -i.bak '5d' "$tmp/deleted_row/t01_matrix.jsonl"
check out_row 1
check pre_row 1
check pre_out_row 0
check provenance_row 0
check deleted_row 3
exit "$fail"
