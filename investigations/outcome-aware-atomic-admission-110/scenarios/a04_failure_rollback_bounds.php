<?php

declare(strict_types=1);

// A04 — storage bounds, public surface, backend failure through the REAL engine, rollback and mixed version (reference stores, Login).
//   S1  OAAA state at the end of six streams: tiers / tuples / credit sets, credit members <= 2, TTL <= 86 400 s, tuples <= granted reservations
//   S2  every OAAA key has expired 86 401 s after the stream; the S0-family keys alive at the end, both arms (informational)
//   P1  public surface by reflection (the committed patch adds only the experimental interface and its two methods on the Redis adapter)
//   F1  the OAAA operation fails (BackendFailureException) through the REAL RateLimiterEngine (circuit breaker + failure-mode resolver)
//   F2a whole correlation-store outage from t=2 (before any rule fired on either arm), REAL engine: the DEC-015 path, both arms
//   F2b the same outage from t=6 (S0 has already persisted its K1 block in the healthy rate-limit store; OAAA has admitted instead)
//   R1  rollback: OAAA runs the first 300 s, the REAL unpatched S0 code continues on the same state (child processes)
//   R2  mixed version: OAAA and S0 alternate per event on one state
//   R3  S0-family STATE parity after the same history (F115-01 lesson: "no exception" is not parity)
//   R4  rollback / mixed DECISION parity: the REAL S0 code after the OAAA history vs after an S0-only history, request for request
//   R5  roll-forward after a rollback: reservations taken before the rollback stay spent (no phantom refund, no reset)
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

emit('A04.provenance', oaaaProvenance());
$arm = oaaaArm();
$cand = $arm === 'OAAA';
$streams = oaaaStreams();
$refl = new \ReflectionClass(StatefulInMemoryCorrelationStore::class);
$s0Family = static function (Lab $lab) use ($refl): array {
    $now = $lab->clock->now()->getTimestamp();
    $n = 0;
    foreach (['sets', 'flags'] as $kind) {
        foreach ($refl->getProperty($kind)->getValue($lab->corr) as $st) {
            $n += $st['expiresAt'] !== null && $st['expiresAt'] > $now ? 1 : 0;
        }
    }

    return ['aliveS0FamilyKeys' => $n];
};

// S1 / S2
$s1 = [];
foreach (['D2', 'MIX', 'OWN4', 'SP3', 'D1', 'TGTM'] as $id) {
    $lab = oaaaLab();
    $rows = oaaaReplay($lab, $streams[$id]['events'], 'pre+out', 'login');
    $end = max(array_column($streams[$id]['events'], 't'));
    $granted = array_sum(array_column($rows, 'granted'));
    $row = ['grantedReservations' => $granted] + $s0Family($lab);
    if ($lab->corr instanceof OaaaStore) {
        $inv = $lab->corr->oaaaInventory();
        $row += ['oaaaAtEnd' => $inv, 'tuplesEverCreated' => count($lab->corr->tuples), 'tierKeysEverCreated' => count($lab->corr->tiers), 'creditKeysEverCreated' => count($lab->corr->credits)];
        $lab->at($end + 86401);
        $row['oaaaAliveAfterOneDay'] = $lab->corr->oaaaInventory();
    }
    $s1[$id] = $row;
}
emit('A04.S1.storage', ['arm' => $arm, 'streams' => $s1]);
if ($cand) {
    $ok = true;
    foreach ($s1 as $r) {
        $ok = $ok && $r['oaaaAtEnd']['maxCreditMembers'] <= OAAA_PARAMS['creditMembers'] && $r['oaaaAtEnd']['maxTtl'] <= OAAA_PARAMS['daySeconds']
            && $r['tuplesEverCreated'] <= $r['grantedReservations'] && $r['oaaaAliveAfterOneDay']['tiers'] + $r['oaaaAliveAfterOneDay']['tuples'] + $r['oaaaAliveAfterOneDay']['credits'] === 0
            && $r['oaaaAtEnd']['maxTierUnits'] <= OAAA_PARAMS['globalDayUnits'];
    }
    OaaaAsserts::check('A04', 'S1/S2 OAAA state is bounded: credit sets <= 2 members, every key TTL <= 86 400 s, tuples created <= granted reservations (no per-request key), tier units <= 48, and nothing is alive one day after the stream', $ok, $s1);
}

