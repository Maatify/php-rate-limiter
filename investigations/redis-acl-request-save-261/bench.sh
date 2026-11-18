#!/usr/bin/env bash
# usage: bench.sh <64|32> <requests per arm>   (isolated Redis 7.0.15; run alone, never beside the tests)
set -uo pipefail
w=$1; n=${2:-300}
root=$(pwd)
sfx="$$-$RANDOM"; net=rl261b-$sfx; rd=rl261b-redis-$sfx
img=maatify-rl-php64:local; plat=linux/arm64
if [ "$w" = 32 ]; then img=maatify-rl-php32:local; plat=linux/386; fi
trap 'docker rm -f $rd >/dev/null 2>&1; docker network rm $net >/dev/null 2>&1' EXIT
docker network create $net >/dev/null
docker run -d --name $rd --network $net redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [ "$(docker exec $rd redis-cli ping 2>/dev/null)" = PONG ]; do sleep 0.3; done
docker run --rm --platform $plat --network $net -v "$root:/app" -w /app "$img" php investigations/redis-acl-request-save-261/bench.php $rd 6379 $n
