#!/usr/bin/env bash
# Shared by run.sh, bench.sh and selftest.sh (#261 study). Sourced, not executed.
# Every setup step is checked and bounded; resources this script created (and only those) are removed on every exit path.

RL261_REDIS_IMAGE=${RL261_REDIS_IMAGE:-redis:7.0.15-alpine}
RL261_READY_SECONDS=${RL261_READY_SECONDS:-30}      # how long Redis may take to answer PING
RL261_RUN_SECONDS=${RL261_RUN_SECONDS:-900}         # hard limit of the PHP command inside the container
RL261_ROOT=$(pwd -P)   # the physical clone root: every check below is against resolved paths, never the lexical ones
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
  # A script that owns more than Redis (the base source tree of bench-impl.sh) defines this hook; it runs on every exit path.
  if [ "$(type -t rl261_extra_cleanup)" = function ]; then rl261_extra_cleanup; fi
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

# Where a run writes: never the committed raw/ evidence by default. A relative directory is taken as is; an absolute one is accepted only
# inside the repository (it is converted to the repository-relative path the container sees); anything else, or a `..` segment, is refused
# before any run (exit 74). Every artifact the run must leave is created and checked writable BEFORE the run, so a failure cannot be hidden.
rl261_out_dir() {
  local out=${RL261_OUT:-$RL261_STUDY/out/$(date -u +%Y%m%dT%H%M%SZ)-$$}
  case "$out" in
    /*) case "$out" in "$RL261_ROOT"/*) out=${out#"$RL261_ROOT"/} ;; *) rl261_die "RL261_OUT must be inside the repository ($RL261_ROOT) or relative; got $out" 74 ;; esac ;;
  esac
  case "/$out/" in */../*) rl261_die "RL261_OUT must not contain '..': $out" 74 ;; esac
  # No component of the path may be a symbolic link (F269-07): it would let a write land outside the clone. Checked BEFORE anything is created
  # or written, component by component, so not even a directory is made through a link.
  local walk="" segment
  local IFS=/
  for segment in $out; do
    [ -z "$segment" ] && continue
    walk=${walk:+$walk/}$segment
    [ -L "$RL261_ROOT/$walk" ] && rl261_die "RL261_OUT passes through a symbolic link ($walk): refused, nothing was written" 74
  done
  unset IFS
  RL261_OUT=$out
  mkdir -p "$RL261_OUT" || rl261_die "could not create $RL261_OUT" 74
  # And the resolved destination must really be inside the physical clone (covers a link created between the check and mkdir, and mounts).
  local physical
  physical=$(cd "$RL261_ROOT/$RL261_OUT" 2>/dev/null && pwd -P) || rl261_die "cannot resolve $RL261_OUT" 74
  case "$physical" in "$RL261_ROOT"/*) ;; *) rl261_die "RL261_OUT resolves outside the clone ($physical): refused" 74 ;; esac
}

# Two phases (F269-07): first EVERY required artifact is checked without opening any of them for writing (not a symbolic link, a regular file or
# absent, writable), so a refusal can never leave an earlier artifact already emptied; only then are they created/emptied.
rl261_artifacts() {
  local f p
  for f in "$@"; do
    p="$RL261_OUT/$f"
    if [ -L "$p" ]; then rl261_die "the artifact $p is a symbolic link: refused, nothing was changed" 75; fi
    if [ -e "$p" ]; then
      [ -f "$p" ] || rl261_die "the artifact $p is not a regular file: refused, nothing was changed" 75
      [ -w "$p" ] || rl261_die "the artifact $p is not writable: refused, nothing was changed" 75
    fi
  done
  [ -w "$RL261_OUT" ] || rl261_die "the output directory $RL261_OUT is not writable" 75
  for f in "$@"; do
    : > "$RL261_OUT/$f" || rl261_die "cannot write the required artifact $RL261_OUT/$f" 75
  done
}

# The source identity is part of the evidence: if it cannot be established, the run does not start (exit 77), and it is never defaulted.
rl261_source_identity() {
  RL261_HEAD=$(git rev-parse HEAD 2>/dev/null) || rl261_die "git cannot resolve HEAD: the source identity cannot be recorded" 77
  RL261_TREE=$(git rev-parse 'HEAD^{tree}' 2>/dev/null) || rl261_die "git cannot resolve the source tree" 77
  local porcelain
  porcelain=$(git status --porcelain 2>/dev/null) || rl261_die "git status failed: the worktree state cannot be verified" 77
  if [ -n "$porcelain" ]; then RL261_DIRTY=yes; else RL261_DIRTY=no; fi
}

# Writes the record; returns non-zero if it cannot be written completely.
rl261_meta() {  # $1 = file, $2 = exit code, rest = command line
  local f=$1 rc=$2; shift 2
  {
    echo "source_head=$RL261_HEAD"
    echo "source_tree=$RL261_TREE"
    echo "worktree_dirty=$RL261_DIRTY"
    echo "php_image=$RL261_IMG ($RL261_PLAT)"
    echo "redis_image=$RL261_REDIS_IMAGE"
    echo "started_at_utc=${RL261_STARTED:-unknown}"
    echo "command=$*"
    echo "exit_code=$rc"
  } > "$f" || return 1
}

# Combines the tool's own exit code with the failures of the run's bookkeeping: the tool's code is kept when it failed, else the first bookkeeping failure.
rl261_final_code() {  # $1 = tool code, $2 = log-writer (tee) code, $3 = meta-writer code, $4 = extra code
  if [ "$1" != 0 ]; then echo "$1"; elif [ "$2" != 0 ]; then echo 75; elif [ "$3" != 0 ]; then echo 75; else echo "${4:-0}"; fi
}
