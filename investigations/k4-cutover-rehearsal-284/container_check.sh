#!/usr/bin/env bash
# #284 rehearsal: the part of a run that executes INSIDE the PHP container: clean clone of the official remote, pinned-base verification, then the controls.
# Usage: container_check.sh <base_sha> <base_tree> <controller|int> [out-dir] <controller|int> [out-dir]
# Exit codes: 0 controls held; 3 clone/checkout failed; 4 source not provably the pinned clean base (SHA, tree, git failure, empty output or dirty files);
#             otherwise the harness's own exit code. A git command that fails or prints nothing is NEVER read as "clean".
# Test-only overrides (selftest.sh): S284_WORK (clone dir, default /work), S284_TMP (default /tmp), S284_STUDY (default /study).
set -uo pipefail

base_sha="${1:?base sha}"; base_tree="${2:?base tree}"; run_mode="${3:?mode}"; out_dir="${4:-/out}"
W="${S284_WORK:-/work}"; T="${S284_TMP:-/tmp}"; S="${S284_STUDY:-/study}"

echo "== php container =="; git --version
php -r 'echo "PHP ", PHP_VERSION, " PHP_INT_SIZE=", PHP_INT_SIZE, " ext-redis=", phpversion("redis"), PHP_EOL;'

git clone -q https://github.com/Maatify/php-rate-limiter.git "$W" && git -C "$W" checkout -q "$base_sha" || { echo "CLONE FAILED"; exit 3; }

head="$(git -C "$W" rev-parse HEAD)" || { echo "GIT REV-PARSE HEAD FAILED"; exit 4; }
tree="$(git -C "$W" rev-parse 'HEAD^{tree}')" || { echo "GIT REV-PARSE TREE FAILED"; exit 4; }
status_out="$(git -C "$W" status --porcelain)" || { echo "GIT STATUS FAILED"; exit 4; }
remote="$(git -C "$W" remote get-url origin)" || { echo "GIT REMOTE FAILED"; exit 4; }
[[ -n "$head" && -n "$tree" ]] || { echo "GIT IDENTITY EMPTY"; exit 4; }
dirty="$(printf '%s' "$status_out" | grep -c . || true)"
echo "source HEAD=$head tree=$tree dirty_files=$dirty remote=$remote"
[[ "$head" == "$base_sha" && "$tree" == "$base_tree" && "$dirty" == 0 ]] || { echo "BASE MISMATCH"; exit 4; }

echo "harness_sha256=$(sha256sum "$S/rehearsal.php" | cut -d' ' -f1)"
cp "$S/rehearsal.php" "$T/rehearsal.php" || { echo "HARNESS COPY FAILED"; exit 4; }
S284_ROOT="$W" php "$T/rehearsal.php" "$run_mode" "$out_dir"; rc=$?
echo "harness_exit_code=$rc"
exit "$rc"
