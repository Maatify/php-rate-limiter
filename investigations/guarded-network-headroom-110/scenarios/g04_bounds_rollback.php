<?php

declare(strict_types=1);

// G04 — storage bounds, TTL, backend failure, rollback and mixed version on the loaded arm (reference stores, controlled clock, Login).
//   S1  one in-zone scope: the correlation keys and set members each arm keeps for it (K1 spray + K2 churn sequences of G02)
//   S2  every correlation key written on the arm has expired 1801 s after the stream ends; no bounded set ever exceeds 30 members
//   F1  backend failure of the budget operation only, through the REAL RateLimiterEngine (circuit breaker + failure-mode resolver)
//   R1  rollback in the middle of a 600 s window: the candidate runs the first 300 s of a stream, then the REAL unpatched S0 code continues
//       on the same state for 600 s (child process)
//   R2  mixed version: candidate and S0 code alternate per request on one state (child processes)
require dirname(__DIR__) . '/lib/gnh.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

emit('G04.provenance', gnhProvenance());
$arm = gnhArm();
$cand = $arm !== 'S0';
$streams = gnhStreams();
$refl = new \ReflectionClass(StatefulInMemoryCorrelationStore::class);
/** @return array{keys: int, members: int, maxSet: int, families: array<string, int>, maxTtl: int} */
$inventory = static function (Lab $lab) use ($refl): array {
    $now = $lab->clock->now()->getTimestamp();
    $fam = [];
    $keys = 0;
    $members = 0;
    $maxSet = 0;
    $maxTtl = 0;
    foreach (['sets', 'flags'] as $kind) {
        foreach ($refl->getProperty($kind)->getValue($lab->corr) as $k => $s) {
            if ($s['expiresAt'] === null || $s['expiresAt'] <= $now) {
                continue;
            }
            $keys++;
            $maxTtl = max($maxTtl, $s['expiresAt'] - $now);
            $f = $kind . ':' . (str_starts_with((string) $k, 'gnh5:x5:') ? 'x5' : (str_starts_with((string) $k, 'gnh5:budget:') ? 'budget' : 's0'));
            $fam[$f] = ($fam[$f] ?? 0) + 1;
            if ($kind === 'sets') {
                $members += count($s['items']);
                $maxSet = max($maxSet, count($s['items']));
            }
        }
    }
    ksort($fam);

    return ['keys' => $keys, 'members' => $members, 'maxSet' => $maxSet, 'families' => $fam, 'maxTtl' => $maxTtl];
};

// S1 — one K1 scope driven 60 s into its zone (one KNOWN subject repeated, checkOnly only) and one K2 scope likewise (three devices on three
// accounts, then the first one repeated). Inventory right after the last request.
$ev = [];
for ($i = 0; $i < 5; $i++) {
    $ev[] = k1Ev($i, '198.51.100.170', null, "s1s$i", 'fail', 'attack', ['uaRaw' => k1Ua(101)]);
}
for ($t = 5; $t <= 64; $t++) {
    $ev[] = k1Ev($t, '198.51.100.170', null, 's1s0', 'fail', 'attack', ['uaRaw' => k1Ua(101)]);
}
for ($i = 0; $i < 3; $i++) {
    $ev[] = k1Ev(100 + $i, '198.51.100.171', "s1f$i", "s1a$i", 'ok', 'attack', ['uaRaw' => k1Ua(102)]);
}
for ($t = 103; $t <= 160; $t++) {
    $ev[] = k1Ev($t, '198.51.100.171', 's1f0', 's1a0', 'ok', 'attack', ['uaRaw' => k1Ua(102)]);
}
$lab = gnhLab();
gnhReplay($lab, $ev, 'pre', 'login', false);
$s1 = $inventory($lab);
emit('G04.S1.per-scope-storage', ['arm' => $arm] + $s1);
GnhAsserts::check('G04', 'S1 ' . match ($arm) {
    'S0' => 'S0: the two in-zone scopes keep only S0-family keys (sets + WATCH flags), every set within its S0 cap',
    'TH5' => 'TH5: the same key families as S0 (no new key), sets may grow to the x5 caps',
    'GNH5' => 'GNH5: the S0 keys plus at most one x5 set per S0 rule set and exactly one budget flag per in-zone rule scope (2 here; fixed TTL 600 s); no per-request key',
}, match ($arm) {
    'S0', 'TH5' => array_diff(array_keys($s1['families']), ['flags:s0', 'sets:s0']) === [],
    'GNH5' => isset($s1['families']['sets:x5'], $s1['families']['flags:budget']) && $s1['families']['flags:budget'] === 2 && $s1['families']['sets:x5'] <= $s1['families']['sets:s0'],
}, $s1);

