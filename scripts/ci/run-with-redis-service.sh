#!/usr/bin/env bash
set -euo pipefail

if (( $# == 0 )); then
    echo 'Usage: run-with-redis-service.sh command [args...]' >&2
    exit 2
fi

package_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
docker_bin="${REDIS_INTEGRATION_DOCKER_BIN:-docker}"
if [[ ! -x "$docker_bin" && "$docker_bin" != */* ]]; then
    docker_bin="$(command -v -- "$docker_bin" || true)"
fi
if [[ -z "$docker_bin" || ! -x "$docker_bin" ]]; then
    echo 'Configured Docker binary is unavailable.' >&2
    exit 127
fi

project="maatify-rate-limiter-${RANDOM}-${RANDOM}-$$"
compose=("$docker_bin" compose -p "$project" -f "$package_root/docker/redis-integration/compose.yaml")
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
        return "$original_status"
    fi
    return "$cleanup_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

"${compose[@]}" up -d --wait
port="$(${compose[@]} port redis 6379 | awk -F: 'NF {print $NF}' | tail -n 1)"
if [[ ! "$port" =~ ^[0-9]+$ || "$port" == 0 ]]; then
    echo 'Unable to discover the dynamically published Redis port.' >&2
    exit 1
fi

REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" "$@"
