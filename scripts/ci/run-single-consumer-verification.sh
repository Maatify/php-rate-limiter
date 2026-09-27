#!/usr/bin/env bash
set -euo pipefail

# Runs one clean consumer verification attempt into an already-prepared,
# already-isolated consumer root. This body MUST run as its own bash process
# (not a `{ }` brace group or `( )` subshell inside a caller that tests this
# script's exit status with `||`): bash suspends `errexit` for every command
# inside a compound command that is itself the tested operand of `||`/`&&`/
# `if`, so a caller that inlined this logic in such a construct would keep
# running past a failed `composer`/`php` step instead of stopping and
# reporting the real failure. A separate process has its own `errexit`
# behavior that the caller's `||` cannot reach into, so failures here abort
# immediately and this script's exit status is always the true result.

if (( $# != 4 )); then
    echo 'Usage: run-single-consumer-verification.sh run_number consumer_root fixture_root package_root' >&2
    exit 2
fi

run_number="$1"
consumer_root="$2"
fixture_root="$3"
package_root="$4"

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
