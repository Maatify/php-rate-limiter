#!/usr/bin/env bash
# #284 study: one run = one isolated redis:7.0.15-alpine + one FULL CLEAN CLONE of the official remote inside the PHP container.
# Usage: run_in_docker.sh <32|64> <deps-dir-with-vendor> <out-dir>
# Everything that identifies the run (source SHA, tree, git, PHP, PHP_INT_SIZE, ext-redis, Redis server) is logged from inside the containers.
set -uo pipefail
width="$1"; deps="$2"; out="$3"
base_sha=109f3c6d7d81efda750a2d6d4be13eaa0eff55c6
base_tree=7ec62d35b97f675f33cbe66bb556de74bd82b085
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ "$width" == 32 ]]; then image=maatify-rl-php32:verify-git-283; platform=linux/386; else image=maatify-rl-php64:verify-git-283; platform=""; fi
suffix="${RANDOM}-${RANDOM}-$$"; net="s284-${width}-${suffix}"; redis="s284-redis-${width}-${suffix}"
mkdir -p "$out"; log="$out/run-${width}bit.log"
cleanup() { docker rm -f "$redis" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker network create "$net" >/dev/null
docker run -d --name "$redis" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [[ "$(docker exec "$redis" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done
{
  echo "== redis-server (from inside redis container) =="; docker exec "$redis" redis-server --version
  docker run --rm ${platform:+--platform "$platform"} --network "$net" -v "$deps:/deps:ro" -v "$here:/study:ro" \
    -e REDIS_INTEGRATION_HOST="$redis" -e REDIS_INTEGRATION_PORT=6379 "$image" bash -c '
      set -uo pipefail
      echo "== php container =="; git --version
      php -r "echo \"PHP \", PHP_VERSION, \" PHP_INT_SIZE=\", PHP_INT_SIZE, \" ext-redis=\", phpversion(\"redis\"), PHP_EOL;"
      git clone -q https://github.com/Maatify/php-rate-limiter.git /work && git -C /work checkout -q '"$base_sha"' || { echo "CLONE FAILED"; exit 3; }
      head=$(git -C /work rev-parse HEAD); tree=$(git -C /work rev-parse "HEAD^{tree}"); dirty=$(git -C /work status --porcelain | wc -l)
      echo "source HEAD=$head tree=$tree dirty_files=$dirty remote=$(git -C /work remote get-url origin)"
      [[ "$head" == '"$base_sha"' && "$tree" == '"$base_tree"' && "$dirty" == 0 ]] || { echo "BASE MISMATCH"; exit 4; }
      cp /study/k4_contract_controls.php /tmp/k4_contract_controls.php
      php /tmp/k4_contract_controls.php /work; rc=$?; echo "harness_exit_code=$rc"; exit $rc'
  rc=$?; echo "container_exit_code=$rc"; echo "$rc" > "$out/exit-${width}bit.txt"
} 2>&1 | tee "$log"
exit "$(cat "$out/exit-${width}bit.txt")"
