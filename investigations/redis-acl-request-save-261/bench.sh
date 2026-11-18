#!/usr/bin/env bash
# usage: bench.sh <64|32> <requests per arm>   (run alone, never beside tests). Output in $RL261_OUT/bench.log with run-meta.txt.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
. investigations/redis-acl-request-save-261/common.sh
rl261_select_image "${1:-}"; n=${2:-300}
rl261_out_dir
RL261_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
rl261_start_redis
docker run --rm --platform "$RL261_PLAT" --network "$RL261_NET" -v "$RL261_ROOT:/app" -w /app "$RL261_IMG" \
  timeout "$RL261_RUN_SECONDS" php investigations/redis-acl-request-save-261/bench.php "$RL261_REDIS" 6379 "$n" 2>&1 | tee "$RL261_OUT/bench.log"
rc=${PIPESTATUS[0]}
rl261_meta "$RL261_OUT/run-meta.txt" "$rc" bench.sh "$RL261_PLAT" "$n"
echo "rl261: output in $RL261_OUT, exit code $rc"
exit "$rc"
