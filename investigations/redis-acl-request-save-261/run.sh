#!/usr/bin/env bash
# usage: run.sh <64|32> <phpunit args...>
# Writes evidence.jsonl, phpunit.log and run-meta.txt (source head, command, exit code) to $RL261_OUT (default: out/<utc>-<pid>, untracked).
# Copy a finished run to raw/ yourself when it is meant to be committed. Isolated Redis per call; exit code is PHPUnit's, or 7x on setup failure.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
. investigations/redis-acl-request-save-261/common.sh
rl261_select_image "${1:-}"; shift
rl261_out_dir
RL261_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
rl261_start_redis
: > "$RL261_OUT/evidence.jsonl"
docker run --rm --platform "$RL261_PLAT" --network "$RL261_NET" -v "$RL261_ROOT:/app" -w /app \
  -e REDIS_INTEGRATION_HOST="$RL261_REDIS" -e REDIS_INTEGRATION_PORT=6379 -e ACL_261_EVIDENCE="/app/$RL261_OUT/evidence.jsonl" "$RL261_IMG" \
  sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\" ext-redis=\",phpversion(\"redis\"),\"\n\";"; timeout "$0" php -d memory_limit=1G vendor/bin/phpunit "$@"' "$RL261_RUN_SECONDS" "$@" 2>&1 | tee "$RL261_OUT/phpunit.log"
rc=${PIPESTATUS[0]}
echo "redis: $(docker exec "$RL261_REDIS" redis-server --version)" | tee -a "$RL261_OUT/phpunit.log"
rl261_meta "$RL261_OUT/run-meta.txt" "$rc" run.sh "$RL261_PLAT" "$@"
echo "rl261: output in $RL261_OUT, exit code $rc"
exit "$rc"
