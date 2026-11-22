#!/usr/bin/env bash
# #284: independent controls for the runner (run_rehearsal.sh). A fake `docker` records every call; nothing real is started.
# Each case states its input and the exit code the runner MUST return. Exit 0 only when every case holds.
set -uo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
runner="$here/run_rehearsal.sh"
work="$(mktemp -d "${TMPDIR:-/tmp}/s284r-selftest.XXXXXX")"
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
      if [[ "$a" == bash ]]; then
        echo "FAKE-HARNESS-RAN"
        mnt=""; for b in "$@"; do [[ "$b" == *:/out ]] && mnt="${b%%:/out}"; done
        mode="${FAKE_RESULTS:-ok}"
        if [[ -n "$mnt" && "$mode" != missing ]]; then
          comp=true; [[ "$mode" == incomplete ]] && comp=false
          printf '{\n    "control_failures": 0,\n    "complete": %s,\n    "scenarios": {"A_login_protection_own_ns": {}}\n}' "$comp" > "$mnt/rehearsal-results.json"
          sha="$( (command -v sha256sum >/dev/null && sha256sum "$mnt/rehearsal-results.json" || shasum -a 256 "$mnt/rehearsal-results.json") | cut -d' ' -f1)"
          [[ "$mode" == badsha ]] && sha="$(printf '%064d' 0)"
          ver=true; [[ "$mode" == unverified ]] && ver=false
          echo "{\"tag\":\"results_file\",\"saved_and_verified\":$ver,\"complete\":$comp,\"results_sha256\":\"$sha\"}"
        fi
        exit "${FAKE_HARNESS_RC:-0}"
      fi
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
acc_dir() { # the run directory the accepted pointer names
  local ptr="$1/ACCEPTED-${2:-64}bit.json"; [[ -s "$ptr" ]] || { echo ""; return; }
  echo "$1/$(grep -o '"run":"[^"]*"' "$ptr" | cut -d'"' -f4)"
}
run() { # width out -> sets rc; FAKE_* and S284_* come from the caller's environment
  FAKE_LOG="$work/calls.log" S284_DOCKER_BIN="$work/docker" "$runner" "$1" "$work/deps" "$2" >"$work/stdout.log" 2>&1
  rc=$?
}
reset() { : > "$work/calls.log"; }
names_cleaned() { # every name the run created is removed by that exact name
  local ok=yes n
  for n in $(grep -oE 's284r-(redis-|probe-|php-)?[0-9]+-[0-9]+-[0-9]+(-[0-9]+)?' "$work/calls.log" | sort -u); do
    if [[ "$n" == s284r-redis-* ]] || [[ "$n" == s284r-probe-* ]] || [[ "$n" == s284r-php-* ]]; then
      grep -qE "^rm -f ${n}\$" "$work/calls.log" || ok=no
    else
      grep -qE "^network rm ${n}\$" "$work/calls.log" || ok=no
    fi
  done
  echo "$ok"
}

# T1 happy path
reset; FAKE_INT_SIZE=8 run 64 "$work/o1"; expect T1_happy_64 0 "$rc"
u1="$(acc_dir "$work/o1")"; check T1_evidence "$([[ -n "$u1" && -s "$u1/run-64bit.log" && "$(cat "$u1/exit-64bit.txt")" == 0 && -s "$u1/MANIFEST.sha256" ]] && echo yes || echo no)" "the pointer names a run dir with a non-empty log, exit file 0 and a manifest"
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
check T5_rejected_kept "$([[ -n "$(ls "$work/o5/rejected" 2>/dev/null)" && ! -e "$work/o5/ACCEPTED-64bit.json" ]] && echo yes || echo no)" "a failing run is kept under rejected/ and writes no accepted exit file"

# T6 evidence directory cannot be created -> 7
: > "$work/notadir"; reset; FAKE_INT_SIZE=8 run 64 "$work/notadir/sub"; expect T6_unwritable_out 7 "$rc"

# T7 usage
reset; FAKE_LOG="$work/calls.log" S284_DOCKER_BIN="$work/docker" "$runner" 16 "$work/deps" "$work/o7" >/dev/null 2>&1; expect T7_bad_width 2 "$?"
reset; FAKE_LOG="$work/calls.log" S284_DOCKER_BIN="$work/docker" "$runner" 64 "$work/missing" "$work/o7" >/dev/null 2>&1; expect T7_missing_deps 2 "$?"

# T8 two runs never share resource names
reset; FAKE_INT_SIZE=8 run 64 "$work/o8a"; a="$(grep -oE 's284r-redis-64-[0-9]+-[0-9]+-[0-9]+' "$work/calls.log" | sort -u)"
reset; FAKE_INT_SIZE=8 run 64 "$work/o8b"; b="$(grep -oE 's284r-redis-64-[0-9]+-[0-9]+-[0-9]+' "$work/calls.log" | sort -u)"
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
mkdir -p "$work/bin" "$work/study"; cp "$here/container_check.sh" "$work/study/"; echo "<?php" > "$work/study/rehearsal.php"
cat > "$work/bin/git" <<'FAKE'
#!/usr/bin/env bash
[[ "$1" == --version ]] && { echo "git version fake"; exit 0; }
if [[ "$1" == clone ]]; then mkdir -p "${@: -1}"; exit 0; fi
[[ "$1" == -C ]] && shift 2
case "$1" in
  checkout) exit 0 ;;
  rev-parse) [[ "${FAKE_GIT_MODE:-}" == revparse_fail ]] && exit 128
             if [[ "$2" == HEAD ]]; then echo "${FAKE_HEAD:-ca626bf9d6f6216036321c79bd8216df98d70b75}"; else echo "${FAKE_TREE:-b8bf598ffeceb8338744d5de3005878d43b799dc}"; fi ;;
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
  PATH="$work/bin:$PATH" S284_WORK="$work/cw$RANDOM" S284_TMP="$work" S284_STUDY="$work/study" "$work/study/container_check.sh" ca626bf9d6f6216036321c79bd8216df98d70b75 b8bf598ffeceb8338744d5de3005878d43b799dc controller "$work/cco" >"$work/cc.log" 2>&1
  rc=$?
}
FAKE_GIT_MODE= cc; expect T11_clean_source 0 "$rc"
FAKE_GIT_MODE=status_fail cc; expect T12_git_status_fails_empty_output 4 "$rc"
check T12_not_run "$(grep -q 'FAKE-CONTROLS-RAN' "$work/cc.log" && echo no || echo yes)" "controls did not run when git status failed"
FAKE_GIT_MODE=dirty cc; expect T13_dirty_tree 4 "$rc"
FAKE_GIT_MODE=revparse_fail cc; expect T14_revparse_fails 4 "$rc"
FAKE_GIT_MODE= FAKE_HEAD=deadbeef cc; expect T15_wrong_head 4 "$rc"
FAKE_GIT_MODE= FAKE_HARNESS_RC=1 cc; expect T16_controls_rc_passthrough 1 "$rc"

