# #153 phase 2 acceptance

The acceptance of the one-time-authority fix: PLAN.md §4.6, §4.7, §10 and §12. The scripts call the archived #154 and #156 harnesses unchanged (V-1, LT-P2-07), and run on the fixed code.

## Scripts

| Script | What it does |
| --- | --- |
| `run.sh E-SAME` / `run.sh E-DOCKER` | Runs #154 in full and the #156 probe groups. On E-SAME it also runs the two-clock arm. With `ACCEPT_ONLY=p154`, it reruns #154 alone and replaces only `expected/<env>/p154` |
| `s0_parity.sh` + `s0_parity.php` | Gate §4.5: the six #156 S-BEN / S-RA cells at σ = AA, compared with the pre-fix outputs |
| `tail_cause.sh` + `tail_cause.php` | A descriptive replay of the P-TAIL / P-HT targets that keeps the full result (LT-P2-17) |
| `report.php <root> [expected-dir] [p156]` | Writes `<expected-dir>/summary.md`: every registered condition and verdict, plus the descriptive sections. With `p156`, it writes only the #156 sections, for a directory that holds `<env>/p156` alone |

## N-158-01: twin results need a pairing gap of at most 100 ms

The #156 probes compare two twins, S at σ and Z at 0, on the same schedule. Their verdicts assume that both twins of a step run at nearly the same instant.

`run.sh` starts every cell of a #156 group concurrently, as the archived runner does. `probes-a` has 65 cells. On a machine that this load saturates, the gap |h0_S − h0_Z| between twins grows to hundreds of milliseconds:
* the Lead's run: p99 400–650 ms, maximum 921 ms;
* the executor's run: p99 27–36 ms, maximum 121 ms.

A step that is not paired can differ for reasons that have nothing to do with the clock under test. So C-156-Q carries the validity condition V-GAP (PLAN §12.5):
* a step with a gap above 100 ms is unpaired, and is left out of the verdict;
* a `return` step is measured by each twin's lateness against its own schedule;
* a probe step also takes the largest gap of its stream's earlier steps, since a late write moves the stream's stored end;
* a cell with more than 1 % unpaired steps is UNPROVEN, not FAIL.

**Load invalidates the cell, not the fix.** To prove such a cell, rerun it alone. `run.php` takes one cell as arguments; see `run.sh` for the exact form.
