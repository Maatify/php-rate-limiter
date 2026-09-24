#!/usr/bin/env bash
set -euo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
helper="$root/scripts/ci/run-with-redis-service.sh"
fake_docker="$root/tests/Support/Redis/fake-docker.sh"

for case_values in '0 0 0' '7 0 7' '0 9 9' '7 9 7'; do
    read -r verification cleanup expected <<<"$case_values"
    set +e
    REDIS_INTEGRATION_DOCKER_BIN="$fake_docker" \
    REDIS_FAKE_COMPOSE_UP_STATUS=0 \
    REDIS_FAKE_COMPOSE_DOWN_STATUS="$cleanup" \
    bash "$helper" bash -c 'exit "$1"' _ "$verification" >/dev/null 2>&1
    actual=$?
    set -e
    if [[ "$actual" != "$expected" ]]; then
        printf 'Redis lifecycle status regression failed: verification=%s cleanup=%s expected=%s actual=%s.\n' "$verification" "$cleanup" "$expected" "$actual" >&2
        exit 1
    fi
done
