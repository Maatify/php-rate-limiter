<?php

declare(strict_types=1);

/*
 * #153 LT-153-07 report (PLAN.md §6, §7, §8, §12, §13). Deterministic over
 * expected/<env>/*.jsonl.gz; writes expected/summary.md. Descriptive only:
 * the detectors are evaluated exactly as registered, nothing is tuned here.
 * argv: package root.
 */

namespace Maatify\RateLimiter\Investigations\ClockMixes153\Report;

const AMBIGUITY = 0.002; // PLAN §7.2 (LT-M-10)
const VALIDATOR = 'Bounded correlation store returned an invalid snapshot.';
const SITES = [
    'DecayCalculator.php:44', 'DecayCalculator.php:84', 'BudgetTracker.php:55',
    'EvaluationPipeline.php:280', 'EvaluationPipeline.php:531', 'EvaluationPipeline.php:867', 'EvaluationPipeline.php:1315',
    'EvaluationPipeline.php:1672', 'EvaluationPipeline.php:1675', 'EvaluationPipeline.php:1693', 'EvaluationPipeline.php:1776',
    'EvaluationPipeline.php:1780', 'EvaluationPipeline.php:2161', 'EvaluationPipeline.php:2324', 'EvaluationPipeline.php:2340',
    'EvaluationPipeline.php:2740', 'EvaluationPipeline.php:2765',
    'CircuitBreaker.php:72', 'CircuitBreaker.php:131', 'CircuitBreaker.php:170', 'CircuitBreaker.php:214', 'CircuitBreaker.php:235',
    'CircuitBreaker.php:243', 'CircuitBreaker.php:331', 'LocalFallbackLimiter.php:145', 'LocalFallbackLimiter.php:176', 'AdmissionRuntime.php:461',
];
const COMPARISONS = ['B', 'B84', 'C', 'E', "E'", 'F', 'I', 'L-1', 'L-2', 'L-3', 'L-4', 'L-5'];

$root = $argv[1] ?? getcwd();
// argv[2] (smoke runs only): another directory laid out as expected/.
$expected = $argv[2] ?? $root . '/investigations/clock-mixes-153/expected';

/** Wilson 95 % interval, in percent. */
function wilson(int $k, int $n): string
{
    if ($n === 0) {
        return '—';
    }
    $z = 1.959963984540054;
    $p = $k / $n;
    $d = 1 + $z * $z / $n;
    $c = ($p + $z * $z / (2 * $n)) / $d;
    $h = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $d;

    return sprintf('%d / %d = %.2f %% [%.2f, %.2f]', $k, $n, 100 * $p, 100 * max(0, $c - $h), 100 * min(1, $c + $h));
}