// P1 — public surface (reflection on the loaded runtime; report.php compares the arms).
$surface = static function (string $class): array {
    $r = new \ReflectionClass($class);
    $m = [];
    foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $m[] = ($method->isPublic() ? 'public ' : 'protected ') . $method->getName() . '(' . implode(',', array_map(static fn(\ReflectionParameter $p): string => (string) $p->getType() . ' $' . $p->getName(), $method->getParameters())) . ')';
    }
    sort($m);
    $i = $r->getInterfaceNames();
    sort($i);

    return ['methods' => $m, 'interfaces' => $i];
};
$p1 = [];
foreach ([\Maatify\RateLimiter\Service\EvaluationPipeline::class, \Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class, \Maatify\RateLimiter\Service\RateLimiterEngine::class, RateLimitCommand::class, \Maatify\RateLimiter\DTO\RateLimitContextDTO::class, \Maatify\RateLimiter\DTO\RateLimitResultDTO::class, \Maatify\RateLimiter\Builder\RateLimiterBuilder::class] as $class) {
    $p1[$class] = $surface($class);
}
$p1['experimentalInterface'] = interface_exists(OAAA_INTERFACE) ? $surface(OAAA_INTERFACE) : null;
emit('A04.P1.public-surface', ['arm' => $arm, 'surface' => $p1]);

// F1 / F2 — failures through the REAL engine.
// Each probe uses its OWN IP and account namespace: DEC-015's LocalFallbackLimiter keeps process-global (static) counters per IP / account,
// so a probe sharing them with an earlier one would inherit its fallback consumption.
$engineRun = static function (Lab $lab, callable $before, string $ip, string $tag) use ($arm): array {
    $emitter = new RecordingFailureSignalEmitter();
    $engine = new RateLimiterEngine($lab->resolver, $lab->pipeline, new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $lab->clock), new FailureModeResolver(), $emitter, $lab->clock, [$lab->policy]);
    $out = [];
    // 4 credited successes (pre+out), then from t=10 failing pre-checks on fresh victims (the spray rule fires from the 5th subject on).
    for ($t = 0; $t < 16; $t++) {
        $lab->at($t);
        $before($t);
        $acct = $t < 4 ? "{$tag}s$t" : "{$tag}v$t";
        $ctx = $lab->ctx($ip, k1Ua(103), $acct);
        $r = $engine->limit($ctx, RateLimitCommand::checkOnly($lab->policy->getName()));
        $d = $t . ':' . $r->decision . '/' . $r->failureMode . ($r->decision === 'ALLOW' ? '' : '/L' . $r->blockLevel . '/' . $r->retryAfter);
        if ($r->decision === 'ALLOW') {
            $o = $engine->limit($ctx, $t < 4 ? RateLimitCommand::recordSuccess($lab->policy->getName()) : RateLimitCommand::recordFailure($lab->policy->getName()));
            $d .= '>' . $o->decision . '/' . $o->failureMode;
        }
        $out[] = $d;
    }

    return $out;
};
$lab = oaaaLab();
$f1Failures = 0;
$f1 = $engineRun($lab, static function (int $t) use ($lab, &$f1Failures): void {
    if ($lab->corr instanceof OaaaStore) {
        $lab->corr->failOaaa = $t >= 6;
    }
}, '198.51.100.172', 'f1');
$degraded = count(array_filter($f1, static fn(string $d): bool => str_contains($d, ':ALLOW/DEGRADED_MODE')));
emit('A04.F1.oaaa-operation-failure', ['arm' => $arm, 'decisions' => $f1, 'degradedModeAllows' => $degraded]);
OaaaAsserts::check('A04', 'F1 ' . ($cand
    ? 'the failing OAAA operation is a typed BackendFailureException: the REAL engine fails closed first, then the DEC-015 DEGRADED_MODE path allows requests that no reservation admitted (the OAAA bound does not hold during the outage) and its local fallback limit denies the rest'
    : 'S0: no OAAA operation exists; the stream is decided as without failures'), $cand
    ? str_contains(implode(' ', $f1), 'HARD_BLOCK/FAIL_CLOSED') && $degraded > 0
    : ! str_contains(implode(' ', $f1), 'FAIL_CLOSED') && ! str_contains(implode(' ', $f1), 'DEGRADED'), ['decisions' => $f1, 'degradedModeAllows' => $degraded]);
