#!/usr/bin/env bash
# Shared by run.sh, bench.sh and selftest.sh (#261 study). Sourced, not executed.
# Every setup step is checked and bounded; resources this script created (and only those) are removed on every exit path.

RL261_REDIS_IMAGE=${RL261_REDIS_IMAGE:-redis:7.0.15-alpine}
RL261_READY_SECONDS=${RL261_READY_SECONDS:-30}      # how long Redis may take to answer PING
RL261_RUN_SECONDS=${RL261_RUN_SECONDS:-900}         # hard limit of the PHP command inside the container
RL261_ROOT=$(pwd)
RL261_STUDY=investigations/redis-acl-request-save-261

rl261_die() { echo "rl261: $*" >&2; exit "${2:-70}"; }

rl261_select_image() {  # $1 = 64|32
  case "$1" in
    64) RL261_IMG=maatify-rl-php64:local; RL261_PLAT=linux/arm64 ;;
    32) RL261_IMG=maatify-rl-php32:local; RL261_PLAT=linux/386 ;;
    *) rl261_die "the first argument must be 64 or 32" 64 ;;
  esac
}

rl261_cleanup() {
  [ -n "${RL261_REDIS:-}" ] && docker rm -f "$RL261_REDIS" >/dev/null 2>&1
  [ -n "${RL261_NET:-}" ] && docker network rm "$RL261_NET" >/dev/null 2>&1
  return 0
}

# Creates the private network and the isolated Redis; fails visibly (non-zero, message) if either step or the readiness wait fails.
rl261_start_redis() {
  local sfx="$$-$RANDOM"
  RL261_NET=rl261-$sfx
  RL261_REDIS=rl261-redis-$sfx
  trap rl261_cleanup EXIT
  trap 'exit 130' INT TERM
  docker network create "$RL261_NET" >/dev/null || rl261_die "could not create the network $RL261_NET" 71
  if [ -n "${RL261_REDIS_CMD:-}" ]; then   # only the self-test overrides the command (a server that never becomes ready)
    # shellcheck disable=SC2086
    docker run -d --name "$RL261_REDIS" --network "$RL261_NET" "$RL261_REDIS_IMAGE" $RL261_REDIS_CMD >/dev/null || rl261_die "could not start the container from $RL261_REDIS_IMAGE" 72
  else
    docker run -d --name "$RL261_REDIS" --network "$RL261_NET" "$RL261_REDIS_IMAGE" redis-server --save "" --appendonly no >/dev/null \
      || rl261_die "could not start Redis from $RL261_REDIS_IMAGE" 72
  fi
  local waited=0
  until [ "$(docker exec "$RL261_REDIS" redis-cli ping 2>/dev/null)" = PONG ]; do
    waited=$((waited + 1))
    [ "$waited" -gt $((RL261_READY_SECONDS * 3)) ] && rl261_die "Redis did not answer PING within ${RL261_READY_SECONDS}s" 73
    sleep 0.33
  done
}

# Where a run writes: never the committed raw/ evidence by default.
rl261_out_dir() {
  RL261_OUT=${RL261_OUT:-$RL261_STUDY/out/$(date -u +%Y%m%dT%H%M%SZ)-$$}
  mkdir -p "$RL261_OUT" || rl261_die "could not create $RL261_OUT" 74
}

rl261_meta() {  # $1 = file, $2 = exit code, rest = command line
  local f=$1 rc=$2; shift 2
  {
    echo "source_head=$(git rev-parse HEAD 2>/dev/null || echo unknown)"
    echo "source_tree=$(git rev-parse 'HEAD^{tree}' 2>/dev/null || echo unknown)"
    echo "worktree_dirty=$([ -n "$(git status --porcelain 2>/dev/null)" ] && echo yes || echo no)"
    echo "php_image=$RL261_IMG ($RL261_PLAT)"
    echo "redis_image=$RL261_REDIS_IMAGE"
    echo "started_at_utc=${RL261_STARTED:-unknown}"
    echo "command=$*"
    echo "exit_code=$rc"
  } > "$f"
}
