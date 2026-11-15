#!/usr/bin/env bash
# Repeats the contention cells alternately (eval, evalsha, eval, ...) to show the run-to-run spread behind a single p99.
#   bash investigations/evalsha-210/repeat-contention.sh [repeats] [output-directory]
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
repeats="${1:-6}"
out="${2:-$root/investigations/evalsha-210/raw}"
name="rl210-rep-$$"; port="${STUDY_REDIS_PORT:-6402}"; proxy_port="${STUDY_PROXY_PORT:-6403}"
export STUDY_GIT_SHA="$(git -C "$root" rev-parse HEAD)"
proxy_pid=""
cleanup() { [[ -n "$proxy_pid" ]] && kill "$proxy_pid" 2>/dev/null || true; docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run -d --name "$name" -p "127.0.0.1:${port}:6379" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [[ "$(docker exec "$name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done
for r in $(seq 1 "$repeats"); do
    for m in eval evalsha; do
        php "$root/investigations/evalsha-210/contention.php" 127.0.0.1 "$port" "$m" 16 25 "repeat-local-w16" > "$out/repeat-local_hot-w16-r${r}_$m.json"
    done
done
php "$root/investigations/evalsha-210/delay-proxy.php" "$proxy_port" 127.0.0.1 "$port" 3.5 &
proxy_pid=$!
sleep 1
for r in $(seq 1 "$repeats"); do
    for m in eval evalsha; do
        php "$root/investigations/evalsha-210/contention.php" 127.0.0.1 "$proxy_port" "$m" 8 10 "repeat-rtt-w8" > "$out/repeat-rtt_hot-w8-r${r}_$m.json"
    done
done
