#!/usr/bin/env bash
# Control of the runner itself: a failing setup must fail visibly, quickly, with a non-zero code and leave none of its own resources behind.
# usage: selftest.sh   (prints one line per control and exits non-zero if any control fails)
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
fail=0
before=$(docker ps -a --format '{{.Names}}' | grep -c '^rl261-'; docker network ls --format '{{.Name}}' | grep -c '^rl261-')
check() { # name expected-code actual-code
  if [ "$3" = "$2" ]; then echo "ok   $1 (exit $3)"; else echo "FAIL $1: expected exit $2, got $3"; fail=1; fi
}
# 1. Redis cannot start: a missing image.
RL261_OUT=investigations/redis-acl-request-save-261/out/selftest-image-$$ RL261_REDIS_IMAGE=rl261-no-such-image:0 investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
check "missing Redis image" 72 $?
# 2. The container runs but Redis never answers PING within the (shortened) bound.
start=$(date +%s)
RL261_OUT=investigations/redis-acl-request-save-261/out/selftest-ready-$$ RL261_READY_SECONDS=2 RL261_REDIS_IMAGE=alpine:3 RL261_REDIS_CMD="sleep 300" investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
rc=$?
check "Redis never ready" 73 "$rc"
elapsed=$(( $(date +%s) - start ))
[ "$elapsed" -lt 60 ] && echo "ok   bounded: $elapsed s" || { echo "FAIL not bounded: $elapsed s"; fail=1; }
# 3. A wrong width argument.
RL261_OUT=investigations/redis-acl-request-save-261/out/selftest-width-$$ investigations/redis-acl-request-save-261/run.sh 48 --version >/dev/null 2>&1
check "wrong width" 64 $?
# 4 (F269-05). A run whose artifacts cannot be written, or whose source identity cannot be verified, must not return success.
nonzero() { # name actual-code
  if [ "$2" != 0 ]; then echo "ok   $1 (exit $2, non-zero)"; else echo "FAIL $1: returned 0 although the run's record is incomplete"; fail=1; fi
}
# 4a. An absolute output directory under the repository: supported; the artifacts must exist, be non-empty and the run must succeed.
abs=$(pwd)/investigations/redis-acl-request-save-261/out/selftest-abs-$$
RL261_OUT=$abs investigations/redis-acl-request-save-261/run.sh 64 --do-not-cache-result --filter 'testCase.*resp control' tests/Integration/Redis/RequestSaveAclRedisTest.php >/dev/null 2>&1
rc=$?
check "absolute output directory inside the repository: run" 0 "$rc"
[ -s "$abs/evidence.jsonl" ] && echo "ok   evidence.jsonl is non-empty" || { echo "FAIL evidence.jsonl missing or empty in $abs"; fail=1; }
grep -qx 'exit_code=0' "$abs/run-meta.txt" 2>/dev/null && echo "ok   run-meta.txt records exit_code=0" || { echo "FAIL run-meta.txt missing or without exit_code=0"; fail=1; }
# 4b. An absolute output directory outside the repository: refused explicitly before any run.
RL261_OUT=$(mktemp -d) investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
check "absolute output directory outside the repository is refused" 74 $?
# 4c. A required artifact that cannot be written (a directory where the log file must be).
bad=investigations/redis-acl-request-save-261/out/selftest-unwritable-$$
mkdir -p "$bad/phpunit.log"
RL261_OUT=$bad investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "unwritable phpunit.log" $?
bad2=investigations/redis-acl-request-save-261/out/selftest-unwritable-meta-$$
mkdir -p "$bad2/run-meta.txt"
RL261_OUT=$bad2 investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "unwritable run-meta.txt" $?
bad3=investigations/redis-acl-request-save-261/out/selftest-unwritable-bench-$$
mkdir -p "$bad3/bench.log"
RL261_OUT=$bad3 investigations/redis-acl-request-save-261/bench.sh 64 5 >/dev/null 2>&1
nonzero "unwritable bench.log" $?
# 4d. The source identity cannot be verified: a git that fails on `status` (and one that fails on everything).
shim=$(mktemp -d)
real=$(command -v git)
printf '#!/bin/sh\n[ "$1" = status ] && exit 3\nexec %s "$@"\n' "$real" > "$shim/git"; chmod +x "$shim/git"
PATH="$shim:$PATH" RL261_OUT=investigations/redis-acl-request-save-261/out/selftest-git-status-$$ investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "git status fails" $?
printf '#!/bin/sh\nexit 4\n' > "$shim/git"
PATH="$shim:$PATH" RL261_OUT=investigations/redis-acl-request-save-261/out/selftest-git-all-$$ investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "git cannot identify the source" $?
rm -rf "$shim"
# 5 (F269-07). A symbolic link on the way to the output directory, or in place of an artifact, must never let the runner write outside the clone:
# the run is refused (non-zero) and an existing file outside the clone is not touched.
ext=$(mktemp -d)
reset_ext() { rm -rf "$ext"; mkdir -p "$ext"; for f in evidence.jsonl phpunit.log run-meta.txt bench.log; do echo "PRECIOUS-$f" > "$ext/$f"; done; }
sum_ext() { (cd "$ext" && cat evidence.jsonl phpunit.log run-meta.txt bench.log; ls -A | sort | tr '\n' ' ') | cksum; }
untouched() { # name checksum-before
  if [ "$(sum_ext)" = "$2" ]; then echo "ok   $1: the file outside the clone is untouched"; else echo "FAIL $1: a file outside the clone was changed or created"; fail=1; fi
}
out=investigations/redis-acl-request-save-261/out
mkdir -p "$out"
reset_ext; sumb=$(sum_ext)
ln -sfn "$ext" "$out/selftest-link-$$"
RL261_OUT=$out/selftest-link-$$ investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "run.sh: output directory is a symbolic link" $?; untouched "run.sh symlinked directory" "$sumb"
RL261_OUT=$out/selftest-link-$$ investigations/redis-acl-request-save-261/bench.sh 64 3 >/dev/null 2>&1
nonzero "bench.sh: output directory is a symbolic link" $?; untouched "bench.sh symlinked directory" "$sumb"
RL261_OUT=$(pwd)/$out/selftest-link-$$ investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "run.sh: absolute path through a symbolic link" $?; untouched "run.sh absolute symlinked path" "$sumb"
RL261_OUT=$out/selftest-link-$$/sub investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "run.sh: a parent component is a symbolic link" $?; untouched "run.sh symlinked parent (no directory created outside)" "$sumb"
# an artifact that is itself a symbolic link to an outside file
real=$out/selftest-linkfile-$$; mkdir -p "$real"; ln -sfn "$ext/evidence.jsonl" "$real/evidence.jsonl"
RL261_OUT=$real investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "run.sh: evidence.jsonl is a symbolic link" $?; untouched "run.sh symlinked artifact" "$sumb"
# 6 (F269-07, second part). An existing artifact must survive a refusal: every artifact is checked BEFORE any is opened for writing.
keep=$out/selftest-keep-$$; mkdir -p "$keep"
printf 'EXISTING-EVIDENCE-26-BYTES\n' > "$keep/evidence.jsonl"; ln -sfn "$ext/phpunit.log" "$keep/phpunit.log"
keepsum=$(cksum < "$keep/evidence.jsonl")
RL261_OUT=$keep investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "run.sh: phpunit.log is a symbolic link, evidence.jsonl already exists" $?
[ "$(cksum < "$keep/evidence.jsonl")" = "$keepsum" ] && echo "ok   the existing evidence.jsonl is unchanged after the refusal" || { echo "FAIL the existing evidence.jsonl was emptied or changed before the refusal"; fail=1; }
keep2=$out/selftest-keep2-$$; mkdir -p "$keep2/run-meta.txt"
printf 'EXISTING-EVIDENCE-26-BYTES\n' > "$keep2/evidence.jsonl"; printf 'EXISTING-LOG\n' > "$keep2/phpunit.log"
ks2=$(cat "$keep2/evidence.jsonl" "$keep2/phpunit.log" | cksum)
RL261_OUT=$keep2 investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
nonzero "run.sh: run-meta.txt is a directory, evidence.jsonl and phpunit.log already exist" $?
[ "$(cat "$keep2/evidence.jsonl" "$keep2/phpunit.log" | cksum)" = "$ks2" ] && echo "ok   the existing evidence.jsonl and phpunit.log are unchanged after the refusal" || { echo "FAIL existing artifacts were changed before the refusal"; fail=1; }
keep3=$out/selftest-keep3-$$; mkdir -p "$keep3"; printf 'EXISTING-BENCH\n' > "$keep3/bench.log"; ln -sfn "$ext/run-meta.txt" "$keep3/run-meta.txt"
ks3=$(cksum < "$keep3/bench.log")
RL261_OUT=$keep3 investigations/redis-acl-request-save-261/bench.sh 64 3 >/dev/null 2>&1
nonzero "bench.sh: run-meta.txt is a symbolic link, bench.log already exists" $?
[ "$(cksum < "$keep3/bench.log")" = "$ks3" ] && echo "ok   the existing bench.log is unchanged after the refusal" || { echo "FAIL the existing bench.log was emptied before the refusal"; fail=1; }
rm -f "$out/selftest-link-$$"; rm -rf "$ext"
# 7 (F271-02). The comparison bench never swaps the production file in the checkout: a setup failure leaves src/ byte-identical to HEAD and the
# temporary base tree removed; an unwritable record fails; and the two arms run from different source trees.
overlay=src/Repository/Redis/RedisRequestOverlay.php
headblob=$(git rev-parse "HEAD:$overlay"); workblob_before=$(git hash-object "$overlay")
RL261_OUT=investigations/redis-acl-request-save-261/out/selftest-bench-ready-$$ RL261_READY_SECONDS=2 RL261_REDIS_IMAGE=alpine:3 RL261_REDIS_CMD="sleep 300" investigations/redis-acl-request-save-261/bench-impl.sh 64 5 1 >/dev/null 2>&1
check "bench-impl.sh: Redis never ready" 73 $?
[ "$(git hash-object "$overlay")" = "$workblob_before" ] && echo "ok   the production overlay in the checkout is unchanged after the failed setup" || { echo "FAIL the production overlay was changed by a failed bench-impl.sh"; fail=1; }
[ "$(find investigations/redis-acl-request-save-261/out/selftest-bench-ready-$$ -maxdepth 1 -name 'base-tree*' | wc -l | tr -d ' ')" = 0 ] && echo "ok   the temporary base tree was removed" || { echo "FAIL the temporary base tree was left behind"; fail=1; }
badb=investigations/redis-acl-request-save-261/out/selftest-bench-log-$$; mkdir -p "$badb/bench-impl.log"
RL261_OUT=$badb investigations/redis-acl-request-save-261/bench-impl.sh 64 5 1 >/dev/null 2>&1
nonzero "bench-impl.sh: bench-impl.log is not writable" $?
[ "$(git hash-object "$overlay")" = "$workblob_before" ] && echo "ok   the production overlay is unchanged after the refused record" || { echo "FAIL overlay changed"; fail=1; }
goodb=investigations/redis-acl-request-save-261/out/selftest-bench-ok-$$
RL261_OUT=$goodb investigations/redis-acl-request-save-261/bench-impl.sh 64 5 1 >/dev/null 2>&1
check "bench-impl.sh: a short comparison run" 0 $?
grep -q '^arm base: .*blob ' "$goodb/bench-impl.log" && grep -q '^arm fix : .*blob ' "$goodb/bench-impl.log" && grep -q '^summary base:' "$goodb/bench-impl.log" && grep -q '^summary fix:' "$goodb/bench-impl.log" && echo "ok   the log names both arms' sources and carries both summaries" || { echo "FAIL the bench log lacks the arm sources or summaries"; fail=1; }
[ "$(git hash-object "$overlay")" = "$workblob_before" ] && [ "$workblob_before" = "$headblob" ] && echo "ok   the checkout's overlay equals HEAD after a full run" || { echo "FAIL overlay differs from HEAD after a full run"; fail=1; }
[ "$(find "$goodb" -maxdepth 1 -name 'base-tree*' | wc -l | tr -d ' ')" = 0 ] && echo "ok   the base tree is removed after a full run" || { echo "FAIL base tree left"; fail=1; }
# 8 (F271-02, ownership). The comparison bench removes only the temporary directory THIS run created: a `base-tree` that already exists in the output
# directory (with the production overlay name inside it, and a sentinel file) survives a failed setup and a full run, byte for byte.
pre=investigations/redis-acl-request-save-261/out/selftest-bench-owned-$$
mkdir -p "$pre/base-tree/src/Repository/Redis"
printf 'SENTINEL-BEFORE-RUN-28-BYTES\n' > "$pre/base-tree/sentinel.txt"
printf 'PRE-EXISTING-OVERLAY\n' > "$pre/base-tree/src/Repository/Redis/RedisRequestOverlay.php"
presum=$(cat "$pre/base-tree/sentinel.txt" "$pre/base-tree/src/Repository/Redis/RedisRequestOverlay.php" | cksum)
RL261_OUT=$pre RL261_READY_SECONDS=2 RL261_REDIS_IMAGE=alpine:3 RL261_REDIS_CMD="sleep 300" investigations/redis-acl-request-save-261/bench-impl.sh 64 5 1 >/dev/null 2>&1
check "bench-impl.sh with a pre-existing base-tree: Redis never ready" 73 $?
[ "$(cat "$pre/base-tree/sentinel.txt" "$pre/base-tree/src/Repository/Redis/RedisRequestOverlay.php" 2>/dev/null | cksum)" = "$presum" ] && echo "ok   the pre-existing base-tree and its contents survive the failed setup" || { echo "FAIL the pre-existing base-tree or its contents were removed or changed"; fail=1; }
left=$(find "$pre" -maxdepth 1 -name 'base-tree.*' | wc -l | tr -d ' ')
[ "$left" = 0 ] && echo "ok   no per-run temporary directory is left behind" || { echo "FAIL $left per-run temporary directories left"; fail=1; }
RL261_OUT=$pre investigations/redis-acl-request-save-261/bench-impl.sh 64 5 1 >/dev/null 2>&1
check "bench-impl.sh with a pre-existing base-tree: a short comparison run" 0 $?
[ "$(cat "$pre/base-tree/sentinel.txt" "$pre/base-tree/src/Repository/Redis/RedisRequestOverlay.php" 2>/dev/null | cksum)" = "$presum" ] && echo "ok   the pre-existing base-tree survives a full run" || { echo "FAIL the pre-existing base-tree was changed by a full run"; fail=1; }
left=$(find "$pre" -maxdepth 1 -name 'base-tree.*' | wc -l | tr -d ' ')
[ "$left" = 0 ] && echo "ok   no per-run temporary directory is left after a full run" || { echo "FAIL $left per-run temporary directories left after a full run"; fail=1; }
after=$(docker ps -a --format '{{.Names}}' | grep -c '^rl261-'; docker network ls --format '{{.Name}}' | grep -c '^rl261-')
[ "$before" = "$after" ] && echo "ok   no rl261-* container or network left behind" || { echo "FAIL leftovers: before=[$before] after=[$after]"; fail=1; }
exit $fail
