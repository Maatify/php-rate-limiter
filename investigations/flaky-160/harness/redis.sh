#!/usr/bin/env bash
# PROVENANCE: copy of investigations/flaky-157/harness/redis.sh at 8b79e9b, sha256 f71afb0946ea17a31bb6b45eade1e6678652262dfb88cff7d415e17d02edbb1f; delete this line to get the original byte for byte.
# #157 Redis service for one run (PLAN.md §4, §9.1 LT-157-02, §9.2 V-PORT / V-CONFIG).
# Uses the CI compose file docker/redis-integration/compose.yaml as data (same
# image, command and healthcheck as CI); it does not execute scripts/ci/.
#   redis.sh start <state-file>   -> writes "project=… port=…" and prints the port
#   redis.sh stop  <state-file>
set -euo pipefail

action="${1:?usage: redis.sh start|stop <state-file>}"
state="${2:?usage: redis.sh start|stop <state-file>}"
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
compose_file="$root/docker/redis-integration/compose.yaml"

case "$action" in
    start)
        project="flaky157-${RANDOM}${RANDOM}-$$"
        docker compose -p "$project" -f "$compose_file" up -d --wait >/dev/null 2>&1
        port="$(docker compose -p "$project" -f "$compose_file" port redis 6379 | awk -F: 'NF {print $NF}' | tail -n 1)"
        printf 'project=%s\nport=%s\n' "$project" "$port" > "$state"
        if [[ ! "$port" =~ ^[0-9]+$ || "$port" == 0 || "$port" == 6379 ]]; then
            echo "V-PORT FAIL: published port '$port'" >&2
            docker compose -p "$project" -f "$compose_file" down --volumes --remove-orphans >/dev/null 2>&1 || true
            exit 1
        fi
        echo "$port"
        ;;
    stop)
        project="$(sed -n 's/^project=//p' "$state")"
        docker compose -p "$project" -f "$compose_file" down --volumes --remove-orphans >/dev/null 2>&1
        ;;
    *)
        echo "unknown action $action" >&2
        exit 2
        ;;
esac
