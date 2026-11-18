#!/usr/bin/env bash
# usage: run.sh <64|32> <phpunit args...>
# Writes evidence.jsonl, phpunit.log and run-meta.txt (source head/tree/dirty, command, exit code) to $RL261_OUT (default: out/<utc>-<pid>, untracked;
# relative, or absolute inside the repository). Copy a finished run to raw/ yourself when it is meant to be committed.
# Exit code: PHPUnit's own; otherwise 64 bad width, 70-77 setup/bookkeeping failure (72/73 Redis, 74 output directory, 75 artifact not writable,
# 76 required evidence empty, 77 source identity not verifiable). A run never returns 0 with an incomplete record.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
. investigations/redis-acl-request-save-261/common.sh
rl261_select_image "${1:-}"; shift
rl261_out_dir
rl261_source_identity
rl261_artifacts evidence.jsonl phpunit.log run-meta.txt
RL261_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
rl261_start_redis
docker run --rm --platform "$RL261_PLAT" --network "$RL261_NET" -v "$RL261_ROOT:/app" -w /app \
  -e REDIS_INTEGRATION_HOST="$RL261_REDIS" -e REDIS_INTEGRATION_PORT=6379 -e ACL_261_EVIDENCE="/app/$RL261_OUT/evidence.jsonl" "$RL261_IMG" \
  sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\" ext-redis=\",phpversion(\"redis\"),\"\n\";"; timeout "$0" php -d memory_limit=1G vendor/bin/phpunit "$@"' "$RL261_RUN_SECONDS" "$@" 2>&1 | tee "$RL261_OUT/phpunit.log"
codes=("${PIPESTATUS[@]}")
rc=${codes[0]}; teerc=${codes[1]}
echo "redis: $(docker exec "$RL261_REDIS" redis-server --version)" | tee -a "$RL261_OUT/phpunit.log" >/dev/null || teerc=75
# Evidence is required when the run executed the case provider (no --filter, or a filter naming testCase) of the study test.
extra=0
case " $* " in *RequestSaveAclRedisTest*) require=1 ;; *) require=0 ;; esac
case " $* " in *--filter*) case " $* " in *testCase*) ;; *) require=0 ;; esac ;; esac
if [ "$require" = 1 ] && [ "$rc" = 0 ] && [ ! -s "$RL261_OUT/evidence.jsonl" ]; then
  echo "rl261: the run succeeded but $RL261_OUT/evidence.jsonl is empty" >&2; extra=76
fi
rl261_meta "$RL261_OUT/run-meta.txt" "$rc" run.sh "$RL261_PLAT" "$@"; metarc=$?
final=$(rl261_final_code "$rc" "$teerc" "$metarc" "$extra")
[ "$final" != 0 ] && [ "$rc" = 0 ] && echo "rl261: the tool succeeded but the run's record is incomplete (code $final)" >&2
echo "rl261: output in $RL261_OUT, exit code $final (tool $rc)"
exit "$final"
