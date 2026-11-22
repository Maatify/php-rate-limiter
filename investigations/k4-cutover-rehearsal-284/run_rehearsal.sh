#!/usr/bin/env bash
# #284 K4 cutover REHEARSAL: one run = one isolated redis:7.0.15-alpine + one FULL CLEAN CLONE of the official remote inside the PHP container.
# Usage: run_rehearsal.sh <32|64> <deps-dir-with-vendor> <out-dir>   (64: full rehearsal, 32: 32-bit decimal-arithmetic controls of the gate)
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
base_sha=ca626bf9d6f6216036321c79bd8216df98d70b75
base_tree=b8bf598ffeceb8338744d5de3005878d43b799dc
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

case "$width" in
  32) image=maatify-rl-php32:verify-git-283; platform=linux/386; expected_int_size=4 ;;
  64) image=maatify-rl-php64:verify-git-283; platform=""; expected_int_size=8 ;;
  *) echo "usage: run_in_docker.sh <32|64> <deps-dir> <out-dir>" >&2; exit 2 ;;
esac
[[ -n "$deps" && -f "$deps/vendor/autoload.php" && -n "$out" ]] || { echo "usage: deps dir must contain vendor/autoload.php and out dir must be given" >&2; exit 2; }

mkdir -p "$out" || { echo "cannot create evidence directory: $out" >&2; exit 7; }
run_mode=controller; [[ "$width" == 32 ]] && run_mode=int
exit_file="$out/exit-${width}bit.txt"

# Every resource of THIS run carries the run's own suffix and is removed by that exact name (never by pattern, never another run's).
suffix="${RANDOM}-${RANDOM}-$$"
# Evidence of THIS run is written to a private staging directory. Accepted evidence already in $out is never touched until this run is verified;
# a rejected run is kept under $out/rejected/ and the previous evidence stays exactly as it was.
stage="$out/.stage-${suffix}"
mkdir -p "$stage" || { echo "cannot create staging directory: $stage" >&2; exit 7; }
out_abs="$(cd "$stage" && pwd)"
log="$stage/run-${width}bit.log"
: > "$log" || { echo "cannot write evidence log: $log" >&2; exit 7; }
sha256_of() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
reject() { # rc reason -> keep the staged evidence aside, leave accepted evidence untouched
  local code="$1" reason="$2" dest
  echo "EVIDENCE REJECTED: $reason (exit $code); previous accepted evidence in $out is untouched" >&2
  mkdir -p "$out/rejected" && dest="$out/rejected/$(date +%s)-${width}bit-rc${code}" && mv "$stage" "$dest" 2>/dev/null && echo "rejected run kept in $dest" >&2
  exit "$code"
}
net="s284r-${width}-${suffix}"; redis="s284r-redis-${width}-${suffix}"
php_probe="s284r-probe-${width}-${suffix}"; php_run="s284r-php-${width}-${suffix}"
cleanup() {
  "$docker_bin" rm -f "$php_run" >/dev/null 2>&1 || true
  "$docker_bin" rm -f "$php_probe" >/dev/null 2>&1 || true
  "$docker_bin" rm -f "$redis" >/dev/null 2>&1 || true
  "$docker_bin" network rm "$net" >/dev/null 2>&1 || true
  rm -f "${TMPDIR:-/tmp}/s284r-probe-out-$suffix"
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
  "$docker_bin" run -d --name "$redis" --network "$net" redis:7.0.15-alpine sh -c 'while :; do redis-server --save "" --appendonly no --maxmemory-policy noeviction; sleep 0.2; done' >/dev/null || return 5
  # Readiness is bounded as a whole: a PONG after the deadline, or with a non-zero probe exit status, is not ready (5); a hung probe gets TERM then KILL at the deadline.
  ready=no; deadline=$((SECONDS + ready_timeout)); probe_out="${TMPDIR:-/tmp}/s284r-probe-out-$suffix"
  while :; do
    "$docker_bin" exec "$redis" redis-cli ping >"$probe_out" 2>/dev/null &
    probe_pid=$!
    while kill -0 "$probe_pid" 2>/dev/null; do
      if (( SECONDS >= deadline )); then
        # TERM first, then KILL after a short grace: a probe that ignores TERM must not extend the bound.
        kill -TERM "$probe_pid" 2>/dev/null; sleep 0.3; kill -KILL "$probe_pid" 2>/dev/null; break
      fi
      sleep 0.2
    done
    wait "$probe_pid" 2>/dev/null; probe_rc=$?
    # Ready ONLY if the probe exited 0 (not killed, not a non-zero status) AND printed PONG AND finished within the deadline.
    if (( probe_rc == 0 && SECONDS <= deadline )) && [[ "$(cat "$probe_out" 2>/dev/null)" == PONG ]]; then ready=yes; break; fi
    if (( SECONDS >= deadline )); then break; fi
    sleep 0.5
  done
  rm -f "$probe_out"
  [[ "$ready" == yes ]] || { echo "REDIS NOT READY within ${ready_timeout}s"; return 5; }
  echo "== redis-server (from inside redis container) =="; "$docker_bin" exec "$redis" redis-server --version

  # 3. Clean clone of the official remote inside the PHP container, pinned base check, then the controls.
  "$docker_bin" run --rm --name "$php_run" ${platform:+--platform "$platform"} --network "$net" -v "$deps:/deps:ro" -v "$here:/study:ro" -v "$out_abs:/out" \
    -e REDIS_INTEGRATION_HOST="$redis" -e REDIS_INTEGRATION_PORT=6379 ${S284_SCENARIOS:+-e S284_SCENARIOS="$S284_SCENARIOS"} "$image" bash /study/container_check.sh "$base_sha" "$base_tree" "$run_mode" /out
  rc=$?
  echo "container_exit_code=$rc"
  return "$rc"
}

