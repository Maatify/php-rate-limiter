#!/usr/bin/env bash
# #157 resolved environment of one job (Lead condition 1 before arm L): the
# resolved composer.lock and the versions of the pinned PHPUnit and of PHP, kept
# in <out>/env/<label>/ so envset.php can say whether every job ran the same set.
# Under its own label because download-artifact merges the reference's env/ in.
#   env.sh <out> <label>          the checkout this harness lives in
#   H_ROOT set -> also h-head.composer.lock / h-head.phpunit-version (fe8775c)
set -euo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
dir="${1:?usage: env.sh <out> <label>}/env/${2:?usage: env.sh <out> <label>}"
mkdir -p "$dir"

record() { # $1 tree, $2 file prefix
    cp "$1/composer.lock" "$dir/$2composer.lock"
    env XDEBUG_MODE=off php "$1/vendor/bin/phpunit" --version > "$dir/$2phpunit-version"
}

record "$root" ''
env XDEBUG_MODE=off php -v > "$dir/php-version"
[[ -n "${H_ROOT:-}" ]] && record "$H_ROOT" 'h-head.'
ls "$dir"
