#!/usr/bin/env bash
# #260 investigation runner. Usage (repository root): bash investigations/fiber-request-ownership-260/run.sh [output-dir]
# Runs the governance helper (Docker, an isolated temporary clone of HEAD) and the Fiber suite on actual PHP_INT_SIZE 8 and 4 against an
# isolated Redis. Every step, including the identification of the tested source, keeps its raw output and exit status; the script exits 1
# if any required step failed (a red run, or a run whose source cannot be identified, is never reported as green).
# FIBER260_GIT overrides the git executable (used by the provenance-failure control); FIBER260_TEST overrides the test paths, space
# separated (used by the missing-test control).
set -uo pipefail
root=$(pwd)
git_bin=${FIBER260_GIT:-git}
out=${1:-"$root/investigations/fiber-request-ownership-260/raw"}
mkdir -p "$out"
: > "$out/result.txt"
failed=0
step() { # name, command...
    local name=$1; shift
    "$@" > "$out/$name.log" 2>&1
    local status=$?
    printf '%s\t%s\n' "$name" "$status" >> "$out/result.txt"
    sed -i.bak -e 's/[[:space:]]*$//' "$out/$name.log" && rm -f "$out/$name.log.bak"
    if [ "$status" -ne 0 ]; then failed=1; fi
}
suite() { # width, phpunit args
    local w=$1; shift
    local sfx="$$-$RANDOM" img=maatify-rl-php64:local plat=linux/arm64
    if [ "$w" = 32 ]; then img=maatify-rl-php32:local; plat=linux/386; fi
    local net="rl260-$sfx" rd="rl260-redis-$sfx" st=0
    docker network create "$net" > /dev/null || return 1
    docker run -d --name "$rd" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no > /dev/null || { docker network rm "$net" > /dev/null 2>&1; return 1; }
    until [ "$(docker exec "$rd" redis-cli ping 2>/dev/null)" = PONG ]; do sleep 0.3; done
    docker run --rm --platform "$plat" --network "$net" -v "$root:/app" -w /app -e REDIS_INTEGRATION_HOST="$rd" -e REDIS_INTEGRATION_PORT=6379 "$img" \
        sh -c 'php -r "echo \"PHP \",PHP_VERSION,\" INT=\",PHP_INT_SIZE,\" redis=\",phpversion(\"redis\"),\"\n\";"; php -d memory_limit=1G vendor/bin/phpunit --fail-on-empty-test-suite "$@"' sh "$@"
    st=$?
    docker rm -fv "$rd" > /dev/null 2>&1; docker network rm "$net" > /dev/null 2>&1
    return $st
}
# The source identity: two lines, a commit and its tree, each a 40-hex object name.
valid_identity() { # file
    [ "$(wc -l < "$1" | tr -d ' ')" = 2 ] && grep -Eqx '[0-9a-f]{40}' <(sed -n 1p "$1") && grep -Eqx '[0-9a-f]{40}' <(sed -n 2p "$1")
}
tmp=$(mktemp -d "${TMPDIR:-/tmp}/rl260-run.XXXXXX") || { echo "cannot create a temporary directory" >&2; exit 1; }
trap 'rm -rf "$tmp"' EXIT
step source-identity "$git_bin" -C "$root" rev-parse HEAD 'HEAD^{tree}'
if ! valid_identity "$out/source-identity.log"; then
    printf '%s\t%s\n' source-identity-valid 1 >> "$out/result.txt"; failed=1
fi
sha=$(sed -n 1p "$out/source-identity.log"); tree=$(sed -n 2p "$out/source-identity.log")
# The suites run on the working tree: it must be the committed source (only this runner's own output may differ).
# The status command's own failure is kept: an empty output proves a clean tree only when the command succeeded.
step working-tree-clean bash -c 'changes=$("$1" -C "$2" status --porcelain -- . ":!investigations/fiber-request-ownership-260/raw*") || exit $?; test -z "$changes"' _ "$git_bin" "$root"
step clone "$git_bin" clone -q "$root" "$tmp/clone"
step checkout "$git_bin" -C "$tmp/clone" checkout -q "$sha"
step clone-identity "$git_bin" -C "$tmp/clone" rev-parse HEAD 'HEAD^{tree}'
if ! cmp -s "$out/source-identity.log" "$out/clone-identity.log" || ! valid_identity "$out/clone-identity.log"; then
    printf '%s\t%s\n' clone-matches-source 1 >> "$out/result.txt"; failed=1
fi
provenance_failed=$failed
step governance-helper docker run --rm -v "$tmp/clone:/repo" -w /repo maatify-rl-php64:local php .agents/helpers/maatify-governance-context.php
step suite-php64 suite 64 --testdox ${FIBER260_TEST:-tests/Integration/Redis/FiberRequestOwnershipRedisTest.php tests/Integration/Redis/ExecutionContextProviderRedisTest.php tests/Unit/Service/OwnershipRegistryTest.php}
step suite-php32 suite 32 --testdox ${FIBER260_TEST:-tests/Integration/Redis/FiberRequestOwnershipRedisTest.php tests/Integration/Redis/ExecutionContextProviderRedisTest.php tests/Unit/Service/OwnershipRegistryTest.php}
# The tested source is recorded only when it was identified AND proven to be the committed source (clean tree, clone identical).
if [ "$provenance_failed" = 0 ] && valid_identity "$out/source-identity.log"; then cp "$out/source-identity.log" "$out/source.txt"; else : > "$out/source.txt"; fi
if [ ! -s "$out/source.txt" ]; then printf '%s\t%s\n' source-recorded 1 >> "$out/result.txt"; failed=1; fi
cat "$out/result.txt"
exit "$failed"