# The pipeline status of BOTH ends is read: a failing tee (disk full, unwritable log, a tee that exits 23) is an evidence failure, never a pass.
body 2>&1 | "$tee_bin" "$log"
statuses=("${PIPESTATUS[@]}")
body_rc="${statuses[0]}"; tee_rc="${statuses[1]:-1}"

if [[ "$tee_rc" -ne 0 ]]; then
  reject 7 "tee exited $tee_rc (body exit $body_rc)"
fi
if [[ ! -s "$log" ]]; then
  reject 7 "empty log $log"
fi
if [[ "$body_rc" -ne 0 ]]; then
  reject "$body_rc" "the run did not hold (exit $body_rc)"
fi
results_name=""
if [[ "$width" == 64 ]]; then
  # The results file must exist, be non-empty, be reported as saved-and-verified by the harness, and match the sha256 the harness logged.
  results="$stage/rehearsal-results.json"
  [[ -s "$results" ]] || reject 7 "results file missing or empty"
  logged_sha="$(grep -o '"results_sha256":"[0-9a-f]\{64\}"' "$log" | tail -1 | cut -d'"' -f4)"
  grep -q '"saved_and_verified":true' "$log" || reject 7 "the harness did not report the results file as saved and verified"
  [[ -n "$logged_sha" && "$(sha256_of "$results")" == "$logged_sha" ]] || reject 7 "results file sha256 differs from the one the harness logged"
  results_name="rehearsal-results.json"
fi
# promote the verified run: accepted evidence is replaced only now
mv -f "$log" "$out/run-${width}bit.log" || reject 7 "cannot promote the log"
if [[ -n "$results_name" ]]; then mv -f "$stage/$results_name" "$out/$results_name" || reject 7 "cannot promote the results"; fi
printf '%s\n' "$body_rc" > "$exit_file" || { echo "EVIDENCE WRITE FAILED: $exit_file" >&2; exit 7; }
rmdir "$stage" 2>/dev/null || true
exit "$body_rc"
