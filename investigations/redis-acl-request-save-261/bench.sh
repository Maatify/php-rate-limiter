#!/usr/bin/env bash
# usage: bench.sh <64|32> <requests per arm>   (run alone, never beside tests). Output in $RL261_OUT/bench.log with run-meta.txt.
# Same exit-code contract as run.sh: never 0 with an incomplete record.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
. investigations/redis-acl-request-save-261/common.sh
rl261_select_image "${1:-}"; n=${2:-300}
rl261_out_dir
rl261_source_identity
rl261_artifacts bench.log run-meta.txt
RL261_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
rl261_start_redis
docker run --rm --platform "$RL261_PLAT" --network "$RL261_NET" -v "$RL261_ROOT:/app" -w /app "$RL261_IMG" \
  timeout "$RL261_RUN_SECONDS" php investigations/redis-acl-request-save-261/bench.php "$RL261_REDIS" 6379 "$n" 2>&1 | tee "$RL261_OUT/bench.log"
codes=("${PIPESTATUS[@]}")
rc=${codes[0]}; teerc=${codes[1]}
rl261_meta "$RL261_OUT/run-meta.txt" "$rc" bench.sh "$RL261_PLAT" "$n"; metarc=$?
final=$(rl261_final_code "$rc" "$teerc" "$metarc" 0)
[ "$final" != 0 ] && [ "$rc" = 0 ] && echo "rl261: the tool succeeded but the run's record is incomplete (code $final)" >&2
echo "rl261: output in $RL261_OUT, exit code $final (tool $rc)"
exit "$final"
