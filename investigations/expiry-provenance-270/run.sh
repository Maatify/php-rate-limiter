#!/usr/bin/env bash
# usage: run.sh <php64|php32> <phpunit args...>
#
# Runs the #270 study against a FRESH Redis 7.0.15 on an internal network (no published ports) and writes a self-describing evidence
# directory that this run creates itself:  <dir>/evidence.jsonl  phpunit.log  manifest.json
#
#   EVIDENCE_DIR      optional; must NOT exist (it is created here). Default: a new mktemp directory. Never inside the repository.
#   ALLOW_DIRTY=1     development only: records dirty=true in the manifest. Committed evidence must come from a clean, committed tree.
#
# Any failure to initialise, to start Redis, to get it ready in time, to run PHPUnit, or to find the expected evidence is a non-zero exit.
set -euo pipefail

EXPECTED_ROWS="${RUN270_EXPECTED_ROWS:-552}"
READY_SECONDS="${RUN270_READY_SECONDS:-30}"

die() { echo "run.sh: $1" >&2; exit "${2:-2}"; }

img="${1:-}"
[[ "$img" == php64 || "$img" == php32 ]] || die "the first argument must be php64 or php32"
shift
(($# > 0)) || die "phpunit arguments are required"
((EXPECTED_ROWS > 0)) || die "EXPECTED_ROWS is not set in this script" 2
command -v docker >/dev/null || die "docker is required"
command -v git >/dev/null || die "git is required"
command -v python3 >/dev/null || die "python3 is required (manifest and evidence checks)"

R="${RUN270_REPO_ROOT:-$(dirname "${BASH_SOURCE[0]}")/../..}"
R="$(cd "$R" && pwd -P)" || die "cannot resolve the repository root"
head="$(git -C "$R" rev-parse HEAD)" || die "not a git checkout: $R"
tree="$(git -C "$R" rev-parse 'HEAD^{tree}')"
dirty="$(git -C "$R" status --porcelain --untracked-files=all)"
dirty_flag=false
if [[ -n "$dirty" ]]; then
  [[ "${ALLOW_DIRTY:-0}" == 1 ]] || die "the working tree is not clean; evidence must come from a committed source (ALLOW_DIRTY=1 for development runs)"
  dirty_flag=true
fi

if [[ -n "${EVIDENCE_DIR:-}" ]]; then
  out="$EVIDENCE_DIR"
  mkdir "$out" 2>/dev/null || die "EVIDENCE_DIR must not exist and its parent must (it is created by this run): $out"
else
  out="$(mktemp -d "${TMPDIR:-/tmp}/exp270.XXXXXX")" || die "cannot create an evidence directory"
fi
out="$(cd "$out" && pwd -P)"
case "$out/" in "$R"/*) die "the evidence directory must be outside the repository: $out" ;; esac
[[ -z "$(ls -A "$out")" ]] || die "the evidence directory is not empty: $out"

net=""
rd=""
cleanup() {
  if [[ -n "$rd" ]]; then docker rm -f "$rd" >/dev/null 2>&1 || true; fi
  if [[ -n "$net" ]]; then docker network rm "$net" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT

suffix="$(date +%s)-$$-$RANDOM"
net="n270-$img-$suffix"
rd="r270-$img-$suffix"
docker network create --internal "$net" >/dev/null || die "cannot create the network"
docker run -d --rm --name "$rd" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null || die "cannot start Redis"

ready=false
for ((i = 0; i < READY_SECONDS; i++)); do
  if [[ "$(docker exec "$rd" redis-cli ping 2>/dev/null || true)" == PONG ]]; then ready=true; break; fi
  sleep 1
done
$ready || die "Redis was not ready within ${READY_SECONDS}s" 4

image="maatify-rl-${img}:local"
redis_version="$(docker exec "$rd" redis-server --version)" || die "cannot read the Redis version"
php_probe="$(docker run --rm --network none "$image" php -r 'echo PHP_VERSION, "|", PHP_INT_SIZE, "|", phpversion("redis");')" || die "cannot probe PHP"
phpunit_version="$(docker run --rm --network none -v "$R":/app:ro -w /app "$image" php vendor/bin/phpunit --version | head -n 1)" || die "cannot read the PHPUnit version"

cmd="docker run --rm --network $net -v <repo>:/app:ro -v <evidence-dir>:/out -w /app -e REDIS_INTEGRATION_HOST=<redis> -e REDIS_INTEGRATION_PORT=6379 -e EXPIRY_270_EVIDENCE=/out/evidence.jsonl $image php vendor/bin/phpunit --do-not-cache-result $*"
set +e
docker run --rm --network "$net" -v "$R":/app:ro -v "$out":/out -w /app \
  -e REDIS_INTEGRATION_HOST="$rd" -e REDIS_INTEGRATION_PORT=6379 -e EXPIRY_270_EVIDENCE=/out/evidence.jsonl \
  "$image" php vendor/bin/phpunit --do-not-cache-result "$@" 2>&1 | tee "$out/phpunit.log"
rc=${PIPESTATUS[0]}
set -e

summary="$(grep -E '^OK \(' "$out/phpunit.log" | tail -n 1 || true)"
rows=0
sha=""
if [[ -s "$out/evidence.jsonl" ]]; then
  rows="$(python3 -c 'import json,sys
n=0
for l in open(sys.argv[1]):
    json.loads(l); n+=1
print(n)' "$out/evidence.jsonl")" || die "evidence.jsonl contains a line that is not JSON" 3
  sha="$(shasum -a 256 "$out/evidence.jsonl" | cut -d' ' -f1)"
fi

status=ok
exit_code=0
if ((rc != 0)); then status="phpunit-failed"; exit_code=$rc
elif [[ -z "$summary" ]]; then status="no-ok-summary"; exit_code=3
elif [[ ! -s "$out/evidence.jsonl" ]]; then status="evidence-missing"; exit_code=3
elif ((rows != EXPECTED_ROWS)); then status="evidence-incomplete"; exit_code=3
fi

RUN_HEAD="$head" RUN_TREE="$tree" RUN_DIRTY="$dirty_flag" RUN_CMD="$cmd" RUN_IMG="$img" RUN_REDIS="$redis_version" RUN_PHP="$php_probe" \
  RUN_PHPUNIT="$phpunit_version" RUN_SUMMARY="$summary" RUN_ROWS="$rows" RUN_EXPECTED="$EXPECTED_ROWS" RUN_SHA="$sha" RUN_STATUS="$status" RUN_RC="$rc" \
  python3 - "$out/manifest.json" <<'PY'
import json, os, sys
e = os.environ
php, int_size, ext = (e["RUN_PHP"].split("|") + ["", "", ""])[:3]
manifest = {
    "source_head": e["RUN_HEAD"], "source_tree": e["RUN_TREE"], "source_dirty": e["RUN_DIRTY"] == "true",
    "image": e["RUN_IMG"], "command": e["RUN_CMD"],
    "php": php, "php_int_size": int_size, "ext_redis": ext, "redis": e["RUN_REDIS"], "phpunit": e["RUN_PHPUNIT"],
    "phpunit_summary": e["RUN_SUMMARY"], "phpunit_exit_code": int(e["RUN_RC"]),
    "evidence_rows": int(e["RUN_ROWS"]), "evidence_rows_expected": int(e["RUN_EXPECTED"]), "evidence_sha256": e["RUN_SHA"],
    "status": e["RUN_STATUS"],
}
with open(sys.argv[1], "x") as handle:
    json.dump(manifest, handle, indent=2, sort_keys=True)
    handle.write("\n")
PY

echo "evidence: $out  (status: $status, rows: $rows/$EXPECTED_ROWS)"
exit "$exit_code"
