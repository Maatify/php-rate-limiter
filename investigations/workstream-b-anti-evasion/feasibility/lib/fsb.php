<?php

declare(strict_types=1);

// Unified Security Feasibility (#97 / #100) — shared harness. Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + REAL
// DeviceIdentityResolver + reference stores + controlled clock), the PR #104 wsb harness (streams L1–L4 / X1–X8 / Y*, wsbControls) and the
// PR #105 dsg harness (streams D1–D7, OpCountingStore). LAB_ROOT selects the package tree under test:
//   S0               : the repository checkout (UNPATCHED src/; pipeline sha-256 asserted below)
//   EQL / EQV        : isolated tree = baseline + PR #105's published patch (build_candidate.sh eq)
//   PSC / PSCA       : isolated tree = baseline + patches/psc-candidate.patch (build_candidate.sh psc)
// WSB_CANDIDATE selects the variant. Every loaded EvaluationPipeline must be byte-identical to its pinned file or the run aborts (exit 4).
require_once dirname(__DIR__, 2) . '/design/lib/dsg.php';
require_once dirname(__DIR__) . '/fixtures/feasibility.php';

const FSB_BASE_SHA = 'b34f12eb3f892c2dd6fa423280ead0cdaafc868f';
const FSB_PSC_PIPELINE_SHA256 = '86beb1632f023778b74f916f95aa39fc48bdffe931e9fe3450dd54d13ee3fbe5';
const FSB_VARIANTS = ['S0', 'EQL', 'EQV', 'PSC', 'PSCA'];

/**
 * Proves WHICH EvaluationPipeline was loaded (never mislabels evidence). S0 = the repository's own pinned file; EQL/EQV = PR #105's pinned
 * patched copy; PSC/PSCA = this study's pinned patched copy; every candidate must live OUTSIDE the repository's src/.
 *
 * @return array<string, mixed>
 */
