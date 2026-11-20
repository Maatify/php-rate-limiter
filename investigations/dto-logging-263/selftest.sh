#!/usr/bin/env bash
# Controls of the #263 runner itself (F272-01): a refusal never changes existing evidence, a failing git or artifact never returns success,
# and the normal run produces exactly 13 records on both widths. usage: selftest.sh (one line per control; non-zero if any control fails)
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
S=investigations/dto-logging-263
fail=0
check() { if [ "$3" = "$2" ]; then echo "ok   $1 (exit $3)"; else echo "FAIL $1: expected exit $2, got $3"; fail=1; fi; }
nonzero() { if [ "$2" != 0 ]; then echo "ok   $1 (exit $2, non-zero)"; else echo "FAIL $1: returned 0 although the record is incomplete"; fail=1; fi; }
same() { if [ "$(cat "$2" 2>/dev/null)" = "$3" ]; then echo "ok   $1: existing bytes preserved"; else echo "FAIL $1: existing evidence changed"; fail=1; fi; }
base=$S/out/selftest-$$
mkdir -p "$base" || exit 70
MARK="precious evidence bytes"

# 1. Arguments.
$S/run.sh 48 "$base/x" >/dev/null 2>&1; check "wrong width" 64 $?
$S/run.sh 64 >/dev/null 2>&1; check "missing output directory" 64 $?
# 2. Output destinations: refused BEFORE anything is created or emptied.
outside=$(mktemp -d); printf '%s' "$MARK" > "$outside/evidence.jsonl"
$S/run.sh 64 "$(mktemp -d)" >/dev/null 2>&1; check "absolute directory outside the repository" 74 $?
$S/run.sh 64 "$base/../escape" >/dev/null 2>&1; check "'..' in the directory" 74 $?
ln -s "$outside" "$base/link"
$S/run.sh 64 "$base/link/run" >/dev/null 2>&1; check "directory through a symbolic link" 74 $?
same "symbolic-link directory" "$outside/evidence.jsonl" "$MARK"
$S/run.sh 64 "$base/link" >/dev/null 2>&1; check "directory that is itself a symbolic link" 74 $?
same "symbolic-link directory (itself)" "$outside/evidence.jsonl" "$MARK"
# 3. Artifacts: a refusal on a LATER artifact must not empty an EARLIER one (Lead's F272-01 reproduction).
d=$base/artifacts; mkdir -p "$d"; printf '%s' "$MARK" > "$d/evidence.jsonl"
ln -s "$outside/evidence.jsonl" "$d/phpunit.log"
$S/run.sh 64 "$d" >/dev/null 2>&1; check "symbolic-link phpunit.log" 75 $?
same "later artifact refused" "$d/evidence.jsonl" "$MARK"
same "link target of the refused artifact" "$outside/evidence.jsonl" "$MARK"
rm "$d/phpunit.log"; mkdir "$d/phpunit.log"
$S/run.sh 64 "$d" >/dev/null 2>&1; check "directory where phpunit.log must be" 75 $?
same "directory in place of a later artifact" "$d/evidence.jsonl" "$MARK"
rmdir "$d/phpunit.log"; mkdir "$d/run-meta.txt"
$S/run.sh 64 "$d" >/dev/null 2>&1; check "directory where run-meta.txt must be" 75 $?
same "directory in place of the last artifact" "$d/evidence.jsonl" "$MARK"
# 4. Source identity: git failing (on status only, and on everything) is refused before any write, and nothing existing changes.
shim=$(mktemp -d); real=$(command -v git); g=$base/git; mkdir -p "$g"; printf '%s' "$MARK" > "$g/evidence.jsonl"
printf '#!/bin/sh\n[ "$1" = status ] && exit 3\nexec %s "$@"\n' "$real" > "$shim/git"; chmod +x "$shim/git"
PATH="$shim:$PATH" $S/run.sh 64 "$g" >/dev/null 2>&1; check "git status fails" 77 $?
same "git status fails" "$g/evidence.jsonl" "$MARK"
printf '#!/bin/sh\nexit 4\n' > "$shim/git"
PATH="$shim:$PATH" $S/run.sh 64 "$g" >/dev/null 2>&1; check "git cannot identify the source" 77 $?
same "git fails entirely" "$g/evidence.jsonl" "$MARK"
rm -rf "$shim"
# 5. Evidence count: the record count is part of success.
EXPECTED_SCENARIOS=14 $S/run.sh 64 "$base/count" >/dev/null 2>&1; check "wrong number of evidence records" 76 $?
# 6. Normal runs: success, exactly 13 records, the record states exit 0 (both widths).
for w in 64 32; do
  $S/run.sh $w "$base/normal-$w" >/dev/null 2>&1; check "normal run on $w" 0 $?
  n=$(wc -l < "$base/normal-$w/evidence.jsonl" 2>/dev/null | tr -d ' ')
  [ "$n" = 13 ] && echo "ok   $w: 13 evidence records" || { echo "FAIL $w: $n evidence records"; fail=1; }
  grep -qx 'final_exit=0' "$base/normal-$w/run-meta.txt" 2>/dev/null && echo "ok   $w: run-meta.txt records final_exit=0" || { echo "FAIL $w: run-meta.txt"; fail=1; }
  grep -q 'INT='"$([ "$w" = 64 ] && echo 8 || echo 4)" "$base/normal-$w/phpunit.log" 2>/dev/null && echo "ok   $w: PHP_INT_SIZE as expected" || { echo "FAIL $w: PHP_INT_SIZE"; fail=1; }
done
rm -rf "$base" "$outside"
[ "$fail" = 0 ] && echo "selftest: all controls passed" || echo "selftest: FAILED"
exit "$fail"