// S2 — TTL bounds: every correlation key written with a TTL <= 1800 s on this arm has expired 1801 s after the last event; the keys still
// alive then are only the S0 machinery's own long windows (anti-equilibrium 6 h, distributed-account occurrence 24 h) — never a "gnh5:" key;
// the largest set stays <= 30 members.
$s2 = [];
foreach (['SP1n', 'ABA_A', 'XN1', 'D2', 'SP6'] as $id) {
    $lab = gnhLab();
    gnhReplay($lab, $streams[$id]['events'], 'pre+out', 'login', false);
    $end = max(array_column($streams[$id]['events'], 't'));
    $atEnd = $inventory($lab);
    $lab->at($end + 1801);
    $now = $lab->clock->now()->getTimestamp();
    $survivors = [];
    $gnhSurvivors = 0;
    foreach (['sets', 'flags'] as $kind) {
        foreach ($refl->getProperty($kind)->getValue($lab->corr) as $k => $st) {
            if ($st['expiresAt'] !== null && $st['expiresAt'] > $now) {
                $ttl = gnhStore($lab)->ttlOf[$k] ?? -1;
                $survivors[$ttl] = ($survivors[$ttl] ?? 0) + 1;
                $gnhSurvivors += str_starts_with((string) $k, 'gnh5:') ? 1 : 0;
            }
        }
    }
    ksort($survivors);
    $gnhTtls = [];
    foreach (gnhStore($lab)->ttlOf as $k => $ttl) {
        if (str_starts_with((string) $k, 'gnh5:')) {
            $gnhTtls[$ttl] = true;
        }
    }
    ksort($gnhTtls);
    $s2[$id] = ['keysAtEnd' => $atEnd['keys'], 'membersAtEnd' => $atEnd['members'], 'maxSetAtEnd' => $atEnd['maxSet'], 'aliveAfter1801sByWriteTtl' => $survivors, 'gnhKeysAliveAfter1801s' => $gnhSurvivors, 'gnhKeyTtls' => array_keys($gnhTtls)];
}
emit('G04.S2.ttl-bounds', ['arm' => $arm, 'streams' => $s2]);
GnhAsserts::check('G04', 'S2 every correlation key written with TTL <= 1800 s on this arm (sets, WATCH flags, x5 sets, budget flags) has expired 1801 s after the stream; survivors are S0 long-window keys only (TTL > 1800 s, never "gnh5:"); every "gnh5:" key is written with TTL 600 or 1800; no set above 30 members', array_reduce($s2, static fn(bool $c, array $s): bool => $c && $s['gnhKeysAliveAfter1801s'] === 0 && array_filter(array_keys($s['aliveAfter1801sByWriteTtl']), static fn(int $t): bool => $t <= 1800) === [] && array_diff($s['gnhKeyTtls'], [600, 1800]) === [] && $s['maxSetAtEnd'] <= 30, true), $s2);

