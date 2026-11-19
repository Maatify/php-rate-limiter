#!/usr/bin/env bash
# usage: bench-impl.sh <64|32> <requests> <rounds>   (run alone). Alternates the BASE overlay (main@3acf0258) and the FIX overlay in the same tree, each round a fresh
# process against a fresh Redis, and prints one line per arm and round. The base arm temporarily replaces src/Repository/Redis/RedisRequestOverlay.php
# with `git show 3acf0258:...` and restores the working copy afterwards (a trap restores it on every exit path).
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
. investigations/redis-acl-request-save-261/common.sh
rl261_select_image "${1:-}"; n=${2:-300}; rounds=${3:-3}
rl261_out_dir
rl261_source_identity
rl261_artifacts bench-impl.log run-meta.txt
RL261_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
F=src/Repository/Redis/RedisRequestOverlay.php
cp "$F" "$RL261_OUT/overlay.fix.php" || exit 75
restore() { cp "$RL261_OUT/overlay.fix.php" "$F"; rl261_cleanup; }
trap restore EXIT
arm() { # label
  rl261_start_redis
  docker run --rm --platform "$RL261_PLAT" --network "$RL261_NET" -v "$RL261_ROOT:/app" -w /app "$RL261_IMG" \
    timeout "$RL261_RUN_SECONDS" php investigations/redis-acl-request-save-261/bench-impl.php "$RL261_REDIS" 6379 "$1" "$n" 2>&1 | tee -a "$RL261_OUT/bench-impl.log"
  local rc=${PIPESTATUS[0]}
  rl261_cleanup; RL261_NET=""; RL261_REDIS=""
  return "$rc"
}
rc=0
for r in $(seq 1 "$rounds"); do
  git show "3acf0258087a7bb89057dcf629f9bf8f5b38c246:$F" > "$F" || { rc=77; break; }
  arm "base-round$r" || rc=$?
  cp "$RL261_OUT/overlay.fix.php" "$F"
  arm "fix-round$r" || rc=$?
done
cp "$RL261_OUT/overlay.fix.php" "$F"
rl261_meta "$RL261_OUT/run-meta.txt" "$rc" bench-impl.sh "$RL261_PLAT" "$n" "$rounds"; metarc=$?
final=$(rl261_final_code "$rc" 0 "$metarc" 0)
echo "rl261: output in $RL261_OUT, exit code $final"
exit "$final"
