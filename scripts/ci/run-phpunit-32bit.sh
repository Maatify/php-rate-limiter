#!/usr/bin/env bash
# Runs PHPUnit on an actual 32-bit PHP (PHP_INT_SIZE === 4, linux/386) against an isolated Redis (#213).
# Usage: run-phpunit-32bit.sh [phpunit arguments...]
set -euo pipefail

package_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
docker_bin="${REDIS_INTEGRATION_DOCKER_BIN:-docker}"
if [[ ! -x "$docker_bin" && "$docker_bin" != */* ]]; then
    docker_bin="$(command -v -- "$docker_bin" || true)"
fi
if [[ -z "$docker_bin" || ! -x "$docker_bin" ]]; then
    echo 'Configured Docker binary is unavailable.' >&2
    exit 127
fi

suffix="${RANDOM}-${RANDOM}-$$"
network="maatify-rl-php32-${suffix}"
redis_name="maatify-rl-php32-redis-${suffix}"
image="maatify-rl-php32:local"
cleanup() {
    original_status=$?
    "$docker_bin" rm -f "$redis_name" >/dev/null 2>&1 || true
    "$docker_bin" network rm "$network" >/dev/null 2>&1 || true
    return "$original_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

"$docker_bin" build -q --platform linux/386 -t "$image" "$package_root/docker/php32" >/dev/null
"$docker_bin" network create "$network" >/dev/null
"$docker_bin" run -d --name "$redis_name" --network "$network" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [[ "$("$docker_bin" exec "$redis_name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done

php_run=("$docker_bin" run --rm --platform linux/386 --network "$network" -v "$package_root:/app" -w /app
    -e REDIS_INTEGRATION_HOST="$redis_name" -e REDIS_INTEGRATION_PORT=6379 "$image")

"${php_run[@]}" php -r 'if (PHP_INT_SIZE !== 4) { fwrite(STDERR, "Not a 32-bit PHP build.\n"); exit(1); } echo "PHP ", PHP_VERSION, " PHP_INT_SIZE=", PHP_INT_SIZE, PHP_EOL;'
"$docker_bin" exec "$redis_name" redis-server --version
"${php_run[@]}" php -d memory_limit=1G vendor/bin/phpunit "$@"
