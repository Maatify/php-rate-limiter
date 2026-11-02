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
#
# Fails closed (Lead ruling on F-176-03, PLAN §12.1): everything is written to a staging directory first, and any
# failed fetch (run, jobs page, or a job's log) exits non-zero before anything under data/runs/ is replaced, so a
# collection is either complete or leaves the previous files as they were.
set -euo pipefail

run="${1:?usage: collect.sh <run_id>}"
repo=Maatify/php-rate-limiter
here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
out="$here/../data/runs"
mkdir -p "$out"
stage="$(mktemp -d "$out/.collect-$run.XXXXXX")"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/$run/env" "$stage/$run/failed"

gh api "repos/$repo/actions/runs/$run" > "$stage/$run.run.json"
status="$(php -r 'echo json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["status"];' "$stage/$run.run.json")"
[[ "$status" == completed ]] || { echo "run $run is $status, not completed" >&2; exit 1; }
gh api --paginate "repos/$repo/actions/runs/$run/attempts/1/jobs?per_page=100" --jq '.jobs[]' > "$stage/jobs.ndjson"
php -r '$rows = []; foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $rows[] = json_decode($l, true, flags: JSON_THROW_ON_ERROR); }
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";' "$stage/jobs.ndjson" > "$stage/$run.jobs.json"

php -r '
    foreach (json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR) as $job) {
        echo $job["id"], "\t", $job["conclusion"] ?? "", "\t", $job["name"], "\n";
    }' "$stage/$run.jobs.json" > "$stage/jobs.tsv"
while IFS=$'\t' read -r id conclusion name; do
    log="$stage/job.log"
    gh api "repos/$repo/actions/jobs/$id/logs" > "$log" || { echo "collect.sh: no log for job $id ($name); nothing written" >&2; exit 1; }
    if grep -q -- '- Locking ' "$log"; then
        # setup-php colours its "Installed PHP x.y.z" line, so ANSI escapes are stripped first.
        sed -E 's/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z //; s/\x1b\[[0-9;]*m//g' "$log" \
            | grep -E '^  - Locking |Installed PHP [0-9]+\.[0-9]+\.[0-9]+|^Runtime: +PHP [0-9]+\.[0-9]+\.[0-9]+|^PHPUnit [0-9]+\.[0-9]+\.[0-9]+ by' \
            | sed -E 's/[[:space:]]+$//' > "$stage/$run/env/$id.txt" || true
    fi
    if [[ "$conclusion" == failure ]]; then
        gzip -n -9 -c "$log" > "$stage/$run/failed/$id.log.gz"
    fi
done < "$stage/jobs.tsv"
rmdir "$stage/$run/failed" 2>/dev/null || true

# Every fetch succeeded: replace the run's previous files with the staged ones.
rm -rf "${out:?}/$run"
mv "$stage/$run" "$out/$run"
mv "$stage/$run.run.json" "$out/$run.run.json"
mv "$stage/$run.jobs.json" "$out/$run.jobs.json"
echo "collected run $run: $(php -r 'echo count(json_decode(file_get_contents($argv[1]), true));' "$out/$run.jobs.json") jobs, $(find "$out/$run/env" -type f | wc -l | tr -d ' ') environment files"
