#!/usr/bin/env bash
# #181 S1 (PLAN §10, LT-181-08): run one command from the repository root and record it under results/<M-id>/.
#
#   record.sh <M-id> <name> <command…>
#
# Writes <name>.command.txt, <name>.stdout.txt (stdout and stderr together) and <name>.exit.txt; never fails on the
# command's exit code, which is the recorded result.
set -euo pipefail
id="${1:?usage: record.sh <M-id> <name> <command…>}"
name="${2:?usage: record.sh <M-id> <name> <command…>}"
shift 2
root="$(git rev-parse --show-toplevel)"
out="$root/investigations/governance-closure/results/$id"
mkdir -p "$out"
printf '%q ' "$@" | sed 's/ $//' > "$out/$name.command.txt"
echo >> "$out/$name.command.txt"
set +e
(cd "$root" && "$@") > "$out/$name.stdout.txt" 2>&1
echo $? > "$out/$name.exit.txt"
set -e
echo "$id/$name: exit $(cat "$out/$name.exit.txt")"
