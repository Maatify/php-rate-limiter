#!/usr/bin/env bash
# #176 (PLAN §3.3, §8 LT-176-04/07): collect one Stability Evidence run after it has completed.
#
#   collect.sh <run_id>
#
# Writes, under investigations/stability-release/data/runs/:
#   <run_id>.run.json            gh api …/actions/runs/<run_id>
#   <run_id>.jobs.json           every job of attempt 1 (gh api --paginate …/attempts/1/jobs), one JSON array
#   <run_id>/env/<job_id>.txt    environment lines of each job that resolves dependencies (composer "Locking …",
#                                "Installed PHP x.y.z" from setup-php, PHPUnit's "Runtime: PHP" and version lines), from that job's log
#   <run_id>/failed/<job_id>.log.gz  the full log of each failed job (gzip -n -9)
set -euo pipefail

run="${1:?usage: collect.sh <run_id>}"
repo=Maatify/php-rate-limiter
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
out="$here/../data/runs"
mkdir -p "$out/$run/env" "$out/$run/failed"

status="$(gh api "repos/$repo/actions/runs/$run" --jq .status)"
[[ "$status" == completed ]] || { echo "run $run is $status, not completed" >&2; exit 1; }
gh api "repos/$repo/actions/runs/$run" > "$out/$run.run.json"
gh api --paginate "repos/$repo/actions/runs/$run/attempts/1/jobs?per_page=100" --jq '.jobs[]' \
    | php -r '$rows = []; while (($l = fgets(STDIN)) !== false) { $rows[] = json_decode($l, true, flags: JSON_THROW_ON_ERROR); }
              echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";' > "$out/$run.jobs.json"

php -r '
    foreach (json_decode(file_get_contents($argv[1]), true) as $job) {
        echo $job["id"], "\t", $job["conclusion"] ?? "", "\t", $job["name"], "\n";
    }' "$out/$run.jobs.json" | while IFS=$'\t' read -r id conclusion name; do
    log="$(mktemp)"
    gh api "repos/$repo/actions/jobs/$id/logs" > "$log" 2>/dev/null || { echo "no log for job $id ($name)" >&2; rm -f "$log"; continue; }
    if grep -q -- '- Locking ' "$log"; then
        # setup-php colours its "Installed PHP x.y.z" line, so ANSI escapes are stripped first.
        sed -E 's/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z //; s/\x1b\[[0-9;]*m//g' "$log" \
            | grep -E '^  - Locking |Installed PHP [0-9]+\.[0-9]+\.[0-9]+|^Runtime: +PHP [0-9]+\.[0-9]+\.[0-9]+|^PHPUnit [0-9]+\.[0-9]+\.[0-9]+ by' \
            | sed -E 's/[[:space:]]+$//' > "$out/$run/env/$id.txt" || true
    fi
    if [[ "$conclusion" == failure ]]; then
        gzip -n -9 -c "$log" > "$out/$run/failed/$id.log.gz"
    fi
    rm -f "$log"
done
rmdir "$out/$run/failed" 2>/dev/null || true
echo "collected run $run: $(php -r 'echo count(json_decode(file_get_contents($argv[1]), true));' "$out/$run.jobs.json") jobs"
