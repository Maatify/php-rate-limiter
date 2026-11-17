#!/usr/bin/env bash
# The native-client part of the #210 study, second round: reply shapes and integer replies of ext-redis rawCommand() on both PHP widths, the
# NOSCRIPT classification test class on both widths, the first-round study test, the proxy artifact probe and the command-level latency probe.
# PHP and Redis run in Docker; Redis is isolated (started here, no persistence).
#   bash investigations/evalsha-210/noscript/run-native.sh [output-directory]    (default: investigations/evalsha-210/noscript/raw-native)
# Every step's output is kept in the output directory whatever its exit status. A step whose command exits non-zero is a failed required check:
# the runner finishes the remaining steps (so the diagnostics are complete), writes `result.txt`, and exits 1 if any step failed.
# Env: PHP64_IMAGE (default maatify-rl-php64:local, linux/arm64), PHP32_IMAGE (default maatify-rl-php32:local, linux/386).
set -uo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
out_rel="${1:-investigations/evalsha-210/noscript/raw-native}"
out="$root/$out_rel"
php64="${PHP64_IMAGE:-maatify-rl-php64:local}"
php32="${PHP32_IMAGE:-maatify-rl-php32:local}"
name="rl210-native-$$"
mkdir -p "$out"
: > "$out/result.txt"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
failed=0
record() { # <step> <exit-status>
    printf '%s\texit %s\n' "$1" "$2" >> "$out/result.txt"
    if [[ "$2" != 0 ]]; then failed=1; fi
}

if ! docker run -d --name "$name" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null; then
    record "start redis" 1
    echo "FAILED: start redis" >&2
    exit 1
fi
until [[ "$(docker exec "$name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.5; done
{
    echo "git: $(git -C "$root" rev-parse HEAD)  tree: $(git -C "$root" rev-parse 'HEAD^{tree}')"
    echo "tracked-status (empty = clean): $(git -C "$root" status --porcelain --untracked-files=no | tr '\n' ';')"
    echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    for pair in "64:linux/arm64:$php64" "32:linux/386:$php32"; do
        IFS=: read -r width platform image <<< "$pair"
        echo "php$width-image: $image $platform $(docker image inspect "$image" --format '{{.Id}} {{.Architecture}}' 2>&1)"
        echo "php$width: $(docker run --rm --platform "$platform" "$image" php -r 'echo PHP_VERSION, " INT_SIZE=", PHP_INT_SIZE, " ext-redis=", phpversion("redis");' 2>&1)"
    done
    echo "redis: $(docker exec "$name" redis-server --version)"
} | sed 's/[[:space:]]*$//' > "$out/environment.txt"

# step <name> <output-file> <platform> <image> <command...>: run in the PHP container that shares Redis's network namespace.
step() {
    local label="$1" file="$2" platform="$3" image="$4"
    shift 4
    docker run --rm --platform "$platform" --network "container:$name" -v "$root:/app" -w /app \
        -e REDIS_INTEGRATION_HOST=127.0.0.1 -e REDIS_INTEGRATION_PORT=6379 "$image" "$@" > "$out/$file" 2>&1
    local status=$?
    record "$label" "$status"
    if [[ "$status" != 0 ]]; then echo "FAILED: $label (exit $status), output kept in $out_rel/$file" >&2; fi
}

for pair in "64:linux/arm64:$php64" "32:linux/386:$php32"; do
    IFS=: read -r width platform image <<< "$pair"
    step "php$width shape probe" "shape-probe-w$width.txt" "$platform" "$image" php investigations/evalsha-210/noscript/shape-probe.php 127.0.0.1 6379
    step "php$width integer reply probe" "integer-reply-probe-w$width.txt" "$platform" "$image" php investigations/evalsha-210/noscript/integer-reply-probe.php 127.0.0.1 6379
    step "php$width NativeNoscriptStudyRedisTest" "native-noscript-w$width.txt" "$platform" "$image" php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result --testdox tests/Integration/Redis/NativeNoscriptStudyRedisTest.php
    step "php$width EvalshaStudyRedisTest" "evalsha-study-test-w$width.txt" "$platform" "$image" php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result tests/Integration/Redis/EvalshaStudyRedisTest.php
done
step "php64 proxy artifact probe" "payload-latency-probe.txt" linux/arm64 "$php64" sh -c '
set -e
php investigations/evalsha-210/delay-proxy.php 6402 127.0.0.1 6379 3.5 & php investigations/evalsha-210/noscript/delay-proxy-nodelay.php 6403 127.0.0.1 6379 3.5 & sleep 1
echo "-- direct"; php investigations/evalsha-210/noscript/payload-latency-probe.php 127.0.0.1 6379
echo "-- first-round proxy (delay-proxy.php, 3.5 ms each way)"; php investigations/evalsha-210/noscript/payload-latency-probe.php 127.0.0.1 6402
echo "-- delay-proxy-nodelay.php (3.5 ms each way)"; php investigations/evalsha-210/noscript/payload-latency-probe.php 127.0.0.1 6403'
step "php64 command latency probe" "command-latency-probe.txt" linux/arm64 "$php64" sh -c '
set -e
php investigations/evalsha-210/noscript/delay-proxy-nodelay.php 6403 127.0.0.1 6379 2.5 & sleep 1
for conns in 1 8; do for rep in 1 2 3; do
  if [ $((rep % 2)) = 1 ]; then o="eval evalsha"; else o="evalsha eval"; fi
  echo "== connections $conns repetition $rep order $o"; php investigations/evalsha-210/noscript/command-latency-probe.php 127.0.0.1 6403 $conns 150 13000 $o
done; done'

for f in "$out"/*.txt; do python3 - "$f" <<'PY'
import sys
p = sys.argv[1]
s = open(p, errors="replace").read()
t = "\n".join(line.rstrip() for line in s.split("\n")).rstrip("\n") + "\n"
if t != s:
    open(p, "w").write(t)
PY
done
if [[ "$failed" != 0 ]]; then
    echo "RESULT: FAILED (see $out_rel/result.txt)" | tee -a "$out/result.txt" >&2
    exit 1
fi
echo "RESULT: all required steps passed" | tee -a "$out/result.txt"
echo "raw results in $out"
