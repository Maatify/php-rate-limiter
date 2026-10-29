#!/usr/bin/env bash
# PROVENANCE: copy of investigations/flaky-160/phase3-k4-hand/harness/detectors.sh at 5202bf3, sha256 b90c375abc4fc9336dd47d21addcdbc60b27951409fe8b87da371c51a1d0d2a6; delete this line to get the original byte for byte.
# #160 phase 3 detectors and cost measurement (PLAN.md §5, §6, §13 LT-160D-07/08): every check is shown
# firing before any counted run, then every arm runs once at K = 2,000 for the measured cost.
#
#   detectors.sh <out> [k]     k: size of the cost arms (default 2000)
#
# Writes <out>/detectors.jsonl (one line per detector: expected, observed, holds), <out>/timings.jsonl,
# <out>/<arm>.rows.jsonl.gz and <out>/report.json.
#   D1 provenance   every copy = its original at d37f9a3 (+ exactly its recorded diff); a tampered copy fails
#   D2 V-HEAD       a different head (d71c0af) is refused
#   D3 classifier   synthetic rows through k_lib.php are classified as registered (synthetic.php)
#   D4 D-DET tiny   3 + 3 for K and for H: injected not applied / 0 winners, control applied / 1 winner
#   D5 X readback   PEXPIREAT a known X: PEXPIRETIME returns it (30)
#   D6 V-CLOCK      20 with and 20 without the recorder on a fixed clock: identical outcomes (K and H);
#      overhead     2,000 recorder + 2,000 SystemClock sequences: median ratio <= 1.25
#   cost            knat kheld kforced kforcedm kcal hnat at k each (counted numbers come only from the counted dispatch)
set -uo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../../.." && pwd)"
out="$(mkdir -p "${1:?usage: detectors.sh <out> [k]}" && cd "$1" && pwd)"
k="${2:-2000}"
php=(env XDEBUG_MODE=off php -d memory_limit=1G)
log="$out/detectors.jsonl"
: > "$log"

record() { # $1 id, $2 expected, $3 observed, $4 holds(0/1)
    "${php[@]}" -r 'echo json_encode(["detector" => $argv[1], "expected" => $argv[2], "observed" => $argv[3], "holds" => $argv[4] === "1"], JSON_UNESCAPED_SLASHES), "\n";' "$1" "$2" "$3" "$4" | tee -a "$log"
}
report_field() { # $1 php expression over $r (the decoded report.json)
    "${php[@]}" -r '$r = json_decode(file_get_contents($argv[1]), true); echo json_encode(eval("return " . $argv[2] . ";"), JSON_UNESCAPED_SLASHES);' "$out/report.json" "$1"
}

# D1: the copies, then a tampered copy (a sibling folder at the same depth, removed afterwards)
if bash "$here/provenance_check.sh" > "$out/d1-provenance.txt" 2>&1; then
    copies_ok=1
else
    copies_ok=0
fi
tamper="$here/../d1-tamper-$$"
rm -rf "$tamper"
cp -R "$here" "$tamper"
printf '\n' >> "$tamper/stats.php"
tamper_status=0
bash "$tamper/provenance_check.sh" > "$out/d1-tampered.txt" 2>&1 || tamper_status=$?
rm -rf "$tamper"
record D1-provenance 'every copy OK; a tampered stats.php copy FAILs' \
    "$(grep -c '^OK' "$out/d1-provenance.txt") OK, $(grep -c '^FAIL' "$out/d1-provenance.txt") FAIL; tampered: exit $tamper_status, $(grep -c '^FAIL' "$out/d1-tampered.txt") FAIL" \
    "$([[ "$copies_ok" == 1 && "$tamper_status" != 0 ]] && grep -q '^FAIL stats.php' "$out/d1-tampered.txt" && echo 1 || echo 0)"

# D2
status=0
V_HEAD_REF=d71c0af bash "$here/run.sh" xread "$out/d2" 1 > "$out/d2-vhead.txt" 2>&1 || status=$?
rm -rf "$out/d2"
if (( status != 0 )) && grep -q 'V-HEAD FAIL' "$out/d2-vhead.txt"; then
    record D2-v-head 'refused (exit != 0, V-HEAD FAIL)' "exit $status, $(grep -m1 'V-HEAD' "$out/d2-vhead.txt")" 1
else
    record D2-v-head 'refused (exit != 0, V-HEAD FAIL)' "exit $status" 0
fi

# D3
status=0
"${php[@]}" "$here/synthetic.php" > "$out/d3-synthetic.jsonl" 2>&1 || status=$?
record D3-classifier 'every synthetic case classified as registered' \
    "$(grep -c '"holds":true' "$out/d3-synthetic.jsonl") holds, $(grep -c '"holds":false' "$out/d3-synthetic.jsonl") not, exit $status" \
    "$([[ "$status" == 0 ]] && ! grep -q '"holds":false' "$out/d3-synthetic.jsonl" && echo 1 || echo 0)"

# D4, D5, D6 and the cost arms (each its own fresh container, its timing line)
bash "$here/run.sh" ddet "$out" 3 || true
bash "$here/run.sh" xread "$out" 30 || true
bash "$here/run.sh" vclock "$out" 20 || true
bash "$here/run.sh" overhead "$out" "$k" || true
for arm in knat kheld kforced kforcedm kcal hnat; do
    bash "$here/run.sh" "$arm" "$out" "$k" || true
done
bash "$here/run.sh" report "$out" > /dev/null

record D4-ddet 'K-injected 3/3 not applied, K-control 3/3 applied, H-injected 3/3 zero winners, H-control 3/3 one winner' \
    "$(report_field 'array_map(fn($c) => $c["hits"] . "/" . $c["total"], $r["ddet"] ?? [])')" \
    "$(report_field 'isset($r["ddet"]) && count(array_filter($r["ddet"], fn($c) => $c["holds"])) === 4 ? 1 : 0' | tr -d '"')"
record D5-x-readback '30/30 PEXPIRETIME = the PEXPIREAT value' "$(report_field '($r["xread"]["equal"] ?? 0) . "/" . ($r["xread"]["total"] ?? 0)')" \
    "$(report_field '($r["xread"]["holds"] ?? false) ? 1 : 0' | tr -d '"')"
record D6-v-clock 'identical outcomes with and without the recorder, K and H' "$(report_field 'array_map(fn($t) => $t["identical"], $r["vclock"] ?? [])')" \
    "$(report_field '($r["vclock"]["K"]["identical"] ?? false) && ($r["vclock"]["H"]["identical"] ?? false) ? 1 : 0' | tr -d '"')"
record D6-overhead 'recorder / SystemClock median sequence time <= 1.25' "$(report_field '$r["overhead"]["median_ratio"] ?? null')" \
    "$(report_field '($r["overhead"]["holds_le_1_25"] ?? false) ? 1 : 0' | tr -d '"')"
cat "$out/timings.jsonl"
! grep -q '"holds":false' "$log"