# T24..T30 evidence integrity: accepted evidence is never replaced by a rejected run
seed_old() { mkdir -p "$1/runs/OLD-64bit"; echo OLD > "$1/runs/OLD-64bit/run-64bit.log"; echo 0 > "$1/runs/OLD-64bit/exit-64bit.txt"; echo OLD > "$1/runs/OLD-64bit/rehearsal-results.json"; echo '{"width":64,"run":"runs/OLD-64bit"}' > "$1/ACCEPTED-64bit.json"; }
old_intact() { [[ "$(cat "$1/runs/OLD-64bit/run-64bit.log")" == OLD && "$(cat "$1/runs/OLD-64bit/exit-64bit.txt")" == 0 && "$(cat "$1/runs/OLD-64bit/rehearsal-results.json")" == OLD && "$(cat "$1/ACCEPTED-64bit.json")" == '{"width":64,"run":"runs/OLD-64bit"}' && "$(ls "$1/runs" | wc -l | tr -d ' ')" == 1 ]] && echo yes || echo no; }
seed_old "$work/o17"; reset; FAKE_INT_SIZE=4 run 64 "$work/o17"; expect T24_width_mismatch_rejected 6 "$rc"
check T24_old_evidence_intact "$(old_intact "$work/o17")" "previous accepted evidence untouched after a rejected run"
check T24_rejected_run_kept "$([[ -n "$(ls "$work/o17/rejected" 2>/dev/null)" ]] && echo yes || echo no)" "the rejected run is kept under rejected/"
seed_old "$work/o18"; reset; FAKE_INT_SIZE=8 FAKE_RESULTS=missing run 64 "$work/o18"; expect T25_results_missing 7 "$rc"
check T25_old_evidence_intact "$(old_intact "$work/o18")" "old evidence intact when the results file is missing"
seed_old "$work/o19"; reset; FAKE_INT_SIZE=8 FAKE_RESULTS=badsha run 64 "$work/o19"; expect T26_results_sha_mismatch 7 "$rc"
check T26_old_evidence_intact "$(old_intact "$work/o19")" "old evidence intact when the results sha256 differs from the logged one"
seed_old "$work/o20"; reset; FAKE_INT_SIZE=8 FAKE_RESULTS=unverified run 64 "$work/o20"; expect T27_results_not_verified 7 "$rc"
check T27_old_evidence_intact "$(old_intact "$work/o20")" "old evidence intact when the harness did not verify its own save"
seed_old "$work/o21"; reset; FAKE_INT_SIZE=8 FAKE_HARNESS_RC=1 run 64 "$work/o21"; expect T28_control_failure_rejected 1 "$rc"
check T28_old_evidence_intact "$(old_intact "$work/o21")" "old evidence intact when a control failed"
reset; FAKE_INT_SIZE=8 run 64 "$work/o22"; expect T29_good_run_promotes 0 "$rc"
u22="$(acc_dir "$work/o22")"; check T29_promoted "$([[ -n "$u22" && -s "$u22/run-64bit.log" && -s "$u22/rehearsal-results.json" && "$(cat "$u22/exit-64bit.txt")" == 0 && ! -d "$work/o22/rejected" && ! -e "$work/o22/run-64bit.log" ]] && echo yes || echo no)" "log, results and exit file promoted; nothing rejected"
reset; FAKE_INT_SIZE=4 run 32 "$work/o23"; expect T30_32bit_needs_no_results 0 "$rc"

