#!/usr/bin/env python3
"""Pre-ownership-fix (6e37b982) vs current HEAD: validate pairs and print the p50 table."""
import json, math
from pathlib import Path
ROOT = Path(__file__).parent
def pct(v, p): return sorted(v)[math.ceil(len(v) * p) - 1]
out = ['# F300-03..08 ownership-fix measurements', '', 'Source before = 6e37b982 (pre-ownership fixes), after = the measured HEAD, same unchanged parent bench.php. 3 order-rotated repeats x 100 ops after 15 warmups; pooled nearest-rank percentiles; commands, commits, conflicts and outcomes must match in every pair. Concurrency=1; laboratory evidence only.', '']
for width in (64, 32):
    recs = [json.loads(l) for l in (ROOT / f'raw/bench-{width}.jsonl').read_text().splitlines()]
    rows = [r for r in recs if 'scenario' in r]
    assert len(rows) == 84 and all(r['intSize'] == width // 8 for r in rows)
    out += [f'## PHP_INT_SIZE={width // 8}', '', '| Scenario | Before p50 µs | After p50 µs | Δ p50 | After p95/p99 µs | CPU µs/op before→after | Commands/commits/conflicts per 100 |', '|---|---:|---:|---:|---:|---:|---:|']
    for s in sorted({r['scenario'] for r in rows}):
        a = {arm: [r for r in rows if r['scenario'] == s and r['arm'] == arm] for arm in ('before', 'after')}
        for i in range(3):
            for k in ('commands', 'commits', 'conflicts', 'outcomes'):
                assert a['before'][i][k] == a['after'][i][k], (width, s, k)
        smp = {k: [x for r in v for x in r['latencyUs']] for k, v in a.items()}
        b, f = pct(smp['before'], .5), pct(smp['after'], .5)
        cpu = {k: sum(r['cpuUs'] for r in v) / 300 for k, v in a.items()}
        c = a['after'][0]
        out.append(f"| {s} | {b:.1f} | {f:.1f} | {(f / b - 1) * 100:+.1f}% | {pct(smp['after'], .95):.1f}/{pct(smp['after'], .99):.1f} | {cpu['before']:.1f}→{cpu['after']:.1f} | {c['commands']}/{c['commits']}/{c['conflicts']} |")
    out.append('')
(ROOT / 'BENCHMARK.md').write_text('\n'.join(out) + '\n')
print('\n'.join(out))
