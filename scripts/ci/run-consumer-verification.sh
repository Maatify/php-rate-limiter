#!/usr/bin/env bash
set -euo pipefail

package_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture_root="$package_root/consumer-verification"

if [[ ! -f "$fixture_root/composer.json.template" || ! -f "$fixture_root/verify.php" ]]; then
    echo 'Consumer verification fixture is incomplete.' >&2
    exit 1
fi

for run_number in 1 2; do
    consumer_root="$(mktemp -d "${TMPDIR:-/tmp}/maatify-rate-limiter-consumer.XXXXXX")"
    cleanup() {
        rm -rf -- "$consumer_root"
    }
    trap cleanup EXIT

    cp -R "$fixture_root/." "$consumer_root/"
    sed "s|__PACKAGE_ROOT__|$package_root|g" \
        "$consumer_root/composer.json.template" > "$consumer_root/composer.json"

    (
        cd "$consumer_root"
        echo "Consumer Verification Harness clean run #$run_number"
        # The external fixture intentionally accepts any detached development ref from its path repository.
        composer validate --strict --no-check-all
        composer update --no-interaction --prefer-dist --no-progress
        composer dump-autoload --optimize --strict-psr
        composer check-platform-reqs
        composer show maatify/php-rate-limiter
        php verify.php
    )

    cleanup
    trap - EXIT
done

echo 'Consumer Verification Harness passed twice from clean consumer states.'
