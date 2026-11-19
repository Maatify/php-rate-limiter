#!/usr/bin/env bash
# Shared by run.sh and selftest.sh (#262 study); sourced, not executed. Adapted from the #261 study runner (F269-05/07): no Redis, no network.
# Nothing is created or emptied until EVERY check has passed; a run never exits 0 with an incomplete record.

RL262_ROOT=$(pwd -P)   # the physical clone root: every check below is against resolved paths, never the lexical ones
RL262_STUDY=investigations/fallback-env-scope-262
RL262_RUN_SECONDS=${RL262_RUN_SECONDS:-600}

rl262_die() { echo "rl262: $*" >&2; exit "${2:-70}"; }

rl262_select_image() {  # $1 = 64|32
  case "$1" in
    64) RL262_IMG=maatify-rl-php64:local; RL262_PLAT=linux/arm64 ;;
    32) RL262_IMG=maatify-rl-php32:local; RL262_PLAT=linux/386 ;;
    *) rl262_die "the first argument must be 64 or 32" 64 ;;
  esac
}

# Validates the destination WITHOUT creating or opening anything: relative (or absolute inside the repository, converted), no '..', and no
# EXISTING component may be a symbolic link. Then the directory is created and its resolved path must lie inside the physical clone.
rl262_out_dir() {  # $1 = requested directory
  local out=$1
  [ -n "$out" ] || rl262_die "an output directory is required" 64
  case "$out" in
    /*) case "$out" in "$RL262_ROOT"/*) out=${out#"$RL262_ROOT"/} ;; *) rl262_die "the output directory must be inside the repository ($RL262_ROOT) or relative; got $out" 74 ;; esac ;;
  esac
  case "/$out/" in */../*) rl262_die "the output directory must not contain '..': $out" 74 ;; esac
  local walk="" segment
  local IFS=/
  for segment in $out; do
    [ -z "$segment" ] && continue
    walk=${walk:+$walk/}$segment
    [ -L "$RL262_ROOT/$walk" ] && rl262_die "the output directory passes through a symbolic link ($walk): refused, nothing was written" 74
  done
  unset IFS
  RL262_OUT=$out
  mkdir -p "$RL262_OUT" || rl262_die "could not create $RL262_OUT" 74
  local physical
  physical=$(cd "$RL262_ROOT/$RL262_OUT" 2>/dev/null && pwd -P) || rl262_die "cannot resolve $RL262_OUT" 74
  case "$physical" in "$RL262_ROOT"/*) ;; *) rl262_die "the output directory resolves outside the clone ($physical): refused" 74 ;; esac
  [ -w "$RL262_OUT" ] || rl262_die "the output directory $RL262_OUT is not writable" 75
}

# Phase 1 checks every artifact without opening any for writing (not a symbolic link, a regular file or absent, writable); phase 2 empties them.
# A refusal in phase 1 therefore never leaves an earlier artifact already emptied.
rl262_artifacts() {
  local f p
  for f in "$@"; do
    p="$RL262_OUT/$f"
    if [ -L "$p" ]; then rl262_die "the artifact $p is a symbolic link: refused, nothing was changed" 75; fi
    if [ -e "$p" ]; then
      [ -f "$p" ] || rl262_die "the artifact $p is not a regular file: refused, nothing was changed" 75
      [ -w "$p" ] || rl262_die "the artifact $p is not writable: refused, nothing was changed" 75
    fi
  done
  for f in "$@"; do
    : > "$RL262_OUT/$f" || rl262_die "cannot write the required artifact $RL262_OUT/$f" 75
  done
}

# The source identity is part of the evidence: established (and checked) BEFORE any write; if git fails the run does not start (77).
rl262_source_identity() {
  RL262_HEAD=$(git rev-parse HEAD 2>/dev/null) || rl262_die "git cannot resolve HEAD: the source identity cannot be recorded" 77
  RL262_TREE=$(git rev-parse 'HEAD^{tree}' 2>/dev/null) || rl262_die "git cannot resolve the source tree" 77
  local porcelain
  # Everything except this study's own evidence directories (raw/ and out/), which hold the run's products.
  porcelain=$(git status --porcelain -- . ":!$RL262_STUDY/raw" ":!$RL262_STUDY/out" 2>/dev/null) || rl262_die "git status failed: the worktree state cannot be verified" 77
  if [ -n "$porcelain" ]; then RL262_DIRTY=yes; else RL262_DIRTY=no; fi
}

rl262_meta() {  # $1 = file, $2 = phpunit code, $3 = evidence lines, $4 = final code, rest = command line
  local f=$1 rc=$2 lines=$3 final=$4; shift 4
  {
    echo "source_head=$RL262_HEAD"
    echo "source_tree=$RL262_TREE"
    echo "worktree_dirty_outside_study_evidence=$RL262_DIRTY"
    echo "php_image=$RL262_IMG ($RL262_PLAT)"
    echo "started_at_utc=${RL262_STARTED:-unknown}"
    echo "command=$*"
    echo "phpunit_exit=$rc"
    echo "evidence_lines=$lines"
    echo "final_exit=$final"
  } > "$f" || return 1
}

# The tool's own code is kept when it failed; otherwise the first bookkeeping failure (log writer, meta writer, evidence count) decides.
rl262_final_code() {  # $1 = tool code, $2 = tee code, $3 = meta code, $4 = extra code
  if [ "$1" != 0 ]; then echo "$1"; elif [ "$2" != 0 ]; then echo 75; elif [ "$3" != 0 ]; then echo 75; else echo "${4:-0}"; fi
}