$outage = static function (int $from, string $ip, string $tag) use ($engineRun): array {
    $lab = oaaaLab();
    if ($lab->corr instanceof OaaaStore) {
        $outageStore = $lab->corr;
    } else {
        $outageStore = new class ($lab->clock, 'A') extends K1Store {
            public bool $failAll = false;

            private function outage(): void
            {
                if ($this->failAll) {
                    throw new BackendFailureException('injected correlation-store outage');
                }
            }

            public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
            {
                $this->outage();

                return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
            }

            public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
            {
                $this->outage();

                return parent::addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
            }

            public function incrementWatchFlag(string $key, int $ttlSeconds): int
            {
                $this->outage();

                return parent::incrementWatchFlag($key, $ttlSeconds);
            }

            public function getWatchFlag(string $key): int
            {
                $this->outage();

                return parent::getWatchFlag($key);
            }
        };
        $lab->corr = $outageStore;
        gnhRewire($lab);
    }
    return $engineRun($lab, static function (int $t) use ($outageStore, $from): void {
        $outageStore->failAll = $t >= $from;
    }, $ip, $tag);
};
foreach (['F2a' => [2, '198.51.100.173'], 'F2b' => [6, '198.51.100.174']] as $id => [$from, $ip]) {
    $f2 = $outage($from, $ip, strtolower($id));
    emit("A04.$id.store-outage", ['arm' => $arm, 'outageFrom' => $from, 'decisions' => $f2, 'degradedModeAllows' => count(array_filter($f2, static fn(string $d): bool => str_contains($d, ':ALLOW/DEGRADED_MODE')))]);
}

