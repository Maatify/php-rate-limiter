#!/usr/bin/env bash
# usage: run.sh <64|32> <outdir-relative-to-repo> [extra phpunit args]
# #262 study runner. No Redis: the backend is a test-controlled in-memory store, so only the local PHP image is used (no network, nothing
# else created). Writes evidence.jsonl, phpunit.log and run-meta.txt into <outdir>. Exit: PHPUnit's own code; 64 bad width/args, 70 setup,
# 76 evidence empty or not exactly the expected scenario count, 77 source identity not verifiable (a run never exits 0 with an incomplete record).
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 70
ROOT=$(pwd -P)
case "${1:-}" in
  64) IMG=maatify-rl-php64:local; PLAT=linux/arm64 ;;
  32) IMG=maatify-rl-php32:local; PLAT=linux/386 ;;
  *) echo "run.sh: first argument must be 64 or 32" >&2; exit 64 ;;
esac
OUT=${2:-}
[ -n "$OUT" ] || { echo "run.sh: second argument (output directory inside the repository) is required" >&2; exit 64; }
shift 2
case "$OUT" in /*|*..*) echo "run.sh: the output directory must be relative and without '..'" >&2; exit 64 ;; esac
mkdir -p "$OUT" || exit 70
HEAD=$(git rev-parse HEAD) || exit 77
TREE=$(git rev-parse 'HEAD^{tree}') || exit 77
DIRTY=$(git status --porcelain -- . ':!investigations/fallback-env-scope-262/raw*' ':!investigations/fallback-env-scope-262/out' | wc -l | tr -d ' ')
: > "$OUT/evidence.jsonl" || exit 70
: > "$OUT/phpunit.log" || exit 70
EXPECTED=${EXPECTED_SCENARIOS:-13}
docker run --rm --platform "$PLAT" -v "$ROOT:/app" -w /app -e STUDY_262_EVIDENCE="/app/$OUT/evidence.jsonl" "$IMG" \
  sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\"\n\";"; timeout 600 php -d memory_limit=1G vendor/bin/phpunit "$@"' sh \
  tests/System/FallbackEnvironmentScopeSharingStudy262Test.php "$@" 2>&1 | tee "$OUT/phpunit.log"
codes=("${PIPESTATUS[@]}")
rc=${codes[0]}
final=$rc
lines=$(wc -l < "$OUT/evidence.jsonl" | tr -d ' ')
if [ "$final" = 0 ] && [ "$lines" != "$EXPECTED" ]; then echo "run.sh: evidence has $lines lines, expected $EXPECTED" >&2; final=76; fi
{
  echo "head=$HEAD"; echo "tree=$TREE"; echo "dirty_files_outside_evidence=$DIRTY"; echo "platform=$PLAT"; echo "image=$IMG"
  echo "command=run.sh $PLAT tests/System/FallbackEnvironmentScopeSharingStudy262Test.php $*"; echo "phpunit_exit=$rc"; echo "evidence_lines=$lines"; echo "final_exit=$final"
  echo "started_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$OUT/run-meta.txt" || final=77
echo "run.sh: output in $OUT, exit code $final (phpunit $rc)"
exit "$final"
