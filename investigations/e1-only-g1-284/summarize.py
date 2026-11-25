#!/usr/bin/env python3
"""Validate and summarize the retained raw samples; no third-party dependencies."""
import json
import math
from pathlib import Path

ROOT = Path(__file__).parent

def percentile(values, p):
    return sorted(values)[math.ceil(len(values) * p) - 1]

lines = ["# Comparative G1 measurements", "", "Pooled nearest-rank percentiles of 300 measured samples per cell (three repeats of 100, each after 15 warmups). CPU is client user+system CPU; peak is PHP allocated memory, not RSS. Counts are per 100 operations. No acceptance threshold is inferred."]
for width in (64, 32):
    records = [json.loads(line) for line in (ROOT / f"raw/bench-{width}.jsonl").read_text().splitlines()]
    rows = [r for r in records if "scenario" in r]
    assert len(records) == 135 and len(rows) == 126
    scenarios = sorted({r["scenario"] for r in rows})
    assert len(scenarios) == 14
    for scenario in scenarios:
        for repeat in (1, 2, 3):
            pair = [r for r in rows if r["scenario"] == scenario and r["repeat"] == repeat and r["arm"] in ("base-e1", "g1")]
            assert len(pair) == 2
            for key in ("commands", "commits", "conflicts", "outcomes"):
                assert pair[0][key] == pair[1][key], (width, scenario, repeat, key)
    lines += ["", f"## PHP_INT_SIZE={width // 8}", "", "| Scenario | V2 p50 µs | E1 p50 µs | G1 p50 µs | Δ p50 | G1 p95/p99 µs | G1 ops/s | E1→G1 CPU µs/op | E1/G1 peak MiB | Commands/commits/conflicts |", "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|"]
    for scenario in scenarios:
        arms = {arm: [r for r in rows if r["scenario"] == scenario and r["arm"] == arm] for arm in ("base-v2", "base-e1", "g1")}
        assert all(len(a) == 3 for a in arms.values())
        samples = {arm: [v for r in arm_rows for v in r["latencyUs"]] for arm, arm_rows in arms.items()}
        p50 = {arm: percentile(v, .5) for arm, v in samples.items()}
        cpu = {arm: sum(r["cpuUs"] for r in a) / 300 for arm, a in arms.items()}
        peak = {arm: max(r["peakBytes"] for r in a) / 1048576 for arm, a in arms.items()}
        counts = {(r["commands"], r["commits"], r["conflicts"]) for r in arms["g1"]}
        assert len(counts) == 1
        count_text = "/".join(str(v) for v in counts.pop())
        lines.append(f"| {scenario} | {p50['base-v2']:.1f} | {p50['base-e1']:.1f} | {p50['g1']:.1f} | {(p50['g1']/p50['base-e1']-1)*100:+.1f}% | {percentile(samples['g1'], .95):.1f}/{percentile(samples['g1'], .99):.1f} | {300000000/sum(samples['g1']):.1f} | {cpu['base-e1']:.1f}→{cpu['g1']:.1f} | {peak['base-e1']:.1f}/{peak['g1']:.1f} | {count_text} |")
(ROOT / "BENCHMARK.md").write_text("\n".join(lines) + "\n")
print("Validated both widths: 25,200 measured operations; E1/G1 counters and outcomes match in every repeat/scenario.")
