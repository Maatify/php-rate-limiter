#!/usr/bin/env bash
# #160 detectors (PLAN.md §7, §12): every check is shown firing before any
# counted run. Needs <out> with the reference outputs (run.sh reference).
#
#   detectors.sh <out>
#
# Writes <out>/detectors.jsonl (one line per detector: expected, observed, holds)
# and leaves each detector's own outputs under <out>.
#   D1 provenance      each copied file = the #157 original at 8b79e9b without line 2
#   D2 V-HEAD          a different head (1e951ee) is refused
#   D3 V-REF           the reference matches #157 (75 files, k_ref 1329, fccd09eb…)
#   D4 V-REF perturbed a prefix with its first file dropped is refused
#   D5 V-ORD           one run of the perturbed prefix is invalid (ordinals differ)
#   D6 classifier      selftest_u.php: six failure / six error / seventh / clean / order
#   D7 M tiny          det, det-control, ep-det, ep-det-control, ep-builder-limit,
#                      ep-builder-outside at 3 each; nat and ep-nat at 2,000 (no rate drawn)
#   C1 cost            one untapped prefix run (ucost) for the measured per-run cost
set -uo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
out="$(cd -- "${1:?usage: detectors.sh <out>}" && pwd)"
php=(env XDEBUG_MODE=off php)
log="$out/detectors.jsonl"
: > "$log"

record() { # $1 id, $2 expected, $3 observed, $4 holds(0/1)
    "${php[@]}" -r 'echo json_encode(["detector" => $argv[1], "expected" => $argv[2], "observed" => $argv[3], "holds" => $argv[4] === "1"], JSON_UNESCAPED_SLASHES), "\n";' "$1" "$2" "$3" "$4" | tee -a "$log"
}

# D1
if bash "$here/provenance_check.sh" > "$out/d1-provenance.txt" 2>&1; then
    record D1-provenance 'all copies OK' "$(grep -c '^OK' "$out/d1-provenance.txt") OK, $(grep -c '^FAIL' "$out/d1-provenance.txt") FAIL" 1
else
    record D1-provenance 'all copies OK' "$(grep -c '^FAIL' "$out/d1-provenance.txt") FAIL" 0
fi

# D2 (its own out dir with the reference's vref.json and prefix.xml, so only V-HEAD can refuse)
mkdir -p "$out/d2-vhead"
cp "$out/vref.json" "$out/prefix.xml" "$out/d2-vhead/" 2>/dev/null || true
status=0
V_HEAD_REF=1e951ee bash "$here/run.sh" ucost "$out/d2-vhead" 0 > "$out/d2-vhead.txt" 2>&1 || status=$?
if (( status != 0 )) && grep -q 'V-HEAD FAIL' "$out/d2-vhead.txt"; then
    record D2-v-head 'refused (exit != 0, V-HEAD FAIL)' "exit $status, $(grep -m1 'V-HEAD' "$out/d2-vhead.txt")" 1
else
    record D2-v-head 'refused (exit != 0, V-HEAD FAIL)' "exit $status" 0
fi

# D3
ok="$("${php[@]}" -r '$v = json_decode(file_get_contents($argv[1]), true); echo json_encode(["ok" => $v["ok"], "files" => $v["files_count"], "equal_157" => $v["files_equal_157"], "k_ref" => $v["k_ref"], "sequence_ok" => $v["sequence_ok"], "ordinals" => $v["ordinals"]]);' "$out/vref.json")"
record D3-v-ref 'ok: 75 files equal to #157, k_ref 1329, sequence fccd09eb…' "$ok" "$([[ "$ok" == *'"ok":true'* ]] && echo 1 || echo 0)"

