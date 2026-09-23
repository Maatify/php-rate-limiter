#!/usr/bin/env bash
set -euo pipefail

project="maatify-rate-limiter-${RANDOM}-${RANDOM}"
docker_bin="${REDIS_INTEGRATION_DOCKER_BIN:-docker}"
compose=("$docker_bin" compose -p "$project" -f docker/redis-integration/compose.yaml)
cleanup() {
    original_status=$?
    cleanup_status=0
    if "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1; then
        :
    else
        cleanup_status=$?
        printf 'Redis integration cleanup failed with status %s.\n' "$cleanup_status" >&2
    fi
    if (( original_status != 0 )); then
        exit "$original_status"
    fi
    exit "$cleanup_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

"${compose[@]}" up -d --wait
port="$(${compose[@]} port redis 6379 | awk -F: '{print $NF}')"
if [[ -n "${REDIS_INTEGRATION_TEST_STATUS:-}" ]]; then
    verification_status="$REDIS_INTEGRATION_TEST_STATUS"
else
    set +e
    REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" vendor/bin/phpunit --testsuite Integration
    verification_status=$?
    set -e
fi
exit "$verification_status"
