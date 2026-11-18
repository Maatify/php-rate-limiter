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
RL261_OUT=$(mktemp -d) RL261_REDIS_IMAGE=rl261-no-such-image:0 investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
check "missing Redis image" 72 $?
# 2. The container runs but Redis never answers PING within the (shortened) bound.
start=$(date +%s)
RL261_OUT=$(mktemp -d) RL261_READY_SECONDS=2 RL261_REDIS_IMAGE=alpine:3 RL261_REDIS_CMD="sleep 300" investigations/redis-acl-request-save-261/run.sh 64 --version >/dev/null 2>&1
rc=$?
check "Redis never ready" 73 "$rc"
elapsed=$(( $(date +%s) - start ))
[ "$elapsed" -lt 60 ] && echo "ok   bounded: $elapsed s" || { echo "FAIL not bounded: $elapsed s"; fail=1; }
# 3. A wrong width argument.
RL261_OUT=$(mktemp -d) investigations/redis-acl-request-save-261/run.sh 48 --version >/dev/null 2>&1
check "wrong width" 64 $?
after=$(docker ps -a --format '{{.Names}}' | grep -c '^rl261-'; docker network ls --format '{{.Name}}' | grep -c '^rl261-')
[ "$before" = "$after" ] && echo "ok   no rl261-* container or network left behind" || { echo "FAIL leftovers: before=[$before] after=[$after]"; fail=1; }
exit $fail