/** @return \Generator<array<string, mixed>> */
function rows(string $file): \Generator
{
    $handle = gzopen($file, 'rb');
    while (($line = gzgets($handle)) !== false) {
        if (trim($line) !== '') {
            yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
    }
    gzclose($handle);
}

/** Primary decision of a step (PLAN §6.2, LT-M-07). */
function primary(array $row): string
{
    return ($row['d'] ?? '-') . '|' . (($row['re'] ?? null) !== null ? 're' : '') . '|' . (isset($row['x']) ? 'x' : '');
}

function isA(array $row): bool
{
    return isset($row['x']) && str_contains((string) $row['x'], VALIDATOR);
}

/** A pair step is split when the twins' Redis-written seconds differ, or their spans cross a second (PLAN §4.4). */
function split(?array $s, ?array $z, float $theta): bool
{
    if ($s === null || $z === null) {
        return false;
    }
    if (($s['rs'] ?? []) !== ($z['rs'] ?? [])) {
        return true;
    }

    return (int) floor(min($s['h0'], $z['h0']) + $theta) !== (int) floor(max($s['h1'], $z['h1']) + $theta);
}

/** The law of PLAN §1.2 / §13 for a block: honoured at host time h with offset sigma. */
function honouredPred(array $setup, float $h, float $sigma): array
{
    $end = min($setup['phys'], (float) $setup['E'], $setup['E'] - $sigma);
    $present = $h < min($setup['phys'], (float) $setup['E']);
    $edges = [$setup['phys'], (float) $setup['E'], $setup['E'] - $sigma];
    $near = false;
    foreach ($edges as $i => $edge) {
        $band = AMBIGUITY + ($i === 0 ? $setup['physHalf'] : 0.0);
        if (abs($h - $edge) < $band) {
            $near = true;
        }
    }

    return ['hon' => $h < $end, 'present' => $present, 'near' => $near];
}

$cells = [];
$sites = [];
$l1 = [];
$offsets = [];
foreach (['E-SAME', 'E-DOCKER'] as $env) {
    foreach (['offset-before', 'offset-after'] as $probe) {
        $file = "$expected/$env/$probe.jsonl.gz";
        if (! is_file($file)) {
            continue;
        }
        $theta = [];
        $rtt = [];
        foreach (rows($file) as $r) {
            $theta[] = $r['redis'] - ($r['send'] + $r['recv']) / 2;
            $rtt[] = $r['recv'] - $r['send'];
        }
        sort($theta);
        sort($rtt);
        $offsets[$env][$probe] = ['theta' => $theta[intdiv(count($theta), 2)], 'rtt' => $rtt[intdiv(count($rtt), 2)]];
    }
    foreach (glob("$expected/$env/*.jsonl.gz") ?: [] as $file) {
        if (str_contains($file, '/offset-')) {
            continue;
        }
        $meta = null;
        $steps = [];
        $states = [];
        foreach (rows($file) as $r) {
            if (isset($r['meta'])) {
                $meta = $r['meta'];
                continue;
            }
            if (isset($r['state'])) {
                $states[$r['j']] = $r['state'];
                continue;
            }
            if (isset($r['end'])) {
                continue;
            }
            $steps[$r['j']][$r['t']][] = $r;
            foreach ($r['sites'] ?? [] as $site => $n) {
                $sites[$site] = ($sites[$site] ?? 0) + $n;
            }
        }
        if ($meta === null) {
            continue;
        }
        $cells[] = ['env' => $env, 'meta' => $meta, 'steps' => $steps, 'states' => $states];
    }
}

// θ (Redis − host) measured by run.php at the start of each cell; host times are moved to Redis time with it.
$theta = static fn (array $cell): float => (float) ($cell['meta']['theta'] ?? 0.0);
$md = [];
$md[] = '# #153 LT-153-07 — summary (generated by `harness/report.php`)';
$md[] = '';
$md[] = 'Generated from `expected/<env>/*.jsonl.gz`. Rates carry Wilson 95 % intervals. Environments are never pooled.';
$md[] = '';

// Validity: V-2, scale, V-SITE.
$md[] = '## Validity';
$md[] = '';
foreach ($offsets as $env => $probes) {
    foreach ($probes as $name => $o) {
        $pass = $env !== 'E-SAME' || abs($o['theta']) <= $o['rtt'] / 2;
        $md[] = sprintf('* %s %s: median θ %+.3f ms, median rtt %.3f ms%s', $env, $name, 1000 * $o['theta'], 1000 * $o['rtt'], $env === 'E-SAME' ? ($pass ? ' — V-2 PASS' : ' — **V-2 FAIL**') : '');
    }
}
$bad = array_filter($cells, static fn (array $c): bool => ($c['meta']['scale'] ?? 1) != 1 || ($c['meta']['cap'] ?? 0) > 0);
$md[] = sprintf('* cells: %d; cells with scale ≠ 1 or a smoke cap: %d%s', count($cells), count($bad), $bad === [] ? '' : ' — **refused**');
// V-PAIR, partial (LT ruling M-04, added after the run): the JSONL logs each step's op, index and due
// time, not the context or command arguments. For every step both twins ran on the shared schedule
// (S-RA and the P-RA returns follow per-twin timing; a recordFailure after a pre-check depends on that
// pre-check's decision), the twins' op and due time must be equal.
$pairChecked = 0;
$pairMismatch = 0;
foreach ($cells as $cell) {
    if ($cell['meta']['family'] === 'S-RA') {
        continue;
    }
    foreach ($cell['steps'] as $twins) {
        $byK = [];
        foreach (['S', 'Z'] as $t) {
            foreach ($twins[$t] ?? [] as $row) {
                if ($row['op'] === 'fail' && in_array($cell['meta']['family'], ['S-ATK', 'S-BEN'], true)) {
                    continue;
                }
                if (($row['probe']['band'] ?? '') === 'return' || $row['op'] === 'success') {
                    continue;
                }
                $byK[$row['k']][$t] = $row;
            }
        }
        foreach ($byK as $pair) {
            if (! isset($pair['S'], $pair['Z'])) {
                continue;
            }
            $pairChecked++;
            $pairMismatch += ($pair['S']['op'] !== $pair['Z']['op'] || $pair['S']['due'] !== $pair['Z']['due']) ? 1 : 0;
        }
    }
}
$unknown = array_diff(array_keys($sites), SITES);
$md[] = sprintf('* V-SITE: %d distinct `now()` sites observed; outside the registered list: %s', count($sites), $unknown === [] ? 'none — PASS' : '**' . implode(', ', $unknown) . '**');
$md[] = sprintf('* V-PAIR (partial, descriptive): %d paired steps on the shared schedule; op or due time differs between the twins in %d. The context and command arguments are not in the JSONL: they are equal by construction only (one object passed to both twins).', $pairChecked, $pairMismatch);
$md[] = '';

// Comparison-level skew flips (§6.1), per family × sigma, all environments separately.
$flipTable = [];
foreach ($cells as $cell) {
    $key = $cell['env'] . ' ' . $cell['meta']['family'] . ' ' . $cell['meta']['policy'];
    $sigma = (string) $cell['meta']['sigma'];
    foreach ($cell['steps'] as $twins) {
        foreach ($twins['S'] ?? [] as $row) {
            foreach ($row['n'] ?? [] as $site => $counts) {
                $flipTable[$key][$sigma][$site][0] = ($flipTable[$key][$sigma][$site][0] ?? 0) + ($counts[0] ?? 0);
                $flipTable[$key][$sigma][$site][1] = ($flipTable[$key][$sigma][$site][1] ?? 0) + ($counts[1] ?? 0);
            }
        }
    }
}
$md[] = '## Comparison level: skew flips in the σ twin (PLAN §6.1)';
$md[] = '';
$md[] = 'Each entry is flips / events of that comparison. E-SAME only carries a counterfactual (PLAN §4.3); E-DOCKER entries are listed for completeness and are not a measure.';
$md[] = '';
foreach ($flipTable as $key => $bySigma) {
    $md[] = '### ' . $key;
    $md[] = '';
    $md[] = '| σ | ' . implode(' | ', COMPARISONS) . ' |';
    $md[] = '| --- |' . str_repeat(' ---: |', count(COMPARISONS));
    uksort($bySigma, static fn ($a, $b): int => $a === 'AA' ? -1 : ($b === 'AA' ? 1 : ((float) $a <=> (float) $b)));
    foreach ($bySigma as $sigma => $bySite) {
        $line = '| ' . $sigma . ' |';
        foreach (COMPARISONS as $site) {
            $line .= isset($bySite[$site]) ? sprintf(' %d / %d |', $bySite[$site][1], $bySite[$site][0]) : ' — |';
        }
        $md[] = $line;
    }
    $md[] = '';
}

// Probes (§5.3, §7.2).
$md[] = '## Targeted probes: the law (PLAN §5.3, §7.2)';
$md[] = '';
$md[] = '`pred` is the law of §1.2 evaluated from each stream\'s setup values at the executed time; `obs` is what the twins did. In = prediction 1 (D-LAW-IN requires 100 %); out = prediction 0 (D-LAW-OUT requires 0 %). Ambiguous = within 2 ms of an edge (LT-M-10).';
$md[] = '';
$md[] = '| env | family | policy | σ | in: obs / pred | out: obs / pred | ambiguous | setup excluded | setup split | decision changed (primary) | secondary only | D-LAW |';
$md[] = '| --- | --- | --- | --- | --- | --- | ---: | ---: | ---: | --- | ---: | --- |';
$detectors = [];
$misses = [];
$probeDetail = [];
foreach ($cells as $cell) {
    $family = $cell['meta']['family'];
    if (! in_array($family, ['P-B', 'P-E2', 'P-E3', 'P-SAT', 'P-RA1', 'P-RA2'], true)) {
        continue;
    }
    $sigma = (float) $cell['meta']['sigma'];
    $th = $theta($cell);
    $in = [0, 0];
    $outc = [0, 0];
    $amb = 0;
    $excluded = 0;
    $splits = 0;
    $changed = 0;
    $secondary = 0;
    $total = 0;
    foreach ($cell['steps'] as $j => $twins) {
        $probe = [];
        foreach (['S', 'Z'] as $t) {
            foreach ($twins[$t] ?? [] as $row) {
                if (isset($row['probe'])) {
                    $probe[$t] = $row;
                }
            }
        }
        if (! isset($probe['S'], $probe['Z'])) {
            $excluded++;
            continue;
        }
        $s = $probe['S'];
        $z = $probe['Z'];
        $pred = null;
        $obs = null;
        $near = false;
        if ($family === 'P-B') {
            $p = $s['probe'];
            // Setup check (PLAN §5.3): K4 = 6 after two failures in both twins, same Redis second.
            if (($p['vS'] ?? -1) !== 6 || ($p['v0'] ?? -1) !== 6 || $p['uS'] !== $p['u0']) {
                $excluded++;
                continue;
            }
            $bS = static fn (array $row): ?array => array_values(array_filter($row['c'] ?? [], static fn (array $c): bool => $c['s'] === 'B' && $c['scope'] === 'account'))[0] ?? null;
            $cS = $bS($s);
            $cZ = $bS($z);
            if ($cS === null || $cZ === null) {
                $excluded++;
                continue;
            }
            $stepS = $p['uS'] + 1200 - $sigma;
            $stepZ = $p['u0'] + 1200;
            $pred = ($cS['h'] + $th >= $stepS) !== ($cZ['h'] + $th >= $stepZ);
            $near = abs($cS['h'] + $th - $stepS) < AMBIGUITY || abs($cZ['h'] + $th - $stepZ) < AMBIGUITY;
            $obs = $cS['out'] !== $cZ['out'];
        } elseif (in_array($family, ['P-E2', 'P-E3', 'P-SAT'], true)) {
            $setupS = $s['block']['S'] ?? null;
            $setupZ = $s['block']['Z'] ?? null;
            $expectLevel = $family === 'P-E3' ? 3 : 2;
            if ($setupS === null || $setupZ === null || $setupS['lvl'] !== $expectLevel || $setupZ['lvl'] !== $expectLevel || $s['h531'] === null || $z['h531'] === null) {
                $excluded++;
                continue;
            }
            // A setup split: the twins' blocks were written in different Redis seconds (PLAN §4.4).
            if (($setupS['sec'] ?? 0) !== ($setupZ['sec'] ?? 0)) {
                $splits++;
                continue;
            }
            $hon = static fn (array $row, array $setup): bool => array_filter($row['c'] ?? [], static fn (array $c): bool => $c['s'] === 'E' && $c['k'] === $setup['k'] && $c['out']) !== [];
            $lawS = honouredPred($setupS, $s['h531'] + $th, $sigma);
            $lawZ = honouredPred($setupZ, $z['h531'] + $th, 0.0);
            $pred = $lawS['hon'] !== $lawZ['hon'];
            $near = $lawS['near'] || $lawZ['near'];
            $obs = $hon($s, $setupS) !== $hon($z, $setupZ);
            // Post-run diagnosis (REPORT §8): a twin whose observation differs from the law, and whether a
            // Redis second boundary lies between its L531 reading and the end of its step.
            $diag = [];
            foreach (['S' => [$s, $setupS, $lawS], 'Z' => [$z, $setupZ, $lawZ]] as $t => [$row, $setup, $law]) {
                if ($law['hon'] !== $hon($row, $setup)) {
                    $diag[] = sprintf('%s L531 %.4f → end %.4f, boundary between: %s', $t, $row['h531'] + $th, $row['h1'] + $th, floor($row['h531'] + $th) !== floor($row['h1'] + $th) ? 'yes' : 'no');
                }
            }
        } elseif ($family === 'P-RA1') {
            $setup = $s['setup'] ?? [];
            $ok = isset($setup['S']['callerLast'], $setup['Z']['callerLast']) && ($setup['S']['v'] ?? -1) === 6 && ($setup['Z']['v'] ?? -1) === 6;
            if (! $ok) {
                $excluded++;
                continue;
            }
            $pred = $setup['S']['callerLast'] < $setup['S']['u'];
            $obs = ($s['d'] ?? '') !== 'ALLOW';
            $near = false;
            $fail = array_values(array_filter($twins['S'] ?? [], static fn (array $r): bool => $r['op'] === 'fail' && $r['k'] === 1))[0] ?? null;
            $planned = $fail === null ? 0.0 : $fail['h1'] + (int) ($fail['ra'] ?? 0);
            $l44 = (float) (array_values(array_filter($s['c'] ?? [], static fn (array $c): bool => $c['s'] === 'B' && $c['scope'] === 'account'))[0]['h'] ?? $s['h0']);
            $diag = $fail === null ? [] : [
                sprintf('S L1780 second %d, receipt %.4f (caller %.4f): caller boundary between: %s', $setup['S']['callerLast'], $fail['h1'] + $th, $fail['h1'] + $th + $sigma, floor($fail['h1'] + $sigma) > $setup['S']['callerLast'] ? 'yes' : 'no'),
                sprintf('planned return %.4f, L44 reading on return %.4f (caller): caller boundary between: %s', $planned + $sigma, $l44 + $sigma, floor($planned + $sigma) !== floor($l44 + $sigma) ? 'yes' : 'no'),
            ];
        } elseif ($family === 'P-RA2') {
            $setupS = $s['block']['S'] ?? null;
            if ($setupS === null || $s['h531'] === null) {
                $excluded++;
                continue;
            }
            // DV-E3: the steered return lands where the Redis-written block is present and not honoured,
            // [E − σ, min(E, phys)); a caller-written block cannot flip at L550 (§13 item 3).
            $at = (float) $s['probe']['pred'] + $th;
            $actual = $s['h531'] + $th;
            $hi = min((float) $setupS['E'], $setupS['phys']);
            $lo = $setupS['E'] - $sigma;
            $pred = $setupS['path'] === 'R' && $at >= $lo && $at < $hi;
            // Ambiguous when an edge of the window lies between the predicted and the actual return
            // (± 2 ms; phys also ± half its round trip). Edges matter only where a window exists.
            $near = false;
            if ($setupS['path'] === 'R' && $lo < $hi) {
                $from = min($at, $actual);
                $to = max($at, $actual);
                foreach ([[$lo, AMBIGUITY], [(float) $setupS['E'], AMBIGUITY], [$setupS['phys'], AMBIGUITY + $setupS['physHalf']]] as [$edge, $band]) {
                    $near = $near || ($edge > $from - $band && $edge < $to + $band);
                }
            }
            $obs = array_filter($s['c'] ?? [], static fn (array $c): bool => $c['s'] === 'E' && $c['k'] === $setupS['k'] && $c['flip']) !== [];
            $diag = [sprintf('S L531 %.4f → end %.4f, boundary between: %s', $actual, $s['h1'] + $th, floor($actual) !== floor($s['h1'] + $th) ? 'yes' : 'no')];
        }
        $total++;
        if (primary($s) !== primary($z)) {
            $changed++;
        } elseif (($s['lv'] ?? null) !== ($z['lv'] ?? null) || abs((int) ($s['ra'] ?? 0) - (int) ($z['ra'] ?? 0)) > (int) ceil(abs($sigma))) {
            $secondary++;
        }
        if ($near) {
            $amb++;
            continue;
        }
        if ($pred) {
            $in[1]++;
            $in[0] += $obs ? 1 : 0;
        } else {
            $outc[1]++;
            $outc[0] += $obs ? 1 : 0;
        }
        if ($pred !== $obs) {
            $misses[] = sprintf('%s %s %s σ=%+.2f j=%d pred=%d obs=%d — %s', $cell['env'], $family, $cell['meta']['policy'], $sigma, $j, $pred ? 1 : 0, $obs ? 1 : 0, implode('; ', $diag ?? []));
        }
        $diag = [];
    }
    $lawIn = $in[1] === 0 || $in[0] === $in[1];
    $lawOut = $outc[0] === 0;
    $env = $cell['env'];
    $detectors[$env] ??= ['D-LAW-IN' => [0, 0], 'D-LAW-OUT' => [0, 0], 'D-SAT' => [0, 0]];
    $detectors[$env]['D-LAW-IN'][0] += $in[1] - $in[0];
    $detectors[$env]['D-LAW-IN'][1] += $in[1];
    $detectors[$env]['D-LAW-OUT'][0] += $outc[0];
    $detectors[$env]['D-LAW-OUT'][1] += $outc[1];
    if ($family === 'P-SAT') {
        $detectors[$env]['D-SAT'][0] += $in[0];
        $detectors[$env]['D-SAT'][1] += $in[1] + $outc[1];
    }
    $md[] = sprintf('| %s | %s | %s | %+.2f | %s | %s | %d | %d | %d | %s | %d | %s |', $cell['env'], $family, $cell['meta']['policy'], $sigma, $in[1] ? "{$in[0]} / {$in[1]}" : '—', $outc[1] ? "{$outc[0]} / {$outc[1]}" : '—', $amb, $excluded, $splits, wilson($changed, $total), $secondary, ($lawIn && $lawOut) ? 'PASS' : '**FAIL**');
}
$md[] = '';
foreach ($detectors as $env => $d) {
    $md[] = sprintf('* %s **D-LAW-IN:** %d misses in %d in-window probes — %s', $env, $d['D-LAW-IN'][0], $d['D-LAW-IN'][1], $d['D-LAW-IN'][0] === 0 ? 'PASS' : '**FAIL**');
    $md[] = sprintf('* %s **D-LAW-OUT:** %d flips in %d probes predicted 0 — %s', $env, $d['D-LAW-OUT'][0], $d['D-LAW-OUT'][1], $d['D-LAW-OUT'][0] === 0 ? 'PASS' : '**FAIL**');
    $md[] = $d['D-SAT'][1] === 0
        ? sprintf('* %s **D-SAT:** P-SAT not run in this environment (§12 LT-M-04)', $env)
        : sprintf('* %s **D-SAT:** %d / %d not honoured — %s', $env, $d['D-SAT'][0], $d['D-SAT'][1], $d['D-SAT'][0] === $d['D-SAT'][1] ? 'PASS' : '**FAIL**');
}
$md[] = '';
// Post-run descriptive addition (REPORT §8): every D-LAW miss, with the boundary check of §8.
$md[] = '### D-LAW misses (post-run diagnosis; not a gate)';
$md[] = '';
foreach ($misses as $line) {
    $md[] = '* ' . $line;
}
if ($misses === []) {
    $md[] = '* none';
}
$md[] = '';

// P-I (no detector) and streams (§6.2-§6.5).
$md[] = '## P-I and streams (PLAN §5.3 P-I, §5.4, §6.2–§6.5)';
$md[] = '';
$md[] = '| env | family | policy | σ | pairs | streams with ≥ 1 changed decision | changed / paired steps | first divergence: skew / A / split / unexplained | outcome Δ ≠ 0 (S − Z) | exceptions (S, Z) | of which #155 | state diverged |';
$md[] = '| --- | --- | --- | --- | ---: | --- | --- | --- | --- | --- | ---: | ---: |';
$aa = [];
foreach ($cells as $cell) {
    $family = $cell['meta']['family'];
    if (! in_array($family, ['P-I', 'S-BEN', 'S-ATK', 'S-RA'], true)) {
        continue;
    }
    $sigma = $cell['meta']['sigma'];
    $th = $theta($cell);
    $withChange = 0;
    $changedSteps = 0;
    $paired = 0;
    $causes = ['skew' => 0, 'A' => 0, 'split' => 0, 'none' => 0];
    $outcomeDiff = 0;
    $exS = 0;
    $exZ = 0;
    $x155 = 0;
    $diverged = 0;
    $pairs = 0;
    foreach ($cell['steps'] as $j => $twins) {
        $pairs++;
        $byK = [];
        foreach (['S', 'Z'] as $t) {
            $attempt = 0;
            foreach ($twins[$t] ?? [] as $row) {
                if (! in_array($row['op'], ['check', 'fail', 'success', 'claim'], true)) {
                    continue;
                }
                // S-RA twins keep their own timing: steps are paired by attempt (the n-th pre-check), PLAN §5.4.
                if ($family === 'S-RA') {
                    if ($row['op'] === 'check') {
                        $byK[$attempt++][$t][] = $row;
                    } elseif ($attempt > 0) {
                        $byK[$attempt - 1][$t . '+'][] = $row;
                    }
                    continue;
                }
                $byK[$row['k']][$t][] = $row;
            }
        }
        ksort($byK);
        $first = null;
        $skewSoFar = false;
        $count = 0;
        foreach ($byK as $k => $pair) {
            $s = $pair['S'][0] ?? null;
            $z = $pair['Z'][0] ?? null;
            foreach ($pair['S'] ?? [] as $row) {
                if (($row['f'] ?? []) !== []) {
                    $skewSoFar = true;
                }
            }
            if ($s !== null && $z !== null && $s['op'] === $z['op']) {
                $paired++;
                if (primary($s) !== primary($z)) {
                    $count++;
                    if ($first === null) {
                        $first = $k;
                        $cause = $skewSoFar ? 'skew' : (isA($s) ? 'A' : (split($s, $z, $th) ? 'split' : 'none'));
                        $causes[$cause]++;
                    }
                }
            }
            // The S-RA calls after this attempt's pre-check act on the next attempts only.
            foreach ($pair['S+'] ?? [] as $row) {
                if (($row['f'] ?? []) !== []) {
                    $skewSoFar = true;
                }
            }
        }
        $changedSteps += $count;
        $withChange += $count > 0 ? 1 : 0;
        $fails = ['S' => 0, 'Z' => 0];
        $refused = ['S' => 0, 'Z' => 0];
        foreach (['S', 'Z'] as $t) {
            foreach ($twins[$t] ?? [] as $row) {
                $fails[$t] += $row['op'] === 'fail' ? 1 : 0;
                $refused[$t] += $row['op'] === 'check' && ($row['d'] ?? '') !== 'ALLOW' ? 1 : 0;
                if (isset($row['x'])) {
                    $t === 'S' ? $exS++ : $exZ++;
                    $x155 += str_contains((string) $row['x'], 'malformed bounded metadata') ? 1 : 0;
                }
            }
        }
        $outcomeDiff += ($fails['S'] !== $fails['Z'] || $refused['S'] !== $refused['Z']) ? 1 : 0;
        $state = $cell['states'][$j] ?? null;
        if ($state !== null && json_encode($state['S']) !== json_encode($state['Z'])) {
            $diverged++;
        }
    }
    if ($sigma === 'AA') {
        $aaKey = $cell['env'] . ' ' . $family . ' ' . $cell['meta']['policy'];
        $aa[$aaKey] = [$causes['skew'] + $causes['A'] + $causes['none'], $pairs];
    }
    $md[] = sprintf('| %s | %s | %s | %s | %d | %s | %d / %d | %d / %d / %d / %d | %d | %d, %d | %d | %d |', $cell['env'], $family, $cell['meta']['policy'], is_string($sigma) ? $sigma : sprintf('%+.2f', $sigma), $pairs, wilson($withChange, $pairs), $changedSteps, $paired, $causes['skew'], $causes['A'], $causes['split'], $causes['none'], $outcomeDiff, $exS, $exZ, $x155, $diverged);
}
$md[] = '';
foreach ($aa as $key => [$bad, $n]) {
    $md[] = sprintf('* **D-AA** %s: %d first divergences not attributed to a split, over %d A/A pairs — %s', $key, $bad, $n, $bad === 0 ? 'PASS' : '**FAIL**');
}
$md[] = '* **V-ATTR:** the "unexplained" column must be 0 in every row.';
$md[] = '';

// L-1 occurrences with context (LT-M-01 ruling).
$md[] = '## Lua mixes: occurrences (PLAN §1.3, LT-M-01)';
$md[] = '';
$lua = [];
foreach ($cells as $cell) {
    foreach ($cell['steps'] as $j => $twins) {
        foreach ($twins['S'] ?? [] as $row) {
            foreach ($row['f'] ?? [] as $flip) {
                if (str_starts_with($flip['s'], 'L-')) {
                    $lua[$flip['s']][] = sprintf('%s %s %s σ=%s j=%d k=%d op=%s writer=%s reader=%s', $cell['env'], $cell['meta']['family'], $cell['meta']['policy'], $cell['meta']['sigma'], $j, $row['k'], $row['op'], $flip['w'], $flip['r']);
                }
            }
        }
    }
}
foreach (['L-1', 'L-2', 'L-3', 'L-4', 'L-5'] as $id) {
    $md[] = sprintf('* %s: %d flips in the σ twins', $id, count($lua[$id] ?? []));
    if ($id === 'L-1') {
        foreach (array_slice($lua[$id] ?? [], 0, 50) as $line) {
            $md[] = '  * ' . $line;
        }
    }
}
$md[] = '';

file_put_contents($expected . '/summary.md', implode("\n", $md) . "\n");
echo "wrote $expected/summary.md\n";
