#!/usr/bin/env bash
set -euo pipefail

action=""
for argument in "$@"; do
    case "$argument" in
        up|port|down)
            action="$argument"
            ;;
    esac
done

case "$action" in
    up)
        exit "${REDIS_FAKE_COMPOSE_UP_STATUS:-0}"
        ;;
    port)
        printf '127.0.0.1:6379\n'
        ;;
    down)
        exit "${REDIS_FAKE_COMPOSE_DOWN_STATUS:-0}"
        ;;
    *)
        printf 'Unsupported fake docker action.\n' >&2
        exit 64
        ;;
esac
