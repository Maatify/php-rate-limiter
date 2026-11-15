#!/usr/bin/env bash
# Reproduces the raw results of the EVALSHA study (#210) against an isolated Redis container started by this script.
#   bash investigations/evalsha-210/run.sh [output-directory]      (default: investigations/evalsha-210/raw)
# Needs docker and a PHP with pcntl. The Redis image is the repository's CI image. Nothing is sent outside the machine.
set -euo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
out="${1:-$root/investigations/evalsha-210/raw}"
mkdir -p "$out"
name="rl210-run-$$"
port="${STUDY_REDIS_PORT:-6391}"
proxy_port="${STUDY_PROXY_PORT:-6392}"
one_way_ms="${STUDY_ONE_WAY_MS:-3.5}"
export STUDY_GIT_SHA="$(git -C "$root" rev-parse HEAD)"
proxy_pid=""
cleanup() {
    [[ -n "$proxy_pid" ]] && kill "$proxy_pid" 2>/dev/null || true
    docker rm -f "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --name "$name" -p "127.0.0.1:${port}:6379" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null
until [[ "$(docker exec "$name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done

{
    echo "git: $STUDY_GIT_SHA"
    echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "uname: $(uname -a)"
    echo "cpu: $(sysctl -n machdep.cpu.brand_string 2>/dev/null || grep -m1 'model name' /proc/cpuinfo 2>/dev/null || echo unknown)"
    echo "cores: $(sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null || echo unknown)"
    php -v | head -1
    echo "PHP_INT_SIZE: $(php -r 'echo PHP_INT_SIZE;')"
    echo "ext-redis: $(php -r 'echo phpversion("redis") ?: "not loaded";')"
    docker exec "$name" redis-server --version
    echo "redis config (maxmemory, lua-time-limit/busy-reply-threshold, tcp-keepalive):"
    docker exec "$name" redis-cli config get maxmemory
    docker exec "$name" redis-cli config get busy-reply-threshold
    docker exec "$name" redis-cli config get tcp-keepalive
    echo "docker: $(docker --version)"
    echo "network: loopback ($port) and a delay proxy (${one_way_ms} ms each way) on $proxy_port"
} > "$out/environment.txt"

modes=(eval evalsha evalsha-flush-each evalsha-flush-commit)
workloads=(read newdevice failure admission-reserve admission-outcome recovery)
cell() { # label host port mode workload requests warmup [extra]
    php "$root/investigations/evalsha-210/measure.php" "$2" "$3" "$4" "$5" "$6" "$7" "$1" ${8:-} > "$out/$1_$5_$4.json"
}

# 1. local, loopback: every workload in every mode
for w in "${workloads[@]}"; do
    n=200; warm=20; [[ "$w" == recovery ]] && n=40 && warm=3
    for m in "${modes[@]}"; do cell local 127.0.0.1 "$port" "$m" "$w" "$n" "$warm"; done
done

# 2. cold cache: SCRIPT FLUSH before the first measured request (sample 0 is the cold request)
for w in "${workloads[@]}"; do cell cold 127.0.0.1 "$port" evalsha "$w" 20 0 --cold-first; done

# 3. added round trip: a proxy delaying each direction
php "$root/investigations/evalsha-210/delay-proxy.php" "$proxy_port" 127.0.0.1 "$port" "$one_way_ms" &
proxy_pid=$!
sleep 1
for w in "${workloads[@]}"; do
    n=40; warm=4; [[ "$w" == recovery ]] && n=15 && warm=2
    for m in eval evalsha; do cell rtt 127.0.0.1 "$proxy_port" "$m" "$w" "$n" "$warm"; done
done
cell rtt 127.0.0.1 "$proxy_port" evalsha-flush-each newdevice 25 3
kill "$proxy_pid" 2>/dev/null || true
proxy_pid=""

# 4. hot-key contention: forked workers on one account, local and with the proxy
for workers in 8 16; do
    for m in eval evalsha; do
        php "$root/investigations/evalsha-210/contention.php" 127.0.0.1 "$port" "$m" "$workers" 25 "local-w$workers" > "$out/local_hot-w${workers}_$m.json"
    done
done
php "$root/investigations/evalsha-210/delay-proxy.php" "$proxy_port" 127.0.0.1 "$port" "$one_way_ms" &
proxy_pid=$!
sleep 1
for m in eval evalsha; do
    php "$root/investigations/evalsha-210/contention.php" 127.0.0.1 "$proxy_port" "$m" 8 10 "rtt-w8" > "$out/rtt_hot-w8_$m.json"
done
kill "$proxy_pid" 2>/dev/null || true
proxy_pid=""

# 5. cache loss by a server restart: the first requests of a new connection find an empty script cache
cell warmup-before-restart 127.0.0.1 "$port" evalsha newdevice 5 5
docker restart "$name" >/dev/null
until [[ "$(docker exec "$name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done
cell after-restart 127.0.0.1 "$port" evalsha newdevice 6 0
echo "raw results in $out"
