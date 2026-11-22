#!/usr/bin/env bash
# #284 study: one run = one isolated redis:7.0.15-alpine + one FULL CLEAN CLONE of the official remote inside the PHP container.
# Usage: run_in_docker.sh <32|64> <deps-dir-with-vendor> <out-dir>
# Everything that identifies the run (source SHA, tree, git, PHP, PHP_INT_SIZE, ext-redis, Redis server) is logged from inside the containers.
#
# Exit codes (any other value is the harness's own exit code, passed through):
#   0 all controls held            2 usage / missing dependency directory   3 clone or checkout failed
#   4 source is not provably the pinned clean base (SHA, tree, dirty, or a git command failed/empty)   5 Redis not ready within the timeout (a late PONG counts)
#   6 PHP_INT_SIZE differs from the requested width                         7 evidence could not be written (log, tee or exit file)
#
# Test-only overrides (investigations/k4-scope-encoding-284/selftest.sh): S284_DOCKER_BIN, S284_TEE_BIN, S284_REDIS_READY_TIMEOUT (seconds).
set -uo pipefail

width="${1:-}"; deps="${2:-}"; out="${3:-}"
docker_bin="${S284_DOCKER_BIN:-docker}"
tee_bin="${S284_TEE_BIN:-tee}"
ready_timeout="${S284_REDIS_READY_TIMEOUT:-60}"
base_sha=109f3c6d7d81efda750a2d6d4be13eaa0eff55c6
base_tree=7ec62d35b97f675f33cbe66bb556de74bd82b085
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

case "$width" in
  32) image=maatify-rl-php32:verify-git-283; platform=linux/386; expected_int_size=4 ;;
  64) image=maatify-rl-php64:verify-git-283; platform=""; expected_int_size=8 ;;
  *) echo "usage: run_in_docker.sh <32|64> <deps-dir> <out-dir>" >&2; exit 2 ;;
esac
[[ -n "$deps" && -f "$deps/vendor/autoload.php" && -n "$out" ]] || { echo "usage: deps dir must contain vendor/autoload.php and out dir must be given" >&2; exit 2; }

mkdir -p "$out" || { echo "cannot create evidence directory: $out" >&2; exit 7; }
log="$out/run-${width}bit.log"; exit_file="$out/exit-${width}bit.txt"
: > "$log" || { echo "cannot write evidence log: $log" >&2; exit 7; }

# Every resource of THIS run carries the run's own suffix and is removed by that exact name (never by pattern, never another run's).
suffix="${RANDOM}-${RANDOM}-$$"
net="s284-${width}-${suffix}"; redis="s284-redis-${width}-${suffix}"
php_probe="s284-probe-${width}-${suffix}"; php_run="s284-php-${width}-${suffix}"
cleanup() {
  "$docker_bin" rm -f "$php_run" >/dev/null 2>&1 || true
  "$docker_bin" rm -f "$php_probe" >/dev/null 2>&1 || true
  "$docker_bin" rm -f "$redis" >/dev/null 2>&1 || true
  "$docker_bin" network rm "$net" >/dev/null 2>&1 || true
  rm -f "${TMPDIR:-/tmp}/s284-probe-out-$suffix"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

body() {
  # 1. The requested width must be the width of the interpreter that will run the controls (checked before anything else is started).
  echo "== width probe =="
  actual="$("$docker_bin" run --rm --name "$php_probe" ${platform:+--platform "$platform"} "$image" php -r 'echo PHP_INT_SIZE;' 2>/dev/null)" || actual="probe-failed"
  echo "requested_width=$width expected_PHP_INT_SIZE=$expected_int_size actual_PHP_INT_SIZE=$actual"
  [[ "$actual" == "$expected_int_size" ]] || { echo "PHP_INT_SIZE MISMATCH"; return 6; }

  # 2. Isolated Redis, bounded readiness wait.
  "$docker_bin" network create "$net" >/dev/null || return 5
  "$docker_bin" run -d --name "$redis" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no >/dev/null || return 5
  # Readiness is bounded as a whole: a PONG that arrives after the deadline is a timeout (5), and a hung probe is killed at the deadline.
  ready=no; deadline=$((SECONDS + ready_timeout)); probe_out="${TMPDIR:-/tmp}/s284-probe-out-$suffix"
  while :; do
    "$docker_bin" exec "$redis" redis-cli ping >"$probe_out" 2>/dev/null &
    probe_pid=$!
    while kill -0 "$probe_pid" 2>/dev/null; do
      if (( SECONDS >= deadline )); then kill "$probe_pid" 2>/dev/null; break; fi
      sleep 0.2
    done
    wait "$probe_pid" 2>/dev/null
    if (( SECONDS <= deadline )) && [[ "$(cat "$probe_out" 2>/dev/null)" == PONG ]]; then ready=yes; break; fi
    if (( SECONDS >= deadline )); then break; fi
    sleep 0.5
  done
  rm -f "$probe_out"
  [[ "$ready" == yes ]] || { echo "REDIS NOT READY within ${ready_timeout}s"; return 5; }
  echo "== redis-server (from inside redis container) =="; "$docker_bin" exec "$redis" redis-server --version

  # 3. Clean clone of the official remote inside the PHP container, pinned base check, then the controls.
  "$docker_bin" run --rm --name "$php_run" ${platform:+--platform "$platform"} --network "$net" -v "$deps:/deps:ro" -v "$here:/study:ro" \
    -e REDIS_INTEGRATION_HOST="$redis" -e REDIS_INTEGRATION_PORT=6379 "$image" bash /study/container_check.sh "$base_sha" "$base_tree"
  rc=$?
  echo "container_exit_code=$rc"
  return "$rc"
}

# The pipeline status of BOTH ends is read: a failing tee (disk full, unwritable log, a tee that exits 23) is an evidence failure, never a pass.
body 2>&1 | "$tee_bin" "$log"
statuses=("${PIPESTATUS[@]}")
body_rc="${statuses[0]}"; tee_rc="${statuses[1]:-1}"

if [[ "$tee_rc" -ne 0 ]]; then
  echo "EVIDENCE WRITE FAILED: tee exited $tee_rc (body exit $body_rc)" >&2
  exit 7
fi
if [[ ! -s "$log" ]]; then
  echo "EVIDENCE WRITE FAILED: empty log $log" >&2
  exit 7
fi
printf '%s\n' "$body_rc" > "$exit_file" || { echo "EVIDENCE WRITE FAILED: $exit_file" >&2; exit 7; }
exit "$body_rc"