# D4
"${php[@]}" "$here/prefix.php" --root="$root" --junit="$out/reference.junit.xml" --out-dir="$out" --perturb > "$out/d4-perturb.txt" 2>&1
status=0
"${php[@]}" "$here/vref.php" --root="$root" --out-dir="$out" --prefix="$out/prefix-perturbed.xml" >> "$out/d4-perturb.txt" 2>&1 || status=$?
if (( status != 0 )) && grep -q 'V-REF FAIL' "$out/d4-perturb.txt"; then
    record D4-v-ref-perturbed 'refused (V-REF FAIL)' "exit $status, $(grep -m1 'V-REF' "$out/d4-perturb.txt")" 1
else
    record D4-v-ref-perturbed 'refused (V-REF FAIL)' "exit $status" 0
fi

# D5
bash "$here/run.sh" uconfig "$out" 1 "$out/prefix-perturbed.xml" > "$out/d5-vord.txt" 2>&1
line="$(grep '"arm":"uconfig"' "$out/verdicts.jsonl" | tail -n 1)"
if [[ "$line" == *'"v_ord":false'* && "$line" == *'"valid":false'* ]]; then
    record D5-v-ord 'invalid (v_ord false)' "$("${php[@]}" -r '$v = json_decode($argv[1], true); echo json_encode(["v_ord" => $v["v_ord"], "valid" => $v["valid"], "tests" => $v["tests"], "ordinals_differ" => $v["ordinals_differ"]]);' "$line")" 1
else
    record D5-v-ord 'invalid (v_ord false)' "${line:0:300}" 0
fi

# D6
status=0
"${php[@]}" "$here/selftest_u.php" --out-dir="$out" > "$out/d6-selftest.json" 2>&1 || status=$?
record D6-classifier 'all five cases classified as registered' "exit $status, $(grep -c '"holds": true' "$out/d6-selftest.json") / 5 hold" "$([[ $status == 0 ]] && echo 1 || echo 0)"

# D7
for subarm in det det-control ep-det ep-det-control ep-builder-limit ep-builder-outside; do
    bash "$here/run.sh" m "$out/d7-m" "$subarm" 3 >> "$out/d7-m.txt" 2>&1
done
for subarm in nat ep-nat; do
    bash "$here/run.sh" m "$out/d7-m" "$subarm" 2000 >> "$out/d7-m.txt" 2>&1
done
"${php[@]}" "$here/m_report.php" --expect=det:3 --expect=det-control:3 --expect=ep-det:3 --expect=ep-det-control:3 \
    --expect=ep-builder-limit:3 --expect=ep-builder-outside:3 "$out"/d7-m/m-*/rows.jsonl > "$out/d7-m-report.json"
held="$("${php[@]}" -r '$r = json_decode(file_get_contents($argv[1]), true); $h = []; foreach (["det", "det-control", "ep-det", "ep-det-control", "ep-builder-limit", "ep-builder-outside"] as $a) { $h[$a] = $r[$a]["holds"] ?? false; } foreach (["nat", "ep-nat"] as $a) { $h[$a . "-n1"] = $r[$a]["n1_holds"] ?? false; } echo json_encode($h);' "$out/d7-m-report.json")"
record D7-m-tiny 'det/ep-det/ep-builder-outside throw as registered, controls and ep-builder-limit do not; N-1 on every nat/ep-nat call' "$held" "$([[ "$held" != *false* ]] && echo 1 || echo 0)"

# C1
bash "$here/run.sh" ucost "$out" 1 > "$out/c1-cost.txt" 2>&1
line="$(grep '"arm":"ucost"' "$out/verdicts.jsonl" | tail -n 1)"
record C1-cost 'one valid untapped prefix run; wall seconds container to container' "$("${php[@]}" -r '$v = json_decode($argv[1], true); echo json_encode(["valid" => $v["valid"], "pooled" => $v["pooled"], "wall_seconds" => $v["wall_seconds"], "phpunit_time" => $v["phpunit_time"] ?? null, "tests" => $v["tests"]]);' "$line")" "$([[ "$line" == *'"valid":true'* ]] && echo 1 || echo 0)"

echo "detectors: $(grep -c '"holds":true' "$log") / $(wc -l < "$log" | tr -d ' ') hold"