# T31..T35 (F291-05): a partial run, an incomplete run, a failed pointer flip and a second good run never mix evidence
seed_old "$work/o31"; reset; FAKE_INT_SIZE=8 S284_SCENARIOS=A run 64 "$work/o31"; expect T31_partial_run_never_promoted 7 "$rc"
check T31_old_evidence_intact "$(old_intact "$work/o31")" "a partial run leaves accepted evidence untouched (and the pointer unchanged)"
seed_old "$work/o32"; reset; FAKE_INT_SIZE=8 FAKE_RESULTS=incomplete run 64 "$work/o32"; expect T32_incomplete_run_rejected 7 "$rc"
check T32_old_evidence_intact "$(old_intact "$work/o32")" "an incomplete run (not every expected scenario) leaves accepted evidence untouched"
seed_old "$work/o33"; rm -f "$work/o33/ACCEPTED-64bit.json"; mkdir -p "$work/o33/ACCEPTED-64bit.json"; reset; FAKE_INT_SIZE=8 run 64 "$work/o33"; expect T33_pointer_flip_failure 7 "$rc"
check T33_old_run_and_pointer_slot_intact "$([[ -d "$work/o33/ACCEPTED-64bit.json" && "$(cat "$work/o33/runs/OLD-64bit/run-64bit.log")" == OLD && -z "$(ls -A "$work/o33/ACCEPTED-64bit.json")" ]] && echo yes || echo no)" "the pointer slot was not touched (nothing moved into it) and the old run is intact"
check T33_new_unit_parked "$([[ -n "$(ls "$work/o33/rejected" 2>/dev/null | grep -- '-rc7')" && "$(ls "$work/o33/runs" | wc -l | tr -d ' ')" == 1 ]] && echo yes || echo no)" "the moved unit was parked under rejected/, not left half-accepted in runs/"
check T33_no_temp_pointer_left "$([[ -z "$(ls -A "$work/o33" | grep '^\.ACCEPTED' )" ]] && echo yes || echo no)" "the unique temporary pointer file was removed"
reset; FAKE_INT_SIZE=8 run 64 "$work/o34"; u34a="$(acc_dir "$work/o34")"; sleep 1; reset; FAKE_INT_SIZE=8 run 64 "$work/o34"; u34b="$(acc_dir "$work/o34")"
check T34_second_good_run_is_a_separate_unit "$([[ -n "$u34a" && -n "$u34b" && "$u34a" != "$u34b" && -s "$u34a/run-64bit.log" && -s "$u34b/run-64bit.log" ]] && echo yes || echo no)" "the first accepted run stays intact; the pointer names the second"

