#!/usr/bin/env bash
# #260 cost measurement. Usage (repository root, clean committed tree): bash investigations/fiber-request-ownership-260/bench.sh <baseline-commit> [output-dir]
# Extracts the baseline commit and HEAD with `git archive` into scratch directories (each with this checkout's vendor/), then runs the same
# workloads of bench.php on both, SEQUENTIALLY and ALTERNATING (baseline, candidate, baseline, ...), on actual PHP_INT_SIZE 8 and 4, against
# one isolated Redis, with the Redis round trip and a no-op as controls. Every run's JSON is kept. No acceptance ratio is applied here.
set -uo pipefail
root=$(pwd)
base=${1:?baseline commit}
out=${2:-"$root/investigations/fiber-request-ownership-260/raw-bench"}
rounds=${BENCH_ROUNDS:-5}
iters=${BENCH_ITERATIONS:-3000}
warm=${BENCH_WARMUP:-300}
mkdir -p "$out"
: > "$out/runs.jsonl"
failed=0
if [ -n "$(git status --porcelain -- src tests)" ]; then echo "working tree not clean in src/tests" > "$out/refused.txt"; exit 1; fi
scratch=$(mktemp -d "${TMPDIR:-/tmp}/rl260-bench.XXXXXX") || exit 1
trap 'rm -rf "${scratch:?}"' EXIT
head_sha=$(git rev-parse HEAD)
for tree in baseline candidate; do
    mkdir -p "$scratch/$tree"
    commit=$base; [ "$tree" = candidate ] && commit=$head_sha
    git archive "$commit" | tar -x -C "$scratch/$tree" || failed=1
    cp -R "$root/vendor" "$scratch/$tree/vendor"
done
printf 'baseline %s\ncandidate %s\n' "$(git rev-parse "$base")" "$head_sha" > "$out/sources.txt"
sfx="$$-$RANDOM"; net="rl260b-$sfx"; rd="rl260b-redis-$sfx"
docker network create "$net" > /dev/null || exit 1
docker run -d --name "$rd" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no > /dev/null || exit 1
until [ "$(docker exec "$rd" redis-cli ping 2>/dev/null)" = PONG ]; do sleep 0.3; done
for width in 64 32; do
    img=maatify-rl-php64:local; plat=linux/arm64
    if [ "$width" = 32 ]; then img=maatify-rl-php32:local; plat=linux/386; fi
    for workload in noop ping direct nested limit; do
        for round in $(seq 1 "$rounds"); do
            # alternate the order of the two trees on every round
            order="baseline candidate"; [ $((round % 2)) -eq 0 ] && order="candidate baseline"
            for tree in $order; do
                line=$(docker run --rm --platform "$plat" --network "$net" -v "$scratch/$tree:/app" -v "$root/investigations/fiber-request-ownership-260/bench.php:/bench.php:ro" -w /app \
                    -e REDIS_INTEGRATION_HOST="$rd" -e REDIS_INTEGRATION_PORT=6379 "$img" php -d memory_limit=512M /bench.php "$workload" "$iters" "$warm" 2> "$out/last.err")
                st=$?
                if [ $st -ne 0 ] || [ -z "$line" ]; then failed=1; line="{\"workload\":\"$workload\",\"error\":$st}"; cat "$out/last.err" >> "$out/errors.log"; fi
                printf '%s\n' "$(printf '%s' "$line" | python3 -c 'import sys,json; d=json.loads(sys.stdin.read()); d.update(width='"$width"', round='"$round"', tree="'"$tree"'"); print(json.dumps(d))')" >> "$out/runs.jsonl"
            done
        done
    done
done
docker rm -fv "$rd" > /dev/null 2>&1; docker network rm "$net" > /dev/null 2>&1
exit "$failed"
