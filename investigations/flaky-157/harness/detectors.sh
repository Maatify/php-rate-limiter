#!/usr/bin/env bash
# #157 detector demonstration (PLAN.md §10): every validity rule and the arm-D
# detector shown firing on purpose, plus one clean run of each arm, before any
# N run. Not part of any rate.
#   detectors.sh <out>   (<out> must already hold prefix.xml + kref.json from "run.sh reference")
#   H_ROOT set -> also a tiny arm-H probe/forced run for the runner-hour estimate.
set -uo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$here/../../.." && pwd)"
out="$(cd -- "${1:?usage: detectors.sh <out>}" && pwd)"
php=(env XDEBUG_MODE=off php)
log="$out/detectors.log"
: > "$log"

step() { echo "== $*" | tee -a "$log"; }

step 'V-HEAD must refuse a head that differs (V_HEAD_REF=1e951ee)'
V_HEAD_REF=1e951ee3c3b29ecc49ce08ceff809869bccf139d bash "$here/run.sh" l "$out" 900 1 >> "$log" 2>&1
echo "v_head_negative_exit=$?" | tee -a "$log"

step 'L clean run (prefix suite through the tap)'
bash "$here/run.sh" l "$out" 1 1 >> "$log" 2>&1

step 'V-FRAME must fire: tap log drops case 0 READ'
bash "$here/run.sh" l "$out" 2 1 drop >> "$log" 2>&1

step 'V-FRAME must fire: tap log swaps case 0 READ and MUTATE'
bash "$here/run.sh" l "$out" 3 1 reorder >> "$log" 2>&1

step 'V-ORD must fire: prefix with its first file dropped'
"${php[@]}" "$here/prefix.php" --root="$root" --junit="$out/reference.junit.xml" --out-dir="$out" --perturb >> "$log" 2>&1
bash "$here/run.sh" lconfig "$out" 4 "$out/prefix-perturbed.xml" >> "$log" 2>&1

for dcase in 0 1 2; do
    step "D signature, case $dcase (tap deletes the key before MUTATE)"
    bash "$here/run.sh" d "$out" "$dcase" 1 1 >> "$log" 2>&1
done

step 'Tap overhead, small K (the registered measurement is run.sh overhead 2000, before L)'
bash "$here/run.sh" overhead "$out" 100 >> "$log" 2>&1

step 'H classifier self-test'
"${php[@]}" "$here/h_report.php" --selftest > "$out/h-selftest.json"

if [[ -n "${H_ROOT:-}" ]]; then
    step 'H tiny runs at fe8775c (for the runner-hour estimate)'
    bash "$here/run.sh" h "$out" probe 2000 0 >> "$log" 2>&1
    bash "$here/run.sh" h "$out" forced 30 0 >> "$log" 2>&1
    "${php[@]}" "$here/h_report.php" "$out/h-probe-0/h.jsonl" > "$out/h-probe-0.report.json"
    "${php[@]}" "$here/h_report.php" "$out/h-forced-0/h.jsonl" > "$out/h-forced-0.report.json"
fi

"${php[@]}" "$here/detectors_report.php" "$out" | tee "$out/detectors.json"
