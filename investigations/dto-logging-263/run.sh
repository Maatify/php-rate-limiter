#!/usr/bin/env bash
# usage: run.sh <64|32> <output-dir> [extra phpunit args]
# #263 study runner. No Redis and no network: the backend is a test-controlled in-memory store, so only the local PHP image is used
# (`--network none`; nothing else is created, so there is nothing to clean up). Writes evidence.jsonl, phpunit.log and run-meta.txt into the
# output directory (relative, or absolute inside the repository).
# Exit: PHPUnit's own code; 64 bad arguments, 70 setup, 74 output directory refused, 75 an artifact cannot be written (or the log writer failed),
# 76 evidence is not exactly the expected number of records (EXPECTED_SCENARIOS, default 13), 77 source identity not verifiable.
# Order: every check (arguments, source identity, destination, artifact preconditions) happens BEFORE any file is opened for writing.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
. investigations/dto-logging-263/common.sh
rl263_select_image "${1:-}"
[ "$#" -ge 2 ] || rl263_die "usage: run.sh <64|32> <output-dir> [phpunit args]" 64
OUT_REQUEST=$2
shift 2
rl263_source_identity
rl263_out_dir "$OUT_REQUEST"
rl263_artifacts evidence.jsonl phpunit.log run-meta.txt
RL263_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EXPECTED=${EXPECTED_SCENARIOS:-13}
docker run --rm --network none --platform "$RL263_PLAT" -v "$RL263_ROOT:/app" -w /app -e STUDY_263_EVIDENCE="/app/$RL263_OUT/evidence.jsonl" "$RL263_IMG" \
  sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\"\n\";"; timeout "$0" php -d memory_limit=1G vendor/bin/phpunit "$@"' "$RL263_RUN_SECONDS" \
  tests/System/DtoSerializationLoggingStudy263Test.php "$@" 2>&1 | tee "$RL263_OUT/phpunit.log"
codes=("${PIPESTATUS[@]}")
rc=${codes[0]}; teerc=${codes[1]}
lines=$(wc -l < "$RL263_OUT/evidence.jsonl" 2>/dev/null | tr -d ' ')
extra=0
if [ "$rc" = 0 ] && [ "$lines" != "$EXPECTED" ]; then echo "rl263: evidence has ${lines:-?} records, expected $EXPECTED" >&2; extra=76; fi
# The record is written with the final code already known, so it can state it.
final=$(rl263_final_code "$rc" "$teerc" 0 "$extra")
rl263_meta "$RL263_OUT/run-meta.txt" "$rc" "${lines:-?}" "$final" "run.sh $RL263_PLAT $RL263_OUT${*:+ $*}"; metarc=$?
final=$(rl263_final_code "$rc" "$teerc" "$metarc" "$extra")
[ "$final" != 0 ] && [ "$rc" = 0 ] && echo "rl263: the tool succeeded but the run's record is incomplete (code $final)" >&2
echo "rl263: output in $RL263_OUT, exit code $final (tool $rc)"
exit "$final"
