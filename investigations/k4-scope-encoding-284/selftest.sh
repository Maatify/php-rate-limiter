#!/usr/bin/env bash
# #284: independent controls for the runner (run_in_docker.sh). A fake `docker` records every call; nothing real is started.
# Each case states its input and the exit code the runner MUST return. Exit 0 only when every case holds.
set -uo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
runner="$here/run_in_docker.sh"
work="$(mktemp -d "${TMPDIR:-/tmp}/s284-selftest.XXXXXX")"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/deps/vendor" && : > "$work/deps/vendor/autoload.php"

cat > "$work/docker" <<'FAKE'
#!/usr/bin/env bash
echo "$*" >> "$FAKE_LOG"
case "$1" in
  network) exit 0 ;;
  rm) exit 0 ;;
  exec) if [[ "$3" == redis-cli ]]; then [[ "${FAKE_IGNORE_TERM:-0}" == 1 ]] && trap '' TERM; sleep "${FAKE_PONG_DELAY:-0}"; [[ "${FAKE_REDIS_READY:-1}" == 1 ]] && echo PONG; exit "${FAKE_PROBE_RC:-0}"; fi; echo "Redis server v=7.0.15"; exit 0 ;;
  run)
    for a in "$@"; do
      [[ "$a" == bash ]] && { echo "FAKE-HARNESS-RAN"; exit "${FAKE_HARNESS_RC:-0}"; }
      [[ "$a" == -d ]] && exit 0
    done
    printf '%s' "${FAKE_INT_SIZE:-8}"; exit 0 ;;
esac
exit 0
FAKE
cat > "$work/tee23" <<'FAKE'
#!/usr/bin/env bash
cat >/dev/null
exit 23
FAKE
chmod +x "$work/docker" "$work/tee23"

failures=0
expect() { # name expected_rc actual_rc
  if [[ "$2" == "$3" ]]; then echo "PASS $1 (exit $3)"; else echo "FAIL $1: expected exit $2, got $3"; failures=$((failures + 1)); fi
}
check() { # name condition-result description
  if [[ "$2" == yes ]]; then echo "PASS $1: $3"; else echo "FAIL $1: $3"; failures=$((failures + 1)); fi
}
run() { # width out -> sets rc; FAKE_* and S284_* come from the caller's environment
  FAKE_LOG="$work/calls.log" S284_DOCKER_BIN="$work/docker" "$runner" "$1" "$work/deps" "$2" >"$work/stdout.log" 2>&1
  rc=$?
}
reset() { : > "$work/calls.log"; }
names_cleaned() { # every name the run created is removed by that exact name
  local ok=yes n
  for n in $(grep -oE 's284-(redis-|probe-|php-)?[0-9]+-[0-9]+-[0-9]+(-[0-9]+)?' "$work/calls.log" | sort -u); do
    if [[ "$n" == s284-redis-* ]] || [[ "$n" == s284-probe-* ]] || [[ "$n" == s284-php-* ]]; then
      grep -qE "^rm -f ${n}\$" "$work/calls.log" || ok=no
    else
      grep -qE "^network rm ${n}\$" "$work/calls.log" || ok=no
    fi
  done
  echo "$ok"
}

# T1 happy path
reset; FAKE_INT_SIZE=8 run 64 "$work/o1"; expect T1_happy_64 0 "$rc"
check T1_evidence "$([[ -s "$work/o1/run-64bit.log" && "$(cat "$work/o1/exit-64bit.txt")" == 0 ]] && echo yes || echo no)" "log non-empty and exit file is 0"
check T1_cleanup "$(names_cleaned)" "every container and the network removed by its own name"

# T2 tee returns 23 -> evidence failure (7), never a pass
reset; FAKE_INT_SIZE=8 S284_TEE_BIN="$work/tee23" run 64 "$work/o2"; expect T2_tee_23 7 "$rc"
check T2_cleanup "$(names_cleaned)" "cleanup by name after a tee failure"