function fsbProvenance(): array
{
    $variant = wsbVariant();
    if (! in_array($variant, FSB_VARIANTS, true)) {
        fwrite(STDERR, "unknown feasibility variant: $variant\n");
        exit(4);
    }
    $file = (new \ReflectionClass(\Maatify\RateLimiter\Service\EvaluationPipeline::class))->getFileName();
    $sha = hash_file('sha256', (string) $file);
    $repoPipeline = realpath(dirname(__DIR__, 4) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath((string) $file) === $repoPipeline;
    $pinned = match ($variant) {
        'S0' => WSB_BASE_PIPELINE_SHA256,
        'EQL', 'EQV' => DSG_PATCHED_PIPELINE_SHA256,
        default => FSB_PSC_PIPELINE_SHA256,
    };
    if ($sha !== $pinned || ($variant === 'S0') !== $inRepo) {
        fwrite(STDERR, "provenance violation: $variant loaded EvaluationPipeline $sha (expected $pinned; inRepo=" . ($inRepo ? 'yes' : 'no') . ")\n");
        exit(4);
    }

    return ['variant' => $variant, 'level' => wsbLevel(), 'baseSha' => FSB_BASE_SHA, 'pipelineSha256' => $sha, 'patched' => $variant !== 'S0'];
}

/**
 * Host-realistic replay (same semantics as wsbReplay) that additionally records, per event, the SCOPE of the decision that denied it.
 * The public result does not expose the winning candidate's `source`, so the winner is derived from persisted enforcement:
 *   issued:<K..>  a block cycle was persisted by this very command (new decision; scope = the key family that received it)
 *   active:<K..>  the command was denied by an already-persisted block (scope = the key families holding an unexpired block)
 *   none          denied with no persisted hard block (soft / score-only)
 *
 * @param list<array<string, mixed>> $events
 * @return array{events: list<array<string, mixed>>}
 */
function fsbReplay(Lab $lab, array $events, string $flow, string $shape): array
{
    $out = [];
    $keyScope = [];
    foreach ($events as $ev) {
        $lab->at($ev['t']);
        $acct = $shape === 'login-anon' || str_starts_with($shape, 'api') ? null : $ev['acct'];
        $c = $lab->ctx($ev['ip'], WSB_UA[$ev['ua']] ?? CHROME, $acct, $ev['fp'] === null ? null : fp($ev['fp']));
        $outcome = wsbShapeOutcome($shape, $ev['outcome']);
        $keys = $lab->keys($c);
        foreach ($keys as $name => $k) {
            if ($k !== null) {
                $keyScope[$k] = $name;
            }
        }
        $cyclesBefore = count($lab->store->cycleLog);
        $blocked = false;
        $reached = false;
        $steps = [];
        if ($flow !== 'out') {
            $r = $lab->run($c, 'check');
            $steps[] = 'check:' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl']);
            $blocked = $r['d'] !== 'A';
            $reached = ! $blocked;
        }
        if ($flow !== 'pre' && ! $blocked) {
            $r = $lab->run($c, $outcome);
            $steps[] = $outcome . ':' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl']);
            $blocked = $r['d'] !== 'A';
            $reached = true;
        }
        $src = null;
        if ($blocked) {
            $issued = [];
            for ($i = $cyclesBefore, $n = count($lab->store->cycleLog); $i < $n; $i++) {
                $issued[$keyScope[$lab->store->cycleLog[$i]['key']] ?? '?'] = true;
            }
            if ($issued !== []) {
                ksort($issued);
                $src = 'issued:' . implode('+', array_keys($issued));
            } else {
                $now = $lab->clock->now()->getTimestamp();
                $active = [];
                foreach ($keys as $name => $k) {
                    if ($k !== null && $lab->store->checkBlock($k) !== null && $lab->store->checkBlock($k)->expiresAt > $now) {
                        $active[] = $name;
                    }
                }
                $src = $active === [] ? 'none' : 'active:' . implode('+', $active);
            }
        }
        $out[] = ['t' => $ev['t'], 'kind' => $ev['kind'], 'blocked' => $blocked, 'reached' => $reached, 'src' => $src, 'steps' => implode('>', $steps), 'outcome' => $outcome];
    }

    return ['events' => $out];
}

/**
 * Attack-vs-legitimate measurement of one (stream, shape, flow) on the loaded runtime. The live run uses the operation-counting store;
 * a paired "churn/dilution correlation disabled" control run attributes denials to correlation (counterfactual).
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function fsbMeasure(array $events, string $shape, string $flow): array
{
    // WSB_SELFTEST_CORR_OFF=1 (run.sh self-test only): the "live" run uses the correlation-disabled store, simulating a regression that silently
    // removes churn/dilution protection. The S0 baseline assertions of F01 must then FAIL.
    $lab = getenv('WSB_SELFTEST_CORR_OFF') === '1' ? new Lab(wsbLabPolicy($shape), null, true) : dsgCountingLab(wsbLabPolicy($shape));
    $peakEntries = 0;
    $on = ['events' => []];
    // replay with per-event store-size sampling (peak live entries)
    foreach ($events as $ev) {
        $one = fsbReplay($lab, [$ev], $flow, $shape);
        $on['events'][] = $one['events'][0];
        $s = wsbStoreStats($lab);
        $peakEntries = max($peakEntries, $s['sets'] + $s['flags']);
    }
    $ctl = fsbReplay(new Lab(wsbLabPolicy($shape), null, true), $events, $flow, $shape);

    $atk = ['n' => 0, 'reached' => 0, 'denied' => 0, 'byCorr' => 0, 'src' => [], 'firstT' => null];
    $leg = ['n' => 0, 'denied' => 0, 'byCorr' => 0, 'src' => [], 'firstT' => null, 'lastT' => null];
    foreach ($on['events'] as $i => $r) {
        $isAtk = $r['kind'] === 'attack';
        $b = &$leg;
        if ($isAtk) {
            $b = &$atk;
        }
        $b['n']++;
        if ($isAtk && $r['reached']) {
            $b['reached']++;
        }
        if ($r['blocked']) {
            $b['denied']++;
            $byCorr = ! $ctl['events'][$i]['blocked'];
            $b['byCorr'] += $byCorr ? 1 : 0;
            $label = ($byCorr ? 'corr:' : 'indep:') . $r['src'];
            $b['src'][$label] = ($b['src'][$label] ?? 0) + 1;
            $b['firstT'] ??= $r['t'];
            if (! $isAtk) {
                $leg['lastT'] = $r['t'];
            }
        }
        unset($b);
    }
    ksort($atk['src']);
    ksort($leg['src']);
    $issued = [];
    $keyScope = [];
    foreach ($lab->store->cycleLog as $cyc) {
        $issued[$cyc['key']] = ($issued[$cyc['key']] ?? 0) + 1;
    }
    $scopeCount = [];
    foreach ($events as $ev) {
        $c = $lab->ctx($ev['ip'], WSB_UA[$ev['ua']] ?? CHROME, str_starts_with($shape, 'api') || $shape === 'login-anon' ? null : $ev['acct'], $ev['fp'] === null ? null : fp($ev['fp']));
        foreach ($lab->keys($c) as $name => $k) {
            if ($k !== null && isset($issued[$k])) {
                $keyScope[$k] = $name;
            }
        }
    }
    foreach ($issued as $k => $n) {
        $scopeCount[$keyScope[$k] ?? '?'] = ($scopeCount[$keyScope[$k] ?? '?'] ?? 0) + $n;
    }
    ksort($scopeCount);
    $corr = $lab->corr instanceof OpCountingStore ? $lab->corr : new OpCountingStore($lab->clock);

    return [
        'attackAttempts' => $atk['n'],
        'attackReachedVerification' => $atk['reached'],
        'attackDenied' => $atk['denied'],
        'attackDeniedByCorrelation' => $atk['byCorr'],
        'attackDeniedBySource' => $atk['src'],
        'legitAttempts' => $leg['n'],
        'legitDenied' => $leg['denied'],
        'legitDeniedByCorrelation' => $leg['byCorr'],
        'legitDeniedBySource' => $leg['src'],
        'legitFirstDenyT' => $leg['firstT'],
        'legitLastDenyT' => $leg['lastT'],
        'penaltyScopeIssuances' => $scopeCount,
        'blockIssuancesTotal' => array_sum($scopeCount),
        'storeOps' => $corr->ops,
        'storeOpsTotal' => $corr->total(),
        'peakLiveCorrelationEntries' => $peakEntries,
        'controlLegitDenied' => count(array_filter($ctl['events'], static fn(array $r): bool => $r['kind'] === 'legit' && $r['blocked'])),
        'controlAttackReachedVerification' => count(array_filter($ctl['events'], static fn(array $r): bool => $r['kind'] === 'attack' && $r['reached'])),
    ];
}
