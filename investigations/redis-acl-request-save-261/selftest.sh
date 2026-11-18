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
after=$(docker ps -a --format '{{.Names}}' | grep -c '^rl261-'; docker network ls --format '{{.Name}}' | grep -c '^rl261-')
[ "$before" = "$after" ] && echo "ok   no rl261-* container or network left behind" || { echo "FAIL leftovers: before=[$before] after=[$after]"; fail=1; }
exit $fail