# T35..T37 (F291-05): identity collisions and concurrency. rename(2), unique run identity, unique temporary pointer.
reset; FAKE_INT_SIZE=8 S284_RUN_SUFFIX=fixed S284_NOW=20261122T000000Z run 64 "$work/o35"; expect T35_first_run_with_forced_identity 0 "$rc"
u35="$(acc_dir "$work/o35")"; first_sum="$(cat "$u35/MANIFEST.sha256")"; ptr_before="$(cat "$work/o35/ACCEPTED-64bit.json")"
reset; FAKE_INT_SIZE=8 S284_RUN_SUFFIX=fixed S284_NOW=20261122T000000Z run 64 "$work/o35"; expect T35_second_run_same_second_same_identity_is_rejected 7 "$rc"
check T35_first_unit_intact_and_not_nested "$([[ "$(cat "$u35/MANIFEST.sha256")" == "$first_sum" && "$(cat "$work/o35/ACCEPTED-64bit.json")" == "$ptr_before" && -z "$(find "$u35" -mindepth 1 -type d)" && "$(ls "$work/o35/runs" | wc -l | tr -d ' ')" == 1 ]] && echo yes || echo no)" "the first accepted run is untouched, nothing was nested inside it, the pointer is unchanged"
rm -rf "$work/o36"; mkdir -p "$work/o36"
( FAKE_LOG="$work/c1.log" FAKE_INT_SIZE=8 S284_DOCKER_BIN="$work/docker" "$runner" 64 "$work/deps" "$work/o36" >/dev/null 2>&1; echo $? > "$work/c1.rc" ) &
( FAKE_LOG="$work/c2.log" FAKE_INT_SIZE=8 S284_DOCKER_BIN="$work/docker" "$runner" 64 "$work/deps" "$work/o36" >/dev/null 2>&1; echo $? > "$work/c2.rc" ) &
wait
expect T36_concurrent_runs_both_accepted 0 "$(( $(cat "$work/c1.rc") + $(cat "$work/c2.rc") ))"
u36="$(acc_dir "$work/o36")"; ok36=yes
[[ "$(ls "$work/o36/runs" | wc -l | tr -d ' ')" == 2 ]] || ok36=no
while read -r sum name; do [[ "$( (command -v sha256sum >/dev/null && sha256sum "$u36/$name" || shasum -a 256 "$u36/$name") | cut -d' ' -f1)" == "$sum" ]] || ok36=no; done < "$u36/MANIFEST.sha256"
[[ -z "$(ls -A "$work/o36" | grep '^\.ACCEPTED')" ]] || ok36=no
check T36_two_distinct_units_and_a_valid_pointer "$ok36" "two separate run directories, the pointer names one whose manifest verifies, no temporary pointer files left"

echo "selftest_failures=$failures"
exit $((failures == 0 ? 0 : 1))
