#!/usr/bin/env bash
# usage: bench-impl.sh <64|32> <requests> <rounds>   (run alone, never beside tests)
# Compares the request save (the commit EVAL) of the BASE overlay (main@3acf0258) with the FIX overlay (this checkout). F271-02: the two arms run
# from SEPARATE source trees: the fix arm mounts this checkout, which is never modified; the base arm mounts a temporary copy of the tree under
# $RL261_OUT in which only src/Repository/Redis/RedisRequestOverlay.php is the base blob. Nothing in the checkout is replaced, so no failure can leave
# the production file swapped. The copy is removed on every exit path. Output (bench-impl.log, run-meta.txt) obeys the run.sh exit-code contract.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
. investigations/redis-acl-request-save-261/common.sh
rl261_select_image "${1:-}"; n=${2:-300}; rounds=${3:-3}
BASE=3acf0258087a7bb89057dcf629f9bf8f5b38c246
F=src/Repository/Redis/RedisRequestOverlay.php
rl261_out_dir
rl261_source_identity
rl261_artifacts bench-impl.log run-meta.txt
RL261_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
# The base tree is a directory this run creates and owns: `mktemp -d` makes a fresh, unique one (a `base-tree` or anything else already present in the
# output directory is never reused, written to or removed), and the cleanup removes exactly that directory and nothing else, also when the copy fails.
TREE=""
rl261_extra_cleanup() { if [ -n "$TREE" ] && [ -d "$TREE" ]; then rm -rf "$TREE"; fi; }
trap rl261_cleanup EXIT
TREE=$(mktemp -d "$RL261_ROOT/$RL261_OUT/base-tree.XXXXXX") || rl261_die "cannot create a private base tree in $RL261_OUT" 75
rsync -a --exclude=.git --exclude="$RL261_STUDY/out" "$RL261_ROOT"/ "$TREE"/ || rl261_die "cannot copy the source tree for the base arm" 75
git show "$BASE:$F" > "$TREE/$F" || rl261_die "cannot read the base overlay from $BASE" 77
BASEBLOB=$(git rev-parse "$BASE:$F") || rl261_die "cannot resolve the base overlay blob" 77
FIXBLOB=$(git hash-object "$F") || rl261_die "cannot hash the fix overlay" 77
TREEBLOB=$(git hash-object "$TREE/$F") || rl261_die "cannot hash the base-tree overlay" 77
[ "$TREEBLOB" = "$BASEBLOB" ] || rl261_die "the base tree does not hold the base overlay" 77
[ "$FIXBLOB" != "$BASEBLOB" ] || rl261_die "the checkout's overlay equals the base overlay: nothing to compare" 77
{
  echo "arm base: source tree = private copy ($(basename "$TREE")) of this checkout with $F = blob $BASEBLOB (from $BASE)"
  echo "arm fix : source tree = this checkout at $RL261_HEAD (dirty=$RL261_DIRTY), $F = blob $FIXBLOB"
  echo "aggregation: one line per arm and round (p50, p95, mean of the commit EVAL latency over $n requests); the summary lines are the MEDIAN and the MEAN of the per-round p50 values"
} >> "$RL261_OUT/bench-impl.log" || rl261_die "cannot write bench-impl.log" 75
arm() { # label root
  rl261_start_redis
  docker run --rm --platform "$RL261_PLAT" --network "$RL261_NET" -v "$2:/app" -w /app "$RL261_IMG" \
    timeout "$RL261_RUN_SECONDS" php investigations/redis-acl-request-save-261/bench-impl.php "$RL261_REDIS" 6379 "$1" "$n" 2>&1 | tee -a "$RL261_OUT/bench-impl.log"
  local codes=("${PIPESTATUS[@]}")
  rl261_cleanup_redis_only
  [ "${codes[0]}" != 0 ] && return "${codes[0]}"
  [ "${codes[1]}" != 0 ] && return 75
  return 0
}
rl261_cleanup_redis_only() { docker rm -f "$RL261_REDIS" >/dev/null 2>&1; docker network rm "$RL261_NET" >/dev/null 2>&1; RL261_REDIS=""; RL261_NET=""; }
rc=0
for r in $(seq 1 "$rounds"); do
  arm "base-round$r" "$TREE" || { rc=$?; break; }
  arm "fix-round$r" "$RL261_ROOT" || { rc=$?; break; }
done
if [ "$rc" = 0 ]; then
  summary=$(awk '
    /^(base|fix)-round[0-9]+:/ { arm=$1; sub(/-round.*/,"",arm); for(i=1;i<=NF;i++) if($i ~ /^p50=/){v=$i; sub(/p50=/,"",v); a[arm]=a[arm] " " v} }
    function med(s,   n,i,j,t,x){ n=split(s,x," "); for(i=1;i<=n;i++)for(j=i+1;j<=n;j++) if(x[j]+0<x[i]+0){t=x[i];x[i]=x[j];x[j]=t}; return (n%2)?x[(n+1)/2]:(x[n/2]+x[n/2+1])/2 }
    function mean(s,   n,i,t,x){ n=split(s,x," "); for(i=1;i<=n;i++) t+=x[i]; return t/n }
    END { for (k in a) printf "summary %s: median of per-round p50 = %.0f us, mean of per-round p50 = %.0f us (%s)\n", k, med(a[k]), mean(a[k]), a[k] }' "$RL261_OUT/bench-impl.log" | sort)
  printf '%s\n' "$summary" | tee -a "$RL261_OUT/bench-impl.log" || rc=75
fi
rl261_meta "$RL261_OUT/run-meta.txt" "$rc" bench-impl.sh "$RL261_PLAT" "$n" "$rounds"; metarc=$?
final=$(rl261_final_code "$rc" 0 "$metarc" 0)
echo "rl261: output in $RL261_OUT, exit code $final"
exit "$final"
