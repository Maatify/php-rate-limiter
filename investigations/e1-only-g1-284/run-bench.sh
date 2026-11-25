#!/usr/bin/env bash
# Sequential, isolated Redis only. Usage: run-bench.sh <32|64> [rounds=3]
set -euo pipefail
cd "$(dirname "$0")/../.."
width="${1:?32 or 64}"; rounds="${2:-3}"
case "$width" in
 32) platform=linux/386; image=maatify-rl-php32:local;;
 64) platform=linux/arm64; image=maatify-rl-php64:local;;
 *) exit 2;;
esac
base=6db137868f4abaebb94a287f75a3af1742d8d3bc
baseline="$(mktemp -d "${TMPDIR:-/tmp}/g1-bench-base.XXXXXX")"
network="g1-bench-${width}-$$"; redis_name="${network}-redis"
cleanup() { status=$?; docker rm -f "$redis_name" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; rm -rf -- "$baseline"; exit "$status"; }
trap cleanup EXIT
# Complete src from the exact pinned source, no working-tree swap or source patch.
git archive "$base" src | tar -x -C "$baseline"
docker network create "$network" >/dev/null
docker run -d --name "$redis_name" --network "$network" redis:7.0.15-alpine redis-server --save '' --appendonly no >/dev/null
until [[ "$(docker exec "$redis_name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.2; done
out="investigations/e1-only-g1-284/raw/bench-${width}.jsonl"
: > "$out"
{
 echo "base=$base tree=$(git rev-parse "$base^{tree}")"
 echo "head=$(git rev-parse HEAD) branch=$(git branch --show-current) dirtyFiles=$(git status --porcelain | wc -l | tr -d ' ')"
 echo "platform=$platform image=$image repeats=$rounds concurrency=1 n=100 warmup=15 mode=EVAL callerNow=1793793600"
 docker image inspect "$image" --format '{{.Id}}'
 docker exec "$redis_name" redis-server --version
} > "investigations/e1-only-g1-284/raw/bench-${width}-meta.txt"
for ((round=1;round<=rounds;round++)); do
 # Rotate order between repeats to reduce systematic warm-cache/order bias.
 case "$round" in 1) arms=(base-e1 g1 base-v2);; 2) arms=(g1 base-v2 base-e1);; *) arms=(base-v2 base-e1 g1);; esac
 for arm in "${arms[@]}"; do
  source=/baseline; [[ "$arm" != g1 ]] || source=-
  docker run --rm --platform "$platform" --network "$network" -v "$PWD:/app" -v "$baseline:/baseline:ro" -w /app "$image" \
   php investigations/e1-only-g1-284/bench.php "$redis_name" 6379 "$arm" "$source" "$round" >> "$out"
 done
done
