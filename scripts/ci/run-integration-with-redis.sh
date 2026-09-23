#!/usr/bin/env bash
set -euo pipefail

project="maatify-rate-limiter-${RANDOM}-${RANDOM}"
compose=(docker compose -p "$project" -f docker/redis-integration/compose.yaml)
cleanup() { "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT INT TERM

"${compose[@]}" up -d --wait
port="$(${compose[@]} port redis 6379 | awk -F: '{print $NF}')"
REDIS_INTEGRATION_HOST=127.0.0.1 REDIS_INTEGRATION_PORT="$port" vendor/bin/phpunit --testsuite Integration
