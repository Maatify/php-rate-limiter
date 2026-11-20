#!/usr/bin/env bash
# Shared by run.sh and selftest.sh (#263 study); sourced, not executed. Adapted from the #261 study runner (F269-05/07): no Redis, no network.
# Nothing is created or emptied until EVERY check has passed; a run never exits 0 with an incomplete record.

RL263_ROOT=$(pwd -P)   # the physical clone root: every check below is against resolved paths, never the lexical ones
RL263_STUDY=investigations/dto-logging-263
RL263_RUN_SECONDS=${RL263_RUN_SECONDS:-600}

rl263_die() { echo "rl263: $*" >&2; exit "${2:-70}"; }

rl263_select_image() {  # $1 = 64|32
  case "$1" in
    64) RL263_IMG=maatify-rl-php64:local; RL263_PLAT=linux/arm64 ;;
    32) RL263_IMG=maatify-rl-php32:local; RL263_PLAT=linux/386 ;;
    *) rl263_die "the first argument must be 64 or 32" 64 ;;
  esac
}

# Validates the destination WITHOUT creating or opening anything: relative (or absolute inside the repository, converted), no '..', and no
# EXISTING component may be a symbolic link. Then the directory is created and its resolved path must lie inside the physical clone.
rl263_out_dir() {  # $1 = requested directory
  local out=$1
  [ -n "$out" ] || rl263_die "an output directory is required" 64
  case "$out" in
    /*) case "$out" in "$RL263_ROOT"/*) out=${out#"$RL263_ROOT"/} ;; *) rl263_die "the output directory must be inside the repository ($RL263_ROOT) or relative; got $out" 74 ;; esac ;;
  esac
  case "/$out/" in */../*) rl263_die "the output directory must not contain '..': $out" 74 ;; esac
  local walk="" segment
  local IFS=/
  for segment in $out; do
    [ -z "$segment" ] && continue
    walk=${walk:+$walk/}$segment
    [ -L "$RL263_ROOT/$walk" ] && rl263_die "the output directory passes through a symbolic link ($walk): refused, nothing was written" 74
  done
  unset IFS
  RL263_OUT=$out
  mkdir -p "$RL263_OUT" || rl263_die "could not create $RL263_OUT" 74
  local physical
  physical=$(cd "$RL263_ROOT/$RL263_OUT" 2>/dev/null && pwd -P) || rl263_die "cannot resolve $RL263_OUT" 74
  case "$physical" in "$RL263_ROOT"/*) ;; *) rl263_die "the output directory resolves outside the clone ($physical): refused" 74 ;; esac
  [ -w "$RL263_OUT" ] || rl263_die "the output directory $RL263_OUT is not writable" 75
}

# Phase 1 checks every artifact without opening any for writing (not a symbolic link, a regular file or absent, writable); phase 2 empties them.
# A refusal in phase 1 therefore never leaves an earlier artifact already emptied.
rl263_artifacts() {
  local f p
  for f in "$@"; do
    p="$RL263_OUT/$f"
    if [ -L "$p" ]; then rl263_die "the artifact $p is a symbolic link: refused, nothing was changed" 75; fi
    if [ -e "$p" ]; then
      [ -f "$p" ] || rl263_die "the artifact $p is not a regular file: refused, nothing was changed" 75
      [ -w "$p" ] || rl263_die "the artifact $p is not writable: refused, nothing was changed" 75
    fi
  done
  for f in "$@"; do
    : > "$RL263_OUT/$f" || rl263_die "cannot write the required artifact $RL263_OUT/$f" 75
  done
}

# The source identity is part of the evidence: established (and checked) BEFORE any write; if git fails the run does not start (77).
rl263_source_identity() {
  RL263_HEAD=$(git rev-parse HEAD 2>/dev/null) || rl263_die "git cannot resolve HEAD: the source identity cannot be recorded" 77
  RL263_TREE=$(git rev-parse 'HEAD^{tree}' 2>/dev/null) || rl263_die "git cannot resolve the source tree" 77
  local porcelain
  # Everything except this study's own evidence directories (raw/ and out/), which hold the run's products.
  porcelain=$(git status --porcelain -- . ":!$RL263_STUDY/raw" ":!$RL263_STUDY/out" 2>/dev/null) || rl263_die "git status failed: the worktree state cannot be verified" 77
  if [ -n "$porcelain" ]; then RL263_DIRTY=yes; else RL263_DIRTY=no; fi
}

rl263_meta() {  # $1 = file, $2 = phpunit code, $3 = evidence lines, $4 = final code, rest = command line
  local f=$1 rc=$2 lines=$3 final=$4; shift 4
  {
    echo "source_head=$RL263_HEAD"
    echo "source_tree=$RL263_TREE"
    echo "worktree_dirty_outside_study_evidence=$RL263_DIRTY"
    echo "php_image=$RL263_IMG ($RL263_PLAT)"
    echo "started_at_utc=${RL263_STARTED:-unknown}"
    echo "command=$*"
    echo "phpunit_exit=$rc"
    echo "evidence_lines=$lines"
    echo "final_exit=$final"
  } > "$f" || return 1
}

# The tool's own code is kept when it failed; otherwise the first bookkeeping failure (log writer, meta writer, evidence count) decides.
rl263_final_code() {  # $1 = tool code, $2 = tee code, $3 = meta code, $4 = extra code
  if [ "$1" != 0 ]; then echo "$1"; elif [ "$2" != 0 ]; then echo 75; elif [ "$3" != 0 ]; then echo 75; else echo "${4:-0}"; fi
}
