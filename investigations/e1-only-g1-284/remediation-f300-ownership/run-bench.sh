#!/usr/bin/env bash
# Pre-ownership-fix source (6e37b982, before F300-03..08) vs current HEAD, unchanged 14-scenario harness. Run with no concurrent local suites.
set -euo pipefail
cd "$(dirname "$0")/../../.."
width="${1:?32 or 64}"; rounds="${2:-3}"
case "$width" in
 32) platform=linux/386; image=maatify-rl-php32:local;;
 64) platform=linux/arm64; image=maatify-rl-php64:local;;
 *) exit 2;;
esac
base=6e37b982e40e6081f617dfa9d98e816ca30c34a4
baseline="$(mktemp -d "${TMPDIR:-/tmp}/own-bench-base.XXXXXX")"
network="own-bench-${width}-$$"; redis_name="${network}-redis"
cleanup() { status=$?; docker rm -f "$redis_name" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; rm -rf -- "$baseline"; exit "$status"; }
trap cleanup EXIT
# Complete src from the exact pinned source, no working-tree swap or source patch.
git archive "$base" src | tar -x -C "$baseline"
docker network create "$network" >/dev/null
docker run -d --name "$redis_name" --network "$network" redis:7.0.15-alpine redis-server --save '' --appendonly no >/dev/null
until [[ "$(docker exec "$redis_name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.2; done
out="investigations/e1-only-g1-284/remediation-f300-ownership/raw/bench-${width}.jsonl"
: > "$out"
{
 echo "base=$base tree=$(git rev-parse "$base^{tree}")"
 echo "head=$(git rev-parse HEAD) branch=$(git branch --show-current) dirtyFiles=$(git status --porcelain | wc -l | tr -d ' ')"
 echo "platform=$platform image=$image repeats=$rounds concurrency=1 n=100 warmup=15 mode=EVAL callerNow=1793793600"
 docker image inspect "$image" --format '{{.Id}}'
 docker exec "$redis_name" redis-server --version
} > "investigations/e1-only-g1-284/remediation-f300-ownership/raw/bench-${width}-meta.txt"
for ((round=1;round<=rounds;round++)); do
 # Rotate order between repeats to reduce systematic warm-cache/order bias.
 if ((round % 2)); then arms=(before after); else arms=(after before); fi
 for arm in "${arms[@]}"; do
  source=/baseline; [[ "$arm" != after ]] || source=-
  docker run --rm --platform "$platform" --network "$network" -v "$PWD:/app" -v "$baseline:/baseline:ro" -w /app "$image" \
   php investigations/e1-only-g1-284/bench.php "$redis_name" 6379 "$arm" "$source" "$round" >> "$out"
 done
done