# T3 PHP_INT_SIZE mismatch: 32-bit requested, interpreter reports 8 -> 6, controls never started
reset; FAKE_INT_SIZE=8 run 32 "$work/o3"; expect T3_width_mismatch_32 6 "$rc"
check T3_not_started "$(grep -q 'FAKE-HARNESS-RAN' "$work/stdout.log" && echo no || echo yes)" "no control ran on the wrong width"
check T3_no_redis_started "$(grep -q 'run -d' "$work/calls.log" && echo no || echo yes)" "Redis not started on the wrong width"
reset; FAKE_INT_SIZE=4 run 64 "$work/o3b"; expect T3_width_mismatch_64 6 "$rc"

# T4 Redis never ready -> 5 within the bounded timeout
reset; start=$SECONDS; FAKE_INT_SIZE=8 FAKE_REDIS_READY=0 S284_REDIS_READY_TIMEOUT=2 run 64 "$work/o4"; elapsed=$((SECONDS - start)); expect T4_redis_timeout 5 "$rc"
check T4_bounded "$([[ "$elapsed" -le 10 ]] && echo yes || echo no)" "gave up after ${elapsed}s (bound 2s timeout)"
check T4_cleanup "$(names_cleaned)" "cleanup by name after a readiness timeout"

# T5 a failing control passes its own exit code through
reset; FAKE_INT_SIZE=8 FAKE_HARNESS_RC=1 run 64 "$work/o5"; expect T5_harness_failure 1 "$rc"
check T5_exit_file "$([[ "$(cat "$work/o5/exit-64bit.txt")" == 1 ]] && echo yes || echo no)" "exit file records 1"

# T6 evidence directory cannot be created -> 7
: > "$work/notadir"; reset; FAKE_INT_SIZE=8 run 64 "$work/notadir/sub"; expect T6_unwritable_out 7 "$rc"

# T7 usage
reset; FAKE_LOG="$work/calls.log" S284_DOCKER_BIN="$work/docker" "$runner" 16 "$work/deps" "$work/o7" >/dev/null 2>&1; expect T7_bad_width 2 "$?"
reset; FAKE_LOG="$work/calls.log" S284_DOCKER_BIN="$work/docker" "$runner" 64 "$work/missing" "$work/o7" >/dev/null 2>&1; expect T7_missing_deps 2 "$?"

# T8 two runs never share resource names
reset; FAKE_INT_SIZE=8 run 64 "$work/o8a"; a="$(grep -oE 's284-redis-64-[0-9]+-[0-9]+-[0-9]+' "$work/calls.log" | sort -u)"
reset; FAKE_INT_SIZE=8 run 64 "$work/o8b"; b="$(grep -oE 's284-redis-64-[0-9]+-[0-9]+-[0-9]+' "$work/calls.log" | sort -u)"
check T8_distinct_names "$([[ -n "$a" && -n "$b" && "$a" != "$b" ]] && echo yes || echo no)" "run A '$a' vs run B '$b'"

# T9 PONG that arrives AFTER the deadline (3s delay, 1s timeout) is a timeout (5), never a pass
reset; start=$SECONDS; FAKE_INT_SIZE=8 FAKE_PONG_DELAY=3 S284_REDIS_READY_TIMEOUT=1 run 64 "$work/o9"; elapsed=$((SECONDS - start)); expect T9_late_pong 5 "$rc"
check T9_no_controls "$(grep -q 'FAKE-HARNESS-RAN' "$work/stdout.log" && echo no || echo yes)" "no control ran after a late PONG"
check T9_cleanup "$(names_cleaned)" "cleanup by name after a late PONG"
# T10 a probe that hangs is cut at the deadline (bounded), exit 5
reset; start=$SECONDS; FAKE_INT_SIZE=8 FAKE_PONG_DELAY=30 S284_REDIS_READY_TIMEOUT=2 run 64 "$work/o10"; elapsed=$((SECONDS - start)); expect T10_hung_probe 5 "$rc"
check T10_bounded "$([[ "$elapsed" -le 10 ]] && echo yes || echo no)" "hung probe cut after ${elapsed}s (timeout 2s)"
# T10b a PONG within the deadline still passes (control for T9/T10)
reset; FAKE_INT_SIZE=8 FAKE_PONG_DELAY=1 S284_REDIS_READY_TIMEOUT=5 run 64 "$work/o10b"; expect T10b_timely_pong 0 "$rc"

