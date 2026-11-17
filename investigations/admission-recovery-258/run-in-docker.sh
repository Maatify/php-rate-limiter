#!/usr/bin/env bash
# usage: run.sh <64|32> <phpunit args...>   (isolated Redis per call; own network)
set -uo pipefail
w=$1; shift
root=$(pwd)
sfx="$$-$RANDOM"; net=rl258-$sfx; rd=rl258-redis-$sfx
img=maatify-rl-php64:local; plat=linux/arm64
if [ "$w" = 32 ]; then img=maatify-rl-php32:local; plat=linux/386; fi
trap 'docker rm -f $rd >/dev/null 2>&1; docker network rm $net >/dev/null 2>&1' EXIT
docker network create $net >/dev/null
docker run -d --name $rd --network $net redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [ "$(docker exec $rd redis-cli ping 2>/dev/null)" = PONG ]; do sleep 0.3; done
docker run --rm --platform $plat --network $net -v "$root:/app" -w /app -e REDIS_INTEGRATION_HOST=$rd -e REDIS_INTEGRATION_PORT=6379 $img \
  sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\" redis=\",phpversion(\"redis\")?:\"none\",\"\n\";"; php -d memory_limit=1G vendor/bin/phpunit "$@"' sh "$@"