// F1 — the budget operation fails (BackendFailureException from the correlation store on the "gnh5:budget:" WATCH key only). The REAL
// RateLimiterEngine converts it to the Login policy's failure mode. On S0 / TH5 no budget operation exists, so the stream is unaffected.
$failing = new class ((new Lab())->clock) extends GnhStore {
    public int $failures = 0;

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        if (str_starts_with($currentKey, 'gnh5:budget:')) {
            $this->failures++;
            throw new BackendFailureException('simulated budget backend failure');
        }

        return parent::incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        if (str_starts_with($key, 'gnh5:budget:')) {
            $this->failures++;
            throw new BackendFailureException('simulated budget backend failure');
        }

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }
};
$lab = new Lab();
$failingStore = new $failing($lab->clock);
$lab->corr = $failingStore;
gnhRewire($lab);
$emitter = new RecordingFailureSignalEmitter();
$engine = new RateLimiterEngine($lab->resolver, $lab->pipeline, new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $lab->clock), new FailureModeResolver(), $emitter, $lab->clock, [$lab->policy]);
$f1 = [];
for ($t = 0; $t < 12; $t++) {
    $lab->at($t);
    $subject = $t < 5 ? "f1s$t" : 'f1s0';
    $r = $engine->limit($lab->ctx('198.51.100.172', k1Ua(103), $subject), RateLimitCommand::checkOnly($lab->policy->getName()));
    $f1[] = $t . ':' . $r->decision . '/' . $r->failureMode . ($r->decision === 'ALLOW' ? '' : '/L' . $r->blockLevel . '/' . $r->retryAfter);
}
emit('G04.F1.budget-backend-failure', ['arm' => $arm, 'budgetFailures' => $failingStore->failures, 'decisions' => $f1]);
$afterFirstFailure = array_slice($f1, 4);
GnhAsserts::check('G04', 'F1 ' . match ($arm) {
    'S0' => 'S0: no budget operation exists; the stream is decided as without failures (4 allowed, then the K1 spray block)',
    'TH5' => 'TH5: no budget operation exists; the stream is decided as without failures (all 12 allowed: known subject below the x5 cap)',
    'GNH5' => 'GNH5: the first in-zone request meets a failing budget operation; it is a typed BackendFailureException that the REAL engine turns into the Login FAIL_CLOSED decision, and after the breaker trips every decision comes from the DEC-015 degraded path (no NORMAL decision is taken without the budget)',
}, match ($arm) {
    'S0' => $failingStore->failures === 0 && count(array_filter($f1, static fn(string $d): bool => str_contains($d, 'ALLOW'))) === 4,
    'TH5' => $failingStore->failures === 0 && count(array_filter($f1, static fn(string $d): bool => str_contains($d, 'ALLOW'))) === 12,
    'GNH5' => $failingStore->failures > 0 && str_starts_with($f1[4], '4:HARD_BLOCK/FAIL_CLOSED') && array_filter($afterFirstFailure, static fn(string $d): bool => str_contains($d, '/NORMAL')) === [],
}, ['failures' => $failingStore->failures, 'decisions' => $f1]);

