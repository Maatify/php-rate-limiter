#!/usr/bin/env bash
set -euo pipefail

script_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ -n "${REDIS_INTEGRATION_TEST_STATUS:-}" ]]; then
    command=(bash -c 'exit "$REDIS_INTEGRATION_TEST_STATUS"')
else
    command=(vendor/bin/phpunit --testsuite Integration)
fi
exec "$script_root/run-with-redis-service.sh" "${command[@]}"
