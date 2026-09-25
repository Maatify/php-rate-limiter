#!/usr/bin/env bash
set -euo pipefail

package_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture_root="$package_root/consumer-verification"

if [[ ! -f "$fixture_root/composer.json.template" || ! -f "$fixture_root/verify.php" ]]; then
    echo 'Consumer verification fixture is incomplete.' >&2
    exit 1
fi

bash "$package_root/scripts/ci/check-consumer-verification-boundary.sh"

# Each clean run executes as its own function invocation backgrounded with `&`,
# which bash forks into its own subshell process. That gives each run its own
# process-local trap table and its own local consumer_root, so the two runs
# cannot share or clobber each other's cleanup or temp state while running
# concurrently.
#
# Cleanup is explicit, not trap-driven: the EXIT trap below is only a
# best-effort safety net for an unexpected early termination (e.g. a signal)
# between creating consumer_root and reaching the explicit cleanup step. Bash
# does not guarantee that a failing EXIT trap changes the shell's exit status,
# so relying on the trap alone could let a real cleanup failure be reported as
# success. The explicit cleanup call below, and its captured status, are the
# sole authority for this run's cleanup outcome, and the trap is disarmed
# immediately afterwards so cleanup cannot run a second time.
#
# The verification steps themselves run in run-single-consumer-verification.sh
# as a separate bash process, not inlined here. Bash disables `errexit` for
# every command inside a compound command (a `{ }` group, a function body,
# etc.) that is itself the tested operand of `||`/`&&`/`if` in the CURRENT
# shell. Capturing this run's status with `|| verification_status=$?` would
# therefore silently disable fail-fast for every composer/php step if they
# were inlined in this same function, letting the run appear to pass after a
# real failure. A separate process is unaffected by that suppression, so its
# own `set -e` aborts on the first failing step and its real exit status is
# what `verification_status` captures below.
run_clean_consumer_verification() {
    local run_number="$1"
    local log_file="$2"
    local consumer_root
    consumer_root="$(mktemp -d "${TMPDIR:-/tmp}/maatify-rate-limiter-consumer.XXXXXX")"
    trap 'rm -rf -- "$consumer_root"' EXIT

    local verification_status=0
    bash "$package_root/scripts/ci/run-single-consumer-verification.sh" \
        "$run_number" "$consumer_root" "$fixture_root" "$package_root" \
        >"$log_file" 2>&1 || verification_status=$?

    local cleanup_status=0
    rm -rf -- "$consumer_root" >>"$log_file" 2>&1 || cleanup_status=$?
    trap - EXIT

    {
        if (( verification_status == 0 )); then
            echo "Consumer Verification Harness clean run #$run_number verification: PASS"
        else
            echo "Consumer Verification Harness clean run #$run_number verification: FAIL (status $verification_status)"
        fi
        if (( cleanup_status == 0 )); then
            echo "Consumer Verification Harness clean run #$run_number cleanup: PASS"
        else
            echo "Consumer Verification Harness clean run #$run_number cleanup: FAIL (status $cleanup_status)"
        fi
    } >>"$log_file" 2>&1

    if (( verification_status != 0 )); then
        # The original verification failure is authoritative: a cleanup
        # outcome, either way, must not hide it.
        return "$verification_status"
    fi
    if (( cleanup_status != 0 )); then
        return "$cleanup_status"
    fi
    return 0
}

log_dir="$(mktemp -d "${TMPDIR:-/tmp}/maatify-rate-limiter-consumer-logs.XXXXXX")"
# Best-effort safety net only, same rationale as the per-run trap above; the
# explicit cleanup below is what actually determines pass/fail.
trap 'rm -rf -- "$log_dir"' EXIT

run_clean_consumer_verification 1 "$log_dir/run-1.log" &
pid_1=$!
run_clean_consumer_verification 2 "$log_dir/run-2.log" &
pid_2=$!

status_1=0
wait "$pid_1" || status_1=$?
status_2=0
wait "$pid_2" || status_2=$?

echo '=== Consumer Verification Harness clean run #1 log ==='
cat -- "$log_dir/run-1.log"
echo '=== Consumer Verification Harness clean run #2 log ==='
cat -- "$log_dir/run-2.log"

log_dir_cleanup_status=0
rm -rf -- "$log_dir" || log_dir_cleanup_status=$?
trap - EXIT

if (( status_1 != 0 )); then
    echo "Consumer Verification Harness clean run #1 failed with status $status_1." >&2
fi
if (( status_2 != 0 )); then
    echo "Consumer Verification Harness clean run #2 failed with status $status_2." >&2
fi
if (( log_dir_cleanup_status != 0 )); then
    echo "Consumer Verification Harness log directory cleanup failed with status $log_dir_cleanup_status." >&2
fi
if (( status_1 != 0 || status_2 != 0 || log_dir_cleanup_status != 0 )); then
    exit 1
fi

echo 'Consumer Verification Harness passed twice from clean consumer states.'
