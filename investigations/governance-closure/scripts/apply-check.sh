#!/usr/bin/env bash
# #181 S1 (Lead ruling on F-181-02): a committed provenance.patch, whose blank context lines lost their single space for
# check-whitespace.sh, still applies to the measured tree and reproduces the worktree diff.
#   apply-check.sh <clean-worktree-at-measured-sha> <provenance.patch>
# Prints the HEAD, `git apply --check`, and whether `git diff` after `git apply` equals the patch once the same blank
# context lines are stripped; then restores the worktree. Exit non-zero on any failure.
set -euo pipefail
wt="${1:?usage: apply-check.sh <worktree> <patch>}"
patch="$(cd -- "$(dirname -- "${2:?usage: apply-check.sh <worktree> <patch>}")" && pwd)/$(basename -- "$2")"
cd "$wt"
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || { echo "worktree not clean"; exit 3; }
trap 'git checkout -q -- .' EXIT
echo "HEAD $(git rev-parse HEAD)"
git apply --check -v "$patch" 2>&1
echo "git apply --check: exit 0"
git apply "$patch"
git diff --stat
git diff | sed -E 's/^ $//' | cmp - "$patch"
echo "git diff after apply, blank context lines stripped: byte-identical to the patch"