// R1 / R2 — cross-process rollback and mixed version (candidate arms only; S0 records a reference row).
$here = dirname(__DIR__);
$repo = dirname(__DIR__, 3);
$labRoot = getenv('LAB_ROOT') ?: $repo;
$work = sys_get_temp_dir() . '/gnh-g04-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($work);
/**
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
$child = static function (string $runtime, array $events, string $flow, string $state) use ($here, $repo, $labRoot, $arm, $work): array {
    static $n = 0;
    $n++;
    $evFile = "$work/ev$n.json";
    $outFile = "$work/out$n.json";
    file_put_contents($evFile, json_encode($events, JSON_THROW_ON_ERROR));
    $env = ['PATH' => getenv('PATH')];
    if ($runtime === 'S0') {
        $env['LAB_ROOT'] = $repo;
    } else {
        $env['LAB_ROOT'] = $labRoot;
        $env['GNH_ARM'] = $arm;
    }
    $p = proc_open([PHP_BINARY, "$here/scenarios/g04_child.php", $state, $evFile, $flow, $outFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $so = stream_get_contents($pipes[1]);
    $se = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    if ($rc !== 0 || $so !== '' || $se !== '' || ! is_file($outFile)) {
        fwrite(STDERR, "g04 child failed (rc $rc): $so $se\n");
        exit(2);
    }
    $out = json_decode((string) file_get_contents($outFile), true, 512, JSON_THROW_ON_ERROR);
    if ($out['arm'] !== $runtime) {
        fwrite(STDERR, "g04 child ran arm {$out['arm']}, expected $runtime\n");
        exit(2);
    }

    return $out;
};
$reach = static fn(array $rows): int => count(array_filter($rows, static fn(array $r): bool => $r['kind'] === 'attack' && $r['reached']));
$legDenied = static fn(array $rows): int => count(array_filter($rows, static fn(array $r): bool => $r['kind'] !== 'attack' && $r['blocked']));
if ($cand) {
    // R1 — rollback after 600 s of the candidate.
    $r1 = [];
    foreach (['ABA_A', 'XN1', 'D2'] as $id) {
        $state = "$work/r1-$id.state";
        $first = array_values(array_filter($streams[$id]['events'], static fn(array $e): bool => $e['t'] < 300));
        $second = array_values(array_filter($streams[$id]['events'], static fn(array $e): bool => $e['t'] >= 300 && $e['t'] < 900));
        $a = $child($arm, $first, 'pre+out', $state);
        $b = $child('S0', $second, 'pre+out', $state);
        $r1[$id] = ['candidateFirst300' => ['attackReached' => $reach($a['rows']), 'legitDenied' => $legDenied($a['rows']), 'error' => $a['error']], 's0After' => ['requests' => count($b['rows']), 'attackReached' => $reach($b['rows']), 'legitDenied' => $legDenied($b['rows']), 'error' => $b['error'] === null ? null : preg_replace('/[0-9a-f]{64}/', '<hash>', $b['error'])]];
    }
    emit('G04.R1.rollback', ['arm' => $arm, 'streams' => $r1]);
    $errors = array_filter(array_map(static fn(array $s): ?string => $s['s0After']['error'], $r1));
    GnhAsserts::check('G04', 'R1 rollback at t=300: the REAL unpatched S0 code continues on the state written by this arm without error (ABA_A, XN1, D2)', $errors === [], ['errors' => $errors]);

    // R2 — mixed version: even requests on this arm, odd requests on S0, one state; requests grouped in blocks of one event.
    $r2 = [];
    foreach (['ABA_A' => 240, 'SP1n' => 120, 'D2' => 600] as $id => $until) {
        $state = "$work/r2-$id.state";
        $rows = [];
        $error = null;
        foreach (array_values(array_filter($streams[$id]['events'], static fn(array $e): bool => $e['t'] < $until)) as $i => $e) {
            $o = $child($i % 2 === 0 ? $arm : 'S0', [$e], 'pre+out', $state);
            if ($o['error'] !== null) {
                $error = preg_replace('/[0-9a-f]{64}/', '<hash>', $o['error']);
                break;
            }
            $rows = array_merge($rows, $o['rows']);
        }
        $r2[$id] = ['until' => $until, 'requests' => count($rows), 'attackReached' => $reach($rows), 'legitDenied' => $legDenied($rows), 'error' => $error];
    }
    emit('G04.R2.mixed-version', ['arm' => $arm, 'streams' => $r2]);
    $errors = array_filter(array_map(static fn(array $s): ?string => $s['error'], $r2));
    GnhAsserts::check('G04', 'R2 mixed version: this arm and the REAL S0 code alternating per request on one state never error (ABA_A, SP1n, D2)', $errors === [], ['errors' => $errors]);
} else {
    emit('G04.R.reference', ['arm' => $arm, 'note' => 'rollback / mixed version are measured on the candidate arms (S0 code is the rollback target)']);
    GnhAsserts::check('G04', 'R1 S0 reference: not applicable on S0 (the rollback target itself)', true);
    GnhAsserts::check('G04', 'R2 S0 reference: not applicable on S0 (the old version itself)', true);
}
array_map('unlink', glob("$work/*") ?: []);
rmdir($work);
GnhAsserts::finish();
