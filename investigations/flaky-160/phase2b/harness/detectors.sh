#!/usr/bin/env bash
# #160 phase 2b — synthetic detectors (PLAN.md §7 step 2): one recorded passing run through run.sh (E-CI or
# E-LOCAL smoke), then detectors.php over copies of its record. $1 = out dir.
set -euo pipefail
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../../.." && pwd)"
out="$1"
mkdir -p "$out"
KEEP_ALL=1 bash "$here/run.sh" ref "$out/ref" | tee "$out/ref.verdict"
grep -q '"valid":true' "$out/ref.verdict" || { echo "detectors need a valid reference run" >&2; exit 1; }
env XDEBUG_MODE=off php "$here/detectors.php" --root="$root" --run="$out/ref/runs/ref-1/rec" --work="$out/work" | tee "$out/detectors.jsonl"
