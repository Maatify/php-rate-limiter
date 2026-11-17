#!/usr/bin/env bash
# #260 investigation runner. Usage (repository root): bash investigations/fiber-request-ownership-260/run.sh [output-dir]
# Runs the governance helper (Docker, real clone of HEAD) and the Fiber suite on actual PHP_INT_SIZE 8 and 4 against an isolated Redis.
# Every step keeps its raw output and exit status; the script exits 1 if any required step failed (a red run is never reported as green).
set -uo pipefail
root=$(pwd)
out=${1:-"$root/investigations/fiber-request-ownership-260/raw"}
mkdir -p "$out"
: > "$out/result.txt"
failed=0
step() { # name, command...
    local name=$1; shift
    "$@" > "$out/$name.log" 2>&1
    local status=$?
    printf '%s\t%s\n' "$name" "$status" >> "$out/result.txt"
    sed -i.bak -e 's/[[:space:]]*$//' "$out/$name.log" && rm -f "$out/$name.log.bak"
    if [ "$status" -ne 0 ]; then failed=1; fi
}
suite() { # width, phpunit args
    local w=$1; shift
    local sfx="$$-$RANDOM" img=maatify-rl-php64:local plat=linux/arm64
    if [ "$w" = 32 ]; then img=maatify-rl-php32:local; plat=linux/386; fi
    local net="rl260-$sfx" rd="rl260-redis-$sfx" st=0
    docker network create "$net" > /dev/null || return 1
    docker run -d --name "$rd" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no > /dev/null || { docker network rm "$net" > /dev/null 2>&1; return 1; }
    until [ "$(docker exec "$rd" redis-cli ping 2>/dev/null)" = PONG ]; do sleep 0.3; done
    docker run --rm --platform "$plat" --network "$net" -v "$root:/app" -w /app -e REDIS_INTEGRATION_HOST="$rd" -e REDIS_INTEGRATION_PORT=6379 "$img" \
        sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\" redis=\",phpversion(\"redis\"),\"\n\";"; php -d memory_limit=1G vendor/bin/phpunit --fail-on-empty-test-suite "$@"' sh "$@"
    st=$?
    docker rm -f "$rd" > /dev/null 2>&1; docker network rm "$net" > /dev/null 2>&1
    return $st
}
rm -rf /tmp/rl260-clone && git clone -q "$root" /tmp/rl260-clone && git -C /tmp/rl260-clone checkout -q "$(git rev-parse HEAD)"
step governance-helper docker run --rm -v /tmp/rl260-clone:/repo -w /repo maatify-rl-php64:local php .agents/helpers/maatify-governance-context.php
step suite-php64 suite 64 --testdox "${FIBER260_TEST:-tests/Integration/Redis/FiberRequestOwnershipRedisTest.php}"
step suite-php32 suite 32 --testdox "${FIBER260_TEST:-tests/Integration/Redis/FiberRequestOwnershipRedisTest.php}"
git rev-parse HEAD 'HEAD^{tree}' > "$out/source.txt"
cat "$out/result.txt"
exit "$failed"
