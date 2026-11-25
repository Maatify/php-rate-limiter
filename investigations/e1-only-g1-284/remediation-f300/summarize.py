#!/usr/bin/env python3
"""Validate the two-arm F300 rerun, preserving the original G1 benchmark and raw evidence."""
import hashlib
import json
import math
from pathlib import Path

ROOT = Path(__file__).parent

def percentile(values, p):
    return sorted(values)[math.ceil(len(values) * p) - 1]

manifest = json.loads((ROOT / 'source-manifest.json').read_text())
repo = ROOT.parents[2]
for path, digest in manifest['shared_sha256'].items():
    assert hashlib.sha256((repo / path).read_bytes()).hexdigest() == digest, path

lines = ['# F300 corrective measurements', '', 'Rejected HEAD 304a5835 vs corrected source, same unchanged parent bench.php and pinned dependencies. Three repeats of 100 measured operations after 15 warmups per repeat. Pooled nearest-rank percentiles; commands, commits, conflicts and outcomes must match in every pair. Concurrency=1; no production-load or significance claim. Original G1 versus historical E1 results remain in ../BENCHMARK.md.', '']
for width in (64, 32):
    records = [json.loads(line) for line in (ROOT / f'raw/bench-{width}.jsonl').read_text().splitlines()]
    rows = [r for r in records if 'scenario' in r]
    sources = [r for r in records if 'pipelineSha256' in r]
    assert len(sources) == 6
    for arm in ('rejected', 'corrected'):
        loaded = [r for r in sources if r['arm'] == arm]
        expected = manifest['source_arms'][arm]['sha256']
        assert len(loaded) == 3
        assert all(r['pipelineSha256'] == expected['src/Service/EvaluationPipeline.php'] and r['builderSha256'] == expected['src/Builder/RateLimiterBuilder.php'] and r['intSize'] == width // 8 for r in loaded), (width, arm, 'loaded source')
    assert len(records) == 90 and len(rows) == 84
    scenarios = sorted({r['scenario'] for r in rows})
    assert len(scenarios) == 14
    for scenario in scenarios:
        for repeat in (1, 2, 3):
            pair = [r for r in rows if r['scenario'] == scenario and r['repeat'] == repeat]
            assert {r['arm'] for r in pair} == {'rejected', 'corrected'} and len(pair) == 2
            for key in ('commands', 'commits', 'conflicts', 'outcomes'):
                assert pair[0][key] == pair[1][key], (width, scenario, repeat, key)
    lines += [f'## PHP_INT_SIZE={width // 8}', '', '| Scenario | Rejected p50 µs | Corrected p50 µs | Δ p50 | Corrected p95/p99 µs | Corrected ops/s | Client CPU µs/op before→after | PHP peak MiB before/after | Commands/commits/conflicts per 100 |', '|---|---:|---:|---:|---:|---:|---:|---:|---:|']
    for scenario in scenarios:
        arms = {arm: [r for r in rows if r['scenario'] == scenario and r['arm'] == arm] for arm in ('rejected', 'corrected')}
        assert all(len(a) == 3 for a in arms.values())
        assert all(r['n'] == 100 and r['warmup'] == 15 and r['intSize'] == width // 8 and len(r['latencyUs']) == 100 for a in arms.values() for r in a)
        samples = {a: [v for r in rs for v in r['latencyUs']] for a, rs in arms.items()}
        before, after = [percentile(samples[a], .5) for a in ('rejected', 'corrected')]
        current = arms['corrected']
        cpu = {a: sum(r['cpuUs'] for r in rs) / 300 for a, rs in arms.items()}
        peak = {a: max(r['peakBytes'] for r in rs) / 1048576 for a, rs in arms.items()}
        counts = current[0]
        lines.append(f"| {scenario} | {before:.1f} | {after:.1f} | {(after / before - 1) * 100:+.1f}% | {percentile(samples['corrected'], .95):.1f}/{percentile(samples['corrected'], .99):.1f} | {300000000 / sum(samples['corrected']):.1f} | {cpu['rejected']:.1f}→{cpu['corrected']:.1f} | {peak['rejected']:.1f}/{peak['corrected']:.1f} | {counts['commands']}/{counts['commits']}/{counts['conflicts']} |")
    lines += ['', 'PHP64 is native linux/arm64; PHP32 is actual linux/386 under emulation. Compare arms within a width. CPU is client user+system CPU; PHP allocated peak is not RSS. Redis CPU, concurrency, network/TLS, production hosts and the custom allowance-only advanced Pipeline are not performance-qualified by this run.', '']
(ROOT / 'BENCHMARK.md').write_text('\n'.join(lines).rstrip() + '\n')
