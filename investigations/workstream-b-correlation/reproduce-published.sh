#!/usr/bin/env bash
# Re-runs the whole evidence suite against a PUBLISHED / pinned package tree and compares with the same expected/ files.
#   bash investigations/workstream-b-correlation/reproduce-published.sh v1.0.0-rc.3
#   bash investigations/workstream-b-correlation/reproduce-published.sh 7b6a23d283dcf6e4d3afc79f64404e2630a30577
# Uses `git archive` of the exact ref into a fresh temp directory (no checkout, no moving refs, no network except composer).
set -euo pipefail
ref=${1:?usage: reproduce-published.sh <tag-or-commit>}
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/wsb-published.XXXXXX")
trap 'rm -rf "$work"' EXIT
git -C "$repo" rev-parse --verify "$ref^{commit}" >/dev/null
echo "ref: $ref -> $(git -C "$repo" rev-parse "$ref^{commit}")"
git -C "$repo" archive "$ref" | tar -x -C "$work"
(cd "$work" && composer install --no-interaction --prefer-dist --no-progress -q)
# The harness lives in the evidence PR, not in the archived ref: run it against the archived package tree.
LAB_ROOT="$work" REDIS_SPOTCHECK=${REDIS_SPOTCHECK:-0} bash "$here/run.sh"
