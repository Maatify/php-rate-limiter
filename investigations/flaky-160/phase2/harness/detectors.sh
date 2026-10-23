#!/usr/bin/env bash
# #160 phase 2 detectors (PLAN.md §4.1, §5, §8): every check is shown firing before any counted run.
#
#   detectors.sh <out>
#
# Writes <out>/detectors.jsonl (one line per detector: expected, observed, holds).
#   D1 provenance   tap_full.php = the #157 tap at 8b79e9b + exactly the recorded diff
#   D2 V-HEAD       a different head (1e951ee) is refused
#   D3 V-REF        the A test passes through no tap
#   D4 base         a real tapped run: valid, 32 workers resolved by pid (LT-160B-03), V-KEY holds, no anomaly
#   D5 V-FRAME      one record dropped / one connection dropped / a duplicate seq  -> run invalid
#   D6 V-WORKER     one worker's pid unresolved                                    -> run invalid, counted
#   D7 V-KEY        a second allowance unit recorded for one worker                -> run invalid
#   D8 anomaly      a worker's K5 +2 edited away in the record                     -> unit_without_k5_no_conflict
#   D9 C-DET tiny   3 per variant: C-DET-1 / C-DET-0 / C-DET-2 as registered
#   D10 C-NAT tiny  2,000 repetitions: N-C1 on every repetition
set -uo pipefail

here="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
out="$(mkdir -p "${1:?usage: detectors.sh <out>}" && cd "$1" && pwd)"
php=(env XDEBUG_MODE=off php)
log="$out/detectors.jsonl"
: > "$log"

record() { # $1 id, $2 expected, $3 observed, $4 holds(0/1)
    "${php[@]}" -r 'echo json_encode(["detector" => $argv[1], "expected" => $argv[2], "observed" => $argv[3], "holds" => $argv[4] === "1"], JSON_UNESCAPED_SLASHES), "\n";' "$1" "$2" "$3" "$4" | tee -a "$log"
}
field() { # $1 json line, $2 php expression over $v
    "${php[@]}" -r '$v = json_decode($argv[1], true); echo $argv[2] === "" ? "" : eval("return " . $argv[2] . ";");' "$1" "$2"
}

# D1
if bash "$here/provenance_check.sh" > "$out/d1-provenance.txt" 2>&1; then
    record D1-provenance 'tap copy OK' "$(grep -c '^OK' "$out/d1-provenance.txt") OK, $(grep -c '^FAIL' "$out/d1-provenance.txt") FAIL" 1
else
    record D1-provenance 'tap copy OK' "$(grep -c '^FAIL' "$out/d1-provenance.txt") FAIL" 0
fi

# D2
status=0
V_HEAD_REF=1e951ee bash "$here/run.sh" ref "$out/d2" > "$out/d2-vhead.txt" 2>&1 || status=$?
if (( status != 0 )) && grep -q 'V-HEAD FAIL' "$out/d2-vhead.txt"; then
    record D2-v-head 'refused (exit != 0, V-HEAD FAIL)' "exit $status, $(grep -m1 'V-HEAD' "$out/d2-vhead.txt")" 1
else
    record D2-v-head 'refused (exit != 0, V-HEAD FAIL)' "exit $status" 0
fi

# D3
line="$(bash "$here/run.sh" ref "$out/d3" 2>/dev/null | tail -n 1)"
record D3-v-ref 'the A test passes, one test, no tap' "$line" "$([[ "$line" == *'"test":"pass"'* && "$line" == *'"valid":true'* ]] && echo 1 || echo 0)"

# D4 (a real tapped run, kept)
# A tapped base run that fails is retried (at most 3 attempts, every attempt kept in d4/attempts.jsonl):
# the base run is the substrate of D5..D8; a failing attempt is itself reported, never hidden.
: > "$out/d4-attempts.jsonl"
for attempt in 1 2 3; do
    rm -rf "$out/d4"
    line="$(KEEP_ALL=1 bash "$here/run.sh" arec "$out/d4" 1 1 2>/dev/null | tail -n 1)"
    printf '%s\n' "$line" >> "$out/d4-attempts.jsonl"
    [[ "$line" == *'"valid":true'* && "$line" == *'"test":"pass"'* ]] && break
done
ok="$(field "$line" '$v["valid"] && $v["test"] === "pass" && $v["unresolved_pids"] === 0 && count($v["workers"]) === 32 && $v["v_key"]["consumed_read"] === $v["v_key"]["sum_u"] && $v["anomalies"] === [] ? "1" : "0"')"
record D4-base 'valid; 32 workers by pid, none unresolved; V-KEY holds; no anomaly' "$("${php[@]}" -r '$v = json_decode($argv[1], true); echo json_encode(["valid" => $v["valid"], "test" => $v["test"], "workers" => count($v["workers"]), "unresolved_pids" => $v["unresolved_pids"], "v_key" => $v["v_key"], "anomalies" => count($v["anomalies"])]);' "$line")" "$ok"
base="$out/d4/runs/arec-1"