// R1..R5 — cross-process rollback and mixed version (candidate arm only; S0 records a reference row).
$here = dirname(__DIR__);
$repo = dirname(__DIR__, 3);
$labRoot = getenv('LAB_ROOT') ?: $repo;
$work = sys_get_temp_dir() . '/oaaa-a04-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($work);
$child = static function (string $runtime, array $events, string $flow, string $state) use ($here, $repo, $labRoot, $work): array {
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
        $env['OAAA_ARM'] = 'OAAA';
    }
    $p = proc_open([PHP_BINARY, "$here/scenarios/a04_child.php", $state, $evFile, $flow, $outFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $so = stream_get_contents($pipes[1]);
    $se = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    if ($rc !== 0 || $so !== '' || $se !== '' || ! is_file($outFile)) {
        fwrite(STDERR, "a04 child failed (rc $rc): $so $se\n");
        exit(2);
    }
    $out = json_decode((string) file_get_contents($outFile), true, 512, JSON_THROW_ON_ERROR);
    if ($out['arm'] !== $runtime) {
        fwrite(STDERR, "a04 child ran arm {$out['arm']}, expected $runtime\n");
        exit(2);
    }

    return $out;
};
$reach = static fn(array $rows): int => count(array_filter($rows, static fn(array $r): bool => $r['kind'] === 'attack' && $r['reached']));
$legDenied = static fn(array $rows): int => count(array_filter($rows, static fn(array $r): bool => $r['kind'] !== 'attack' && $r['kind'] !== 'own' && $r['blocked']));
$slice = static fn(string $id, int $from, int $to): array => array_values(array_filter($streams[$id]["events"], static fn(array $e): bool => $e["t"] >= $from && $e["t"] < $to));
$clean = static fn(?string $e): ?string => $e === null ? null : preg_replace('/[0-9a-f]{64}/', '<hash>', $e);
if ($cand) {
    $r1 = [];
    foreach (['ABA_A', 'XN1', 'D2', 'MIX', 'OWN1', 'ABA_B'] as $id) {
        $state = "$work/r1-$id.state";
        $a = $child('OAAA', $slice($id, 0, 300), 'pre+out', $state);
        $b = $child('S0', $slice($id, 300, 900), 'pre+out', $state);
        $r1[$id] = ['oaaaFirst300' => ['attackReached' => $reach($a['rows']), 'legitDenied' => $legDenied($a['rows']), 'error' => $clean($a['error'])], 's0After' => ['requests' => count($b['rows']), 'attackReached' => $reach($b['rows']), 'legitDenied' => $legDenied($b['rows']), 'error' => $clean($b['error'])]];
    }
    emit('A04.R1.rollback', ['arm' => $arm, 'streams' => $r1]);
    $errors = array_filter(array_merge(array_column(array_column($r1, 's0After'), 'error'), array_column(array_column($r1, 'oaaaFirst300'), 'error')));
    OaaaAsserts::check('A04', 'R1 rollback at t=300: the REAL unpatched S0 code continues on the state written by OAAA without error (ABA_A, XN1, D2, MIX, OWN1, ABA_B)', $errors === [], ['errors' => array_values($errors)]);

    $r2 = [];
    foreach (['ABA_A' => 240, 'SP1n' => 120, 'D2' => 600, 'MIX' => 600] as $id => $until) {
        $state = "$work/r2-$id.state";
        $rows = [];
        $error = null;
        foreach ($slice($id, 0, $until) as $i => $e) {
            $o = $child($i % 2 === 0 ? 'OAAA' : 'S0', [$e], 'pre+out', $state);
            if ($o['error'] !== null) {
                $error = $clean($o['error']);
                break;
            }
            $rows = array_merge($rows, $o['rows']);
        }
        $r2[$id] = ['until' => $until, 'requests' => count($rows), 'attackReached' => $reach($rows), 'legitDenied' => $legDenied($rows), 'error' => $error];
    }
    emit('A04.R2.mixed-version', ['arm' => $arm, 'streams' => $r2]);
    $errors = array_filter(array_column($r2, 'error'));
    OaaaAsserts::check('A04', 'R2 mixed version: OAAA and the REAL S0 code alternating per request on one state never error (ABA_A, SP1n, D2, MIX)', $errors === [], ['errors' => array_values($errors)]);

    // R3 — S0-family state parity.
    $snap = static function (string $stateFile): array {
        [$clock, , $sets, $flags] = unserialize((string) file_get_contents($stateFile));
        $now = $clock->now()->getTimestamp();
        $out = [];
        foreach ($sets as $k => $st) {
            if ($st['expiresAt'] !== null && $st['expiresAt'] > $now) {
                $items = array_keys($st['items']);
                sort($items);
                $out['set:' . $k] = $items;
            }
        }
        foreach ($flags as $k => $st) {
            if ($st['expiresAt'] !== null && $st['expiresAt'] > $now) {
                $out['flag:' . $k] = $st['count'];
            }
        }
        ksort($out);

        return $out;
    };
    $compare = static function (array $s0, array $mine): array {
        $differ = 0;
        foreach (array_intersect_key($s0, $mine) as $k => $v) {
            $differ += $v === $mine[$k] ? 0 : 1;
        }
        $onlyS0 = count(array_diff_key($s0, $mine));
        $onlyMine = count(array_diff_key($mine, $s0));

        return ['s0Keys' => count($s0), 'oaaaKeys' => count($mine), 'onlyOnS0' => $onlyS0, 'onlyOnOaaa' => $onlyMine, 'sameKeyDifferentValue' => $differ, 'identical' => $onlyS0 + $onlyMine + $differ === 0];
    };
    $r3 = [];
    foreach (['ABA_B', 'D2', 'MIX', 'ABA_A', 'OWN1', 'Nu12'] as $id) {
        $sS0 = "$work/r3-s0-$id.state";
        $sArm = "$work/r3-oaaa-$id.state";
        $child('S0', $slice($id, 0, 300), 'pre+out', $sS0);
        $child('OAAA', $slice($id, 0, 300), 'pre+out', $sArm);
        $r3[$id . ' first 300 s'] = $compare($snap($sS0), $snap($sArm));
    }
    emit('A04.R3.s0-family-state-parity', ['arm' => $arm, 'histories' => $r3]);
    OaaaAsserts::check('A04', 'R3 S0-family state parity: after the same history the S0-family keys written by OAAA equal the S0 keys key for key (ABA_B, D2, MIX, ABA_A, OWN1, Nu12 first 300 s)', array_reduce($r3, static fn(bool $c, array $x): bool => $c && $x['identical'], true), ['identical' => array_map(static fn(array $x): bool => $x['identical'], $r3)]);

    // R4 — decision parity.
    $flags = static fn(array $rows): string => implode('', array_map(static fn(array $r): string => $r['blocked'] ? 'B' : 'a', $rows));
    $r4 = [];
    foreach (['ABA_B', 'D2', 'MIX', 'ABA_A', 'XN1', 'OWN1'] as $id) {
        $sRef = "$work/r4-ref-$id.state";
        $sMix = "$work/r4-oaaa-$id.state";
        $child('S0', $slice($id, 0, 300), 'pre+out', $sRef);
        $ref = $child('S0', $slice($id, 300, 900), 'pre+out', $sRef);
        $child('OAAA', $slice($id, 0, 300), 'pre+out', $sMix);
        $after = $child('S0', $slice($id, 300, 900), 'pre+out', $sMix);
        $fr = $flags($ref['rows']);
        $fa = $flags($after['rows']);
        $diff = 0;
        for ($i = 0, $n = min(strlen($fr), strlen($fa)); $i < $n; $i++) {
            $diff += $fr[$i] === $fa[$i] ? 0 : 1;
        }
        $r4["$id (OAAA 0..300 | S0 300..900)"] = ['requests' => strlen($fa), 's0OnlyHistory' => ['attackReached' => $reach($ref['rows']), 'legitDenied' => $legDenied($ref['rows'])], 'afterOaaa' => ['attackReached' => $reach($after['rows']), 'legitDenied' => $legDenied($after['rows'])], 'decisionsDiffer' => $diff, 'errors' => array_values(array_filter([$clean($ref['error']), $clean($after['error'])]))];
    }
    foreach (['ABA_A' => 240, 'SP1n' => 120, 'D2' => 600, 'MIX' => 600] as $id => $until) {
        $ref = $child('S0', $slice($id, 0, $until), 'pre+out', "$work/r4-mixref-$id.state");
        $r4["mixed $id (< $until s) vs S0-only"] = ['requests' => count($ref['rows']), 's0OnlyHistory' => ['attackReached' => $reach($ref['rows']), 'legitDenied' => $legDenied($ref['rows'])], 'mixed' => ['attackReached' => $r2[$id]['attackReached'], 'legitDenied' => $r2[$id]['legitDenied']], 'errors' => array_values(array_filter([$clean($ref['error']), $r2[$id]['error']]))];
    }
    emit('A04.R4.rollback-decision-parity', ['arm' => $arm, 'cases' => $r4]);
    $same = array_reduce(array_filter($r4, static fn(string $k): bool => ! str_starts_with($k, 'mixed'), ARRAY_FILTER_USE_KEY), static fn(bool $c, array $x): bool => $c && $x['decisionsDiffer'] === 0 && $x['errors'] === [], true);
    OaaaAsserts::check('A04', 'R4 rollback decision parity: after the OAAA history the REAL S0 code decides every remaining request exactly as after an S0-only history (ABA_B, D2, MIX, ABA_A, XN1, OWN1)', $same, ['decisionsDiffer' => array_map(static fn(array $x): ?int => $x['decisionsDiffer'] ?? null, $r4)]);

    // R5 — roll-forward: OAAA (5 credited successes + 2 pre-only reservations) -> S0 records outcomes for those two attempts -> OAAA again.
    $state = "$work/r5.state";
    $ev = [];
    for ($i = 0; $i < 5; $i++) {
        $ev[] = k1Ev($i, '198.51.100.175', null, "r5s$i", 'ok', 'legit', ['uaRaw' => k1Ua(105)]);
    }
    $child('OAAA', $ev, 'pre+out', $state);
    $p = $child('OAAA', [k1Ev(10, '198.51.100.175', 'r5a', 'r5p0', 'ok', 'legit', ['uaRaw' => k1Ua(105)]), k1Ev(11, '198.51.100.175', 'r5b', 'r5p1', 'ok', 'legit', ['uaRaw' => k1Ua(105)])], 'pre', $state);
    $s0Out = $child('S0', [k1Ev(20, '198.51.100.175', 'r5a', 'r5p0', 'ok', 'legit', ['uaRaw' => k1Ua(105)]), k1Ev(21, '198.51.100.175', 'r5b', 'r5p1', 'ok', 'legit', ['uaRaw' => k1Ua(105)])], 'out', $state);
    $forward = $child('OAAA', [k1Ev(30, '198.51.100.175', 'r5c', 'r5v', 'fail', 'attack', ['uaRaw' => k1Ua(105)])], 'pre+out', $state);
    [, , , , $oaaaState] = unserialize((string) file_get_contents($state));
    $units = [];
    foreach ($oaaaState['tiers'] as $k => $t) {
        if (str_starts_with($k, 'oaaa:v1:e:spray:')) {
            $units[] = $t['u'];
        }
    }
    $r5 = ['preOnlyGranted' => array_sum(array_column($p['rows'], 'granted')), 's0Outcomes' => implode('', array_map(static fn(array $r): string => $r['blocked'] ? 'B' : 'a', $s0Out['rows'])), 'rollForward' => $forward['rows'][0]['blocked'] ? 'B' : 'a', 'sprayEpochUnits' => $units];
    emit('A04.R5.roll-forward', ['arm' => $arm] + $r5);
    OaaaAsserts::check('A04', 'R5 roll-forward: two reservations taken before a rollback stay spent although S0 recorded successes for them (S0 never resolves OAAA reservations); the next OAAA failing attempt in the same epoch is refused (no phantom refund, no reset)', $r5['preOnlyGranted'] === 2 && $r5['rollForward'] === 'B' && $units === [2], $r5);
} else {
    emit('A04.R.reference', ['arm' => $arm, 'note' => 'rollback / mixed version are measured from the candidate arm (S0 code is the rollback target)']);
}
array_map('unlink', glob("$work/*") ?: []);
rmdir($work);
OaaaAsserts::finish();
