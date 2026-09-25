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
run_clean_consumer_verification() {
    local run_number="$1"
    local log_file="$2"
    local consumer_root
    consumer_root="$(mktemp -d "${TMPDIR:-/tmp}/maatify-rate-limiter-consumer.XXXXXX")"
    trap 'rm -rf -- "$consumer_root"' EXIT

    {
        echo "Consumer Verification Harness clean run #$run_number starting at $(date -u +%FT%TZ)"

        cp -R "$fixture_root/." "$consumer_root/"
        sed "s|__PACKAGE_ROOT__|$package_root|g" \
            "$consumer_root/composer.json.template" > "$consumer_root/composer.json"

        bash "$package_root/scripts/ci/run-with-redis-service.sh" bash -c '
            cd "$1"
            echo "Consumer Verification Harness clean run #$2"
            # The external fixture intentionally accepts any detached development ref from its path repository.
            composer validate --strict --no-check-all
            composer update --no-interaction --prefer-dist --no-progress
            composer dump-autoload --optimize --strict-psr
            composer check-platform-reqs
            composer show maatify/php-rate-limiter
            php verify.php
        ' _ "$consumer_root" "$run_number"

        echo "Consumer Verification Harness clean run #$run_number finished at $(date -u +%FT%TZ)"
    } >"$log_file" 2>&1
}

log_dir="$(mktemp -d "${TMPDIR:-/tmp}/maatify-rate-limiter-consumer-logs.XXXXXX")"
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

if (( status_1 != 0 )); then
    echo "Consumer Verification Harness clean run #1 failed with status $status_1." >&2
fi
if (( status_2 != 0 )); then
    echo "Consumer Verification Harness clean run #2 failed with status $status_2." >&2
fi
if (( status_1 != 0 || status_2 != 0 )); then
    exit 1
fi

echo 'Consumer Verification Harness passed twice from clean consumer states.'
