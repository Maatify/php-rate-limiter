#!/usr/bin/env bash
# #181 S1 (PLAN §10, LT-181-08): the environment of one measurement, written to results/<M-id>/versions.txt.
#   versions.sh [redis-cli args…]   with arguments, also records the Redis server version they reach.
set -euo pipefail
root="$(git rev-parse --show-toplevel)"
echo "measured_sha $(git -C "$root" rev-parse HEAD)"
echo "main_sha $(git -C "$root" rev-parse origin/main)"
echo "src_diff_vs_main $(git -C "$root" diff --name-only origin/main HEAD -- src tests composer.json composer.lock | wc -l | tr -d ' ') files"
echo "worktree_dirty $(git -C "$root" status --porcelain -- src tests composer.json composer.lock | wc -l | tr -d ' ') files"
php -r 'echo "php ", PHP_VERSION, " ", PHP_OS_FAMILY, " ", php_uname("m"), "\n";'
echo "php_redis $(php -r 'echo phpversion("redis") ?: "absent";')"
if [[ $# -gt 0 ]]; then
    echo "redis_server $(redis-cli "$@" INFO server | tr -d '\r' | sed -n 's/^redis_version://p')"
fi
