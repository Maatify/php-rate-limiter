#!/usr/bin/env bash
# #181 S1 M-122: m122.php must fail on a changed hash and on a missing row (the inputs reach the comparison).
set -euo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
body="$here/../results/M-122/issue-122-body.md"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
sed 's/f69479abd38a6c597096e13d9cd9d84e029cab338a58f3486987769f05c0f605/f69479abd38a6c597096e13d9cd9d84e029cab338a58f3486987769f05c0f606/' "$body" > "$tmp/hash.md"
sed '/^| 7 |/d' "$body" > "$tmp/row.md"
fail=0
for case in hash row; do
    if php "$here/m122.php" "$tmp/$case.md" > /dev/null; then echo "FAIL $case: accepted"; fail=1; else echo "ok $case: rejected"; fi
done
exit "$fail"