# D5..D8
synthetic() { # $1 id, $2 edit -> prints the a_check line
    "${php[@]}" "$here/synthetic.php" --from="$base" --to="$out/syn-$2" --edit="$2" || { echo '{"valid":null,"problems":["synthetic_failed"]}'; return; }
    "${php[@]}" "$here/a_check.php" --arm=arec --id=1 --dir="$out/syn-$2" --exit=0 --redis-port=1 --tapped=1 --wall=1
}
for edit in drop-line drop-conn dup-seq; do
    line="$(synthetic D5 "$edit")"
    record "D5-v-frame-$edit" 'invalid (v_frame problem)' "$(field "$line" 'json_encode(["valid" => $v["valid"], "problems" => $v["problems"]])')" "$([[ "$line" == *'"valid":false'* && "$line" == *v_frame* ]] && echo 1 || echo 0)"
done
line="$(synthetic D6 null-pid)"
record D6-v-worker 'invalid (v_worker:pid_unresolved), counted' "$(field "$line" 'json_encode(["valid" => $v["valid"], "problems" => $v["problems"], "unresolved_pids" => $v["unresolved_pids"]])')" "$([[ "$line" == *'"valid":false'* && "$line" == *pid_unresolved* ]] && echo 1 || echo 0)"
line="$(synthetic D7 extra-incr)"
record D7-v-key 'invalid (v_key:consumed)' "$(field "$line" 'json_encode(["valid" => $v["valid"], "problems" => $v["problems"], "v_key" => $v["v_key"]])')" "$([[ "$line" == *'"valid":false'* && "$line" == *v_key:consumed* ]] && echo 1 || echo 0)"
line="$(synthetic D8 k5-amount)"
record D8-anomaly 'valid run, one anomaly of class unit_without_k5_no_conflict' "$(field "$line" 'json_encode(["valid" => $v["valid"], "anomalies" => array_map(fn($a) => $a["class"], $v["anomalies"])])')" "$([[ "$line" == *'"valid":true'* && "$line" == *unit_without_k5_no_conflict* ]] && echo 1 || echo 0)"
# the registered rule applied to the synthetic failing run: P-FAIL holds for it
echo "$line" | sed 's/"test":"pass"/"test":"fail","failure":{"lines":[136],"message":"synthetic"}/' > "$out/syn-fail.verdicts.jsonl"
rep="$("${php[@]}" "$here/a_report.php" --verdicts="$out/syn-fail.verdicts.jsonl")"
record D8b-p-fail 'P-FAIL holds for the synthetic failing run' "$("${php[@]}" -r '$r = json_decode($argv[1], true); echo json_encode($r["arec"]["P_FAIL"]);' "$rep")" "$("${php[@]}" -r '$r = json_decode($argv[1], true); echo $r["arec"]["P_FAIL"]["holds"] === true ? "1" : "0";' "$rep")"

# D9, D10 (own Redis through run.sh)
bash "$here/run.sh" cdet "$out/d9" 3 > "$out/d9.txt" 2>&1
bash "$here/run.sh" cnat "$out/d10" 2000 > "$out/d10.txt" 2>&1
rep="$("${php[@]}" "$here/c_report.php" --det="$out/d9/cdet.rows.jsonl" --expect-det=3 --nat="$out/d10/cnat.rows.jsonl" --expect-nat=2000)"
held="$("${php[@]}" -r '$r = json_decode($argv[1], true); echo json_encode(["C-DET-1" => $r["det"]["C-DET-1"]["holds"], "C-DET-0" => $r["det"]["C-DET-0"]["holds"], "C-DET-2" => $r["det"]["C-DET-2"]["holds"]]);' "$rep")"
record D9-c-det-tiny 'all three variants hold at 3 trials' "$held" "$([[ "$held" != *false* ]] && echo 1 || echo 0)"
nat="$("${php[@]}" -r '$r = json_decode($argv[1], true)["nat"]; echo json_encode(["k" => $r["k"], "valid" => $r["valid"], "discordant" => $r["n_c1_discordant"], "straddles" => $r["straddles"]]);' "$rep")"
record D10-c-nat-tiny 'N-C1 holds on every repetition (no rate drawn)' "$nat" "$([[ "$nat" == *'"discordant":0'* && "$nat" == *'"k":2000'* ]] && echo 1 || echo 0)"

echo "detectors: $(grep -c '"holds":true' "$log") / $(wc -l < "$log" | tr -d ' ') hold"
