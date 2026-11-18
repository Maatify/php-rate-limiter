#!/usr/bin/env bash
# usage: run.sh <64|32> <evidence file> <phpunit args...>   (isolated Redis 7.0.15 per call, own network)
set -uo pipefail
w=$1; ev=$2; shift 2
root=$(pwd)
sfx="$$-$RANDOM"; net=rl261-$sfx; rd=rl261-redis-$sfx
img=maatify-rl-php64:local; plat=linux/arm64
if [ "$w" = 32 ]; then img=maatify-rl-php32:local; plat=linux/386; fi
trap 'docker rm -f $rd >/dev/null 2>&1; docker network rm $net >/dev/null 2>&1' EXIT
docker network create $net >/dev/null
docker run -d --name $rd --network $net redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [ "$(docker exec $rd redis-cli ping 2>/dev/null)" = PONG ]; do sleep 0.3; done
: > "$ev"
docker run --rm --platform $plat --network $net -v "$root:/app" -w /app -e REDIS_INTEGRATION_HOST=$rd -e REDIS_INTEGRATION_PORT=6379 -e ACL_261_EVIDENCE=/app/${ev#"$root"/} "$img" \
  sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\" ext-redis=\",phpversion(\"redis\"),\"\n\";"; docker_redis=1; php -d memory_limit=1G vendor/bin/phpunit "$@"' sh "$@"
rc=$?
echo "redis: $(docker exec $rd redis-server --version)"
exit $rc