# T17 PONG printed but the probe exits 19 -> not ready (5), never a pass
reset; FAKE_INT_SIZE=8 FAKE_PROBE_RC=19 S284_REDIS_READY_TIMEOUT=2 run 64 "$work/o17"; expect T17_pong_with_exit_19 5 "$rc"
check T17_no_controls "$(grep -q 'FAKE-HARNESS-RAN' "$work/stdout.log" && echo no || echo yes)" "no control ran when the readiness command exited non-zero"
# T18 a probe that ignores TERM (30s) with a 1s timeout is cut by KILL: total bounded (<= 4s incl. startup), exit 5
reset; start=$SECONDS; FAKE_INT_SIZE=8 FAKE_PONG_DELAY=30 FAKE_IGNORE_TERM=1 S284_REDIS_READY_TIMEOUT=1 run 64 "$work/o18"; elapsed=$((SECONDS - start)); expect T18_term_ignoring_probe 5 "$rc"
check T18_bounded "$([[ "$elapsed" -le 3 ]] && echo yes || echo no)" "TERM-ignoring probe cut after ${elapsed}s (timeout 1s; the previous runner took 4s)"
check T18_cleanup "$(names_cleaned)" "cleanup by name"

# T11..T14 container_check.sh with a fake git/php/sha256sum on PATH (the in-container source verification)
mkdir -p "$work/bin" "$work/study"; cp "$here/container_check.sh" "$work/study/"; echo "<?php" > "$work/study/k4_contract_controls.php"
cat > "$work/bin/git" <<'FAKE'
#!/usr/bin/env bash
[[ "$1" == --version ]] && { echo "git version fake"; exit 0; }
if [[ "$1" == clone ]]; then mkdir -p "${@: -1}"; exit 0; fi
[[ "$1" == -C ]] && shift 2
case "$1" in
  checkout) exit 0 ;;
  rev-parse) [[ "${FAKE_GIT_MODE:-}" == revparse_fail ]] && exit 128
             if [[ "$2" == HEAD ]]; then echo "${FAKE_HEAD:-109f3c6d7d81efda750a2d6d4be13eaa0eff55c6}"; else echo "${FAKE_TREE:-7ec62d35b97f675f33cbe66bb556de74bd82b085}"; fi ;;
  status) case "${FAKE_GIT_MODE:-}" in status_fail) echo "fatal: not a git repository" >&2; exit 128 ;; dirty) echo " M src/x.php" ;; esac ;;
  remote) echo "https://github.com/Maatify/php-rate-limiter.git" ;;
esac
exit 0
FAKE
cat > "$work/bin/php" <<'FAKE'
#!/usr/bin/env bash
[[ "$1" == -r ]] && { echo "PHP fake"; exit 0; }
echo "FAKE-CONTROLS-RAN"; exit "${FAKE_HARNESS_RC:-0}"
FAKE
printf '#!/usr/bin/env bash
echo "0000 -"
' > "$work/bin/sha256sum"
chmod +x "$work/bin/git" "$work/bin/php" "$work/bin/sha256sum"
cc() { # -> rc, output in cc.log
  PATH="$work/bin:$PATH" S284_WORK="$work/cw$RANDOM" S284_TMP="$work" S284_STUDY="$work/study" "$work/study/container_check.sh" 109f3c6d7d81efda750a2d6d4be13eaa0eff55c6 7ec62d35b97f675f33cbe66bb556de74bd82b085 >"$work/cc.log" 2>&1
  rc=$?
}
FAKE_GIT_MODE= cc; expect T11_clean_source 0 "$rc"
FAKE_GIT_MODE=status_fail cc; expect T12_git_status_fails_empty_output 4 "$rc"
check T12_not_run "$(grep -q 'FAKE-CONTROLS-RAN' "$work/cc.log" && echo no || echo yes)" "controls did not run when git status failed"
FAKE_GIT_MODE=dirty cc; expect T13_dirty_tree 4 "$rc"
FAKE_GIT_MODE=revparse_fail cc; expect T14_revparse_fails 4 "$rc"
FAKE_GIT_MODE= FAKE_HEAD=deadbeef cc; expect T15_wrong_head 4 "$rc"
FAKE_GIT_MODE= FAKE_HARNESS_RC=1 cc; expect T16_controls_rc_passthrough 1 "$rc"

echo "selftest_failures=$failures"
exit $((failures == 0 ? 0 : 1))
