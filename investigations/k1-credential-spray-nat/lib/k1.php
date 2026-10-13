<?php

declare(strict_types=1);

// #106 — K1 credential-spray / shared-NAT evidence harness. EVIDENCE ONLY: not part of the package runtime.
//
// It reuses, UNCHANGED, the PR #103 Lab / PR #104 wsb harness / PR #105 D-streams (REAL EvaluationPipeline + REAL DeviceIdentityResolver
// + reference stores + controlled clock). The pipeline under test is ALWAYS the repository's unpatched src/ (S0): the isolated controls
// below live entirely in the CORRELATION STORE, which is an injected dependency of the pipeline:
//
//   arm A  S0 as shipped
//   arm B  TEST ONLY: churn (600 s / cap 3) and dilution (600 s / cap 6) bounded sets disabled
//   arm C  TEST ONLY: K1 credential-spray bounded sets (600 s / cap 5: base K1, current/bridge/previous rotation and the IPv6 macro
//          scopes — no other pipeline state uses that (ttl, cap) pair) disabled
//   arm D  TEST ONLY: both disabled
//
// Every other protection (K1..K5 scores, K4 budgets/anti-equilibrium, device caps, distributed-account snapshots, flood, persisted blocks,
// DEC-003 cycles) stays the real code. A disabled set answers `count 1, accepted`, i.e. "no distinct evidence", which can never reach
// the 4/5 WATCH boundary or the 5/5 threshold.
require_once dirname(__DIR__, 2) . '/workstream-b-anti-evasion/design/lib/dsg.php';

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const K1_BASELINE_SHA = '4d9495ded05f647ce0421352781ce948ceeaa606';
const K1_ARMS = ['A', 'B', 'C', 'D'];
const K1_ARM_TITLE = [
    'A' => 'S0 (all protections)',
    'B' => 'churn+dilution off (TEST ONLY)',
    'C' => 'K1 spray off (TEST ONLY)',
    'D' => 'both off (TEST ONLY)',
];

/**
 * Proves WHICH pipeline ran: the repository's own, unpatched EvaluationPipeline (byte-identical to the pinned baseline hash). LAB_ROOT must
 * be absent or the repository itself; the arms never load a patched tree. Any mismatch aborts with exit 4.
 *
 * @return array<string, mixed>
 */
function k1Provenance(): array
{
    $file = (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = hash_file('sha256', (string) $file);
    $repoPipeline = realpath(dirname(__DIR__, 3) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath((string) $file) === $repoPipeline;
    $override = getenv('WSB_CANDIDATE');
    if ($sha !== WSB_BASE_PIPELINE_SHA256 || ! $inRepo || ($override !== false && $override !== '')) {
        fwrite(STDERR, "K1 provenance violation: loaded EvaluationPipeline is not the repository's unpatched baseline ($sha)\n");
        exit(4);
    }

    return ['level' => 'S0_RUNTIME_OBSERVED', 'baseSha' => K1_BASELINE_SHA, 'pipelineSha256' => $sha, 'patched' => false];
}

/**
 * Correlation store with (a) the TEST-ONLY arm controls and (b) a per-request signal log of what the correlation machinery observed.
 * Signals are recorded from store calls only (the public result exposes no winning source).
 */
class K1Store extends CountingCorrelationStore
{
    /** @var array{spray: int, sprayMet: bool, churnMet: bool, dilMet: bool, distMet: bool, sprayOps: int} */
    public array $sig = ['spray' => 0, 'sprayMet' => false, 'churnMet' => false, 'dilMet' => false, 'distMet' => false, 'sprayOps' => 0];

    /** lifetime counters of bounded-set writes by (ttl/cap) class — resource and "no double observation" evidence */
    /** @var array<string, int> */
    public array $classCalls = [];

    private string $lastClass = '';

    public function __construct(\Maatify\SharedCommon\Contracts\ClockInterface $clock, public readonly string $arm = 'A')
    {
        parent::__construct($clock);
    }

    public function resetSignals(): void
    {
        $this->sig = ['spray' => 0, 'sprayMet' => false, 'churnMet' => false, 'dilMet' => false, 'distMet' => false, 'sprayOps' => 0];
        $this->lastClass = '';
    }

    private function disabled(int $ttl, int $cap): bool
    {
        if ($ttl !== 600) {
            return false;
        }
        // run.sh self-test only: a broken control (arm C silently not disabling spray) must be DETECTED by the control-effectiveness assertions.
        if (getenv('K1_SELFTEST_C_NOOP') === '1' && ($this->arm === 'C' || $this->arm === 'D')) {
            return false;
        }

        return match ($this->arm) {
            'B' => $cap === 3 || $cap === 6,
            'C' => $cap === 5,
            'D' => $cap === 3 || $cap === 5 || $cap === 6,
            default => false,
        };
    }

    private function note(int $ttl, int $cap, int $count): void
    {
        $cls = "$ttl/$cap";
        $this->lastClass = $cls;
        $this->classCalls[$cls] = ($this->classCalls[$cls] ?? 0) + 1;
        if ($cls === '600/5') {
            $this->sig['spray'] = max($this->sig['spray'], $count);
            $this->sig['sprayOps']++;
            $this->sig['sprayMet'] = $this->sig['sprayMet'] || $count >= 5;
        } elseif ($cls === '600/3') {
            $this->sig['churnMet'] = $this->sig['churnMet'] || $count >= 3;
        } elseif ($cls === '600/6') {
            $this->sig['dilMet'] = $this->sig['dilMet'] || $count >= 6;
        } elseif ($cls === '600/4') {
            $this->sig['distMet'] = $this->sig['distMet'] || $count >= 4;
        }
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        if ($this->disabled($ttlSeconds, $maxDistinct)) {
            $this->distinctCalls++;
            $this->note($ttlSeconds, $maxDistinct, 1);

            return new BoundedDistinctResultDTO(1, true);
        }
        // run.sh self-test only: the 600 s spray window moved by one second (599 s). The exact-boundary assertions of K03 must FAIL under it.
        $effective = getenv('K1_SELFTEST_TTL_SHIFT') === '1' && $ttlSeconds === 600 && $maxDistinct === 5 ? 599 : $ttlSeconds;
        $r = parent::addDistinctBounded($key, $item, $effective, $maxDistinct);
        $this->note($ttlSeconds, $maxDistinct, $r->count);

        return $r;
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        if ($this->disabled($ttlSeconds, $maxDistinct)) {
            $this->distinctCalls++;
            $this->note($ttlSeconds, $maxDistinct, 1);

            return new BoundedDistinctResultDTO(1, true);
        }
        $r = parent::addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
        $this->note($ttlSeconds, $maxDistinct, $r->count);

        return $r;
    }

    private function noteWatch(string $key, int $count, int $ttl): void
    {
        // Only the 1800 s WATCH flags that directly follow a bounded-set observation belong to that set's rule (spray / churn / dilution /
        // distributed). The 900 s flood-stage flag and the score WATCH ("watch:" keys) are other rules and must not inherit a stale class.
        if ($ttl !== 1800) {
            return;
        }
        $cls = str_starts_with($key, 'credential_spray:') ? '600/5' : (str_starts_with($key, 'watch:') ? '' : $this->lastClass);
        if ($count < 2) {
            return;
        }
        if ($cls === '600/5') {
            $this->sig['sprayMet'] = true;
        } elseif ($cls === '600/3') {
            $this->sig['churnMet'] = true;
        } elseif ($cls === '600/6') {
            $this->sig['dilMet'] = true;
        } elseif ($cls === '600/4') {
            $this->sig['distMet'] = true;
        }
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $r = parent::addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
        $this->note($ttlSeconds, $maxDistinct, $r->count);

        return $r;
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $r = parent::addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
        $this->note($ttlSeconds, $maxDistinct, $r->count);

        return $r;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $n = parent::incrementWatchFlag($key, $ttlSeconds);
        $this->noteWatch($key, $n, $ttlSeconds);

        return $n;
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        $n = parent::incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
        $this->noteWatch($currentKey, $n, $ttlSeconds);

        return $n;
    }
}

/**
 * Lab (REAL pipeline, REAL resolver, reference stores, controlled clock) with a K1Store of the given arm. With $share the stores and the
 * clock of an earlier Lab are reused (key-rotation scenarios), keeping that Lab's arm.
 */
function k1Lab(string $arm, string $policy = 'login', ?string $previousSecret = null, string $secret = Lab::SECRET, ?Lab $share = null, ?bool $fingerprintRotation = null): Lab
{
    $lab = new Lab($policy, $previousSecret, false, $secret, $share, $fingerprintRotation);
    $corr = $share !== null ? $share->corr : new K1Store($lab->clock, $arm);
    if ($share === null) {
        $lab->corr = $corr;
    }
    $clock = $lab->clock;
    $lab->pipeline = new EvaluationPipeline(
        $lab->store,
        $corr,
        new \Maatify\RateLimiter\Service\BudgetTracker($lab->store, $clock),
        new \Maatify\RateLimiter\Service\AntiEquilibriumGate($corr),
        new \Maatify\RateLimiter\Service\DecayCalculator($clock),
        new \Maatify\RateLimiter\Service\EphemeralBucket($corr),
        $secret,
        'prod',
        $clock,
        $previousSecret,
    );

    return $lab;
}

function k1Store(Lab $lab): K1Store
{
    assert($lab->corr instanceof K1Store);

    return $lab->corr;
}

/**
 * Event => request context. Optional event keys: trusted (bool), sess (string), corr (string), ip6 handled by the caller.
 * $shape: login (account id) | login-anon (no account id) | otp (account id; policy chosen by the Lab).
 *
 * @param array<string, mixed> $ev
 */
function k1Ctx(array $ev, string $shape): RateLimitContextDTO
{
    $acct = $shape === 'login-anon' ? null : ($ev['acct'] ?? null);
    $trusted = (bool) ($ev['trusted'] ?? false);
    $sess = $trusted ? (string) ($ev['sess'] ?? 'sess-' . ($ev['acct'] ?? 'x')) : ($ev['sess'] ?? null);

    return new RateLimitContextDTO(
        $ev['ip'],
        $ev['uaRaw'] ?? (WSB_UA[$ev['ua'] ?? 'chrome126'] ?? CHROME),
        $acct,
        ($ev['fp'] ?? null) === null ? null : fp((string) $ev['fp']),
        $sess,
        $trusted,
        [],
        (bool) ($ev['verified'] ?? false),
        $ev['corr'] ?? null,
    );
}

function k1Scope(Lab $lab, RateLimitContextDTO $c): array
{
    $out = [];
    foreach ($lab->keys($c) as $name => $k) {
        if ($k !== null) {
            $out[$k] = $name;
        }
    }

    return $out;
}

/**
 * Host-realistic replay with per-request attribution.
 *
 * For every request: the persisted blocks that were ACTIVE on the request's canonical keys BEFORE it (a request denied by one of them is a
 * "persisted:Kn" denial), the blocks ISSUED by it (cycle log), and the correlation signals observed by the store during it. The origin of
 * a persisted block is the signal set of the request that issued it ("spray", "churn", "dil", combinations, or "score/other").
 *
 * flow: pre+out | pre | out   (Host calls checkOnly, then records the outcome only if allowed / checkOnly only / record-only)
 *
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function k1Replay(Lab $lab, array $events, string $flow, string $shape): array
{
    $store = k1Store($lab);
    $origin = [];
    $rows = [];
    foreach ($events as $ev) {
        $lab->at($ev['t']);
        $c = k1Ctx($ev, $shape);
        $outcome = wsbShapeOutcome($shape, $ev['outcome']);
        $scope = k1Scope($lab, $c);
        $now = $lab->clock->now()->getTimestamp();
        $before = [];
        foreach ($scope as $key => $name) {
            $b = $lab->store->checkBlock($key);
            if ($b !== null && $b->expiresAt > $now) {
                $before[] = $name;
            }
        }
        $store->resetSignals();
        $cyc0 = count($lab->store->cycleLog);
        $steps = [];
        $blocked = false;
        $reached = false;
        $retry = null;
        if ($flow !== 'out') {
            $r = $lab->run($c, 'check');
            $steps[] = 'check:' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl'] . '/' . $r['retry']);
            $blocked = $r['d'] !== 'A';
            $reached = ! $blocked;
            $retry = $blocked ? $r['retry'] : null;
        }
        if ($flow !== 'pre' && ! $blocked) {
            $r = $lab->run($c, $outcome);
            $steps[] = $outcome . ':' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl'] . '/' . $r['retry']);
            $blocked = $r['d'] !== 'A';
            $reached = true;
            $retry = $blocked ? $r['retry'] : null;
        }
        $sigs = [];
        if ($store->sig['sprayMet']) {
            $sigs[] = 'spray';
        }
        if ($store->sig['churnMet']) {
            $sigs[] = 'churn';
        }
        if ($store->sig['dilMet']) {
            $sigs[] = 'dil';
        }
        if ($store->sig['distMet']) {
            $sigs[] = 'dist';
        }
        $label = $sigs === [] ? 'score/other' : implode('+', $sigs);
        $issued = [];
        foreach (array_slice($lab->store->cycleLog, $cyc0) as $cy) {
            $name = $scope[$cy['key']] ?? 'K5*';
            $issued[] = $name;
            $origin[$cy['key']] = $label;
        }
        $src = null;
        if ($blocked) {
            if ($before !== []) {
                $parts = [];
                foreach ($scope as $key => $name) {
                    if (in_array($name, $before, true)) {
                        $parts[] = $name . '(' . ($origin[$key] ?? '?') . ')';
                    }
                }
                $src = 'persisted:' . implode('+', $parts);
            } elseif ($issued !== []) {
                $src = 'issued:' . implode('+', $issued) . '(' . $label . ')';
            } else {
                $src = 'unpersisted(' . $label . ')';
            }
        }
        $rows[] = [
            't' => $ev['t'],
            'kind' => $ev['kind'],
            'who' => $ev['who'] ?? null,
            'trusted' => (bool) ($ev['trusted'] ?? false),
            'subject' => $ev['corr'] ?? $ev['acct'] ?? null,
            'blocked' => $blocked,
            'reached' => $reached,
            'retry' => $retry,
            'steps' => implode('>', $steps),
            'src' => $src,
            'sprayCount' => $store->sig['spray'],
            'sprayMet' => $store->sig['sprayMet'],
            'sprayOps' => $store->sig['sprayOps'],
            'issued' => $issued,
        ];
    }

    return $rows;
}

/** Source label with the persisted-block origin collapsed to the enforcing key scopes + origin signal (stable aggregation key). */
function k1SourceBucket(?string $src): string
{
    return $src ?? 'allowed';
}

/**
 * One (stream, shape, flow) on one arm → compact aggregates. $rows come from k1Replay.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function k1Aggregate(array $rows): array
{
    $leg = ['n' => 0, 'denied' => 0];
    $atk = ['n' => 0, 'reached' => 0, 'denied' => 0];
    $bySrcLeg = [];
    $bySrcAtk = [];
    $victims = [];
    $firstLeg = null;
    $firstAtk = null;
    $issuedByScope = [];
    $retries = [];
    foreach ($rows as $r) {
        $isAtk = $r['kind'] === 'attack';
        if ($isAtk) {
            $atk['n']++;
            if ($r['reached']) {
                $atk['reached']++;
                $victims[(string) $r['subject']] = true;
            }
            if ($r['blocked']) {
                $atk['denied']++;
                $bySrcAtk[$r['src']] = ($bySrcAtk[$r['src']] ?? 0) + 1;
                $firstAtk ??= $r['t'];
            }
        } else {
            $leg['n']++;
            if ($r['blocked']) {
                $leg['denied']++;
                $bySrcLeg[$r['src']] = ($bySrcLeg[$r['src']] ?? 0) + 1;
                $firstLeg ??= $r['t'];
            }
        }
        if ($r['blocked']) {
            $retries[(int) $r['retry']] = ($retries[(int) $r['retry']] ?? 0) + 1;
        }
        foreach ($r['issued'] as $s) {
            $issuedByScope[$s] = ($issuedByScope[$s] ?? 0) + 1;
        }
    }
    ksort($bySrcLeg);
    ksort($bySrcAtk);
    ksort($issuedByScope);
    ksort($retries);

    return [
        'legitAttempts' => $leg['n'],
        'legitDenied' => $leg['denied'],
        'legitFirstDenyT' => $firstLeg,
        'legitDeniedBySource' => $bySrcLeg,
        'attackAttempts' => $atk['n'],
        'attackReachedVerification' => $atk['reached'],
        'attackDistinctSubjectsVerified' => count($victims),
        'attackDenied' => $atk['denied'],
        'attackFirstDenyT' => $firstAtk,
        'attackDeniedBySource' => $bySrcAtk,
        'blockIssuancesByScope' => $issuedByScope,
        'denialRetryAfter' => $retries,
    ];
}

/**
 * Per-index partition of the requests DENIED in arm A by which TEST-ONLY control frees them (paired counterfactual, same events, same
 * clock/scopes/budget). Path dependence is real (each arm has its own state); this is the same attribution method as PR #104/#105.
 *
 * @param array<string, list<array<string, mixed>>> $rowsByArm
 * @return array<string, int>
 */
function k1Partition(array $rowsByArm, string $kind): array
{
    $out = ['deniedA' => 0, 'sprayOnly(B denies,C frees)' => 0, 'corrOnly(C denies,B frees)' => 0, 'overlap(B,C each sufficient,D frees)' => 0, 'interaction(only A denies)' => 0, 'otherProtections(D denies)' => 0];
    foreach ($rowsByArm['A'] as $i => $a) {
        if (($kind === 'legit') === ($a['kind'] === 'attack') || ! $a['blocked']) {
            continue;
        }
        $out['deniedA']++;
        $b = $rowsByArm['B'][$i]['blocked'];
        $c = $rowsByArm['C'][$i]['blocked'];
        $d = $rowsByArm['D'][$i]['blocked'];
        if ($d) {
            $out['otherProtections(D denies)']++;
        } elseif ($b && $c) {
            $out['overlap(B,C each sufficient,D frees)']++;
        } elseif ($b) {
            $out['sprayOnly(B denies,C frees)']++;
        } elseif ($c) {
            $out['corrOnly(C denies,B frees)']++;
        } else {
            $out['interaction(only A denies)']++;
        }
    }

    return $out;
}

/**
 * Run one stream on every arm for (shape, flow) and return the arm aggregates + the legit/attacker partitions.
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function k1Cell(array $events, string $shape, string $flow): array
{
    $policy = $shape === 'otp' ? 'otp' : 'login';
    $rowsByArm = [];
    $agg = [];
    foreach (K1_ARMS as $arm) {
        $lab = k1Lab($arm, $policy);
        $rowsByArm[$arm] = k1Replay($lab, $events, $flow, $shape);
        $agg[$arm] = k1Aggregate($rowsByArm[$arm]);
        $agg[$arm]['sprayObservations'] = array_sum(array_column($rowsByArm[$arm], 'sprayOps'));
        $agg[$arm]['sprayThresholdRequests'] = count(array_filter($rowsByArm[$arm], static fn(array $r): bool => $r['sprayMet']));
    }

    return ['arms' => $agg, 'legitPartition' => k1Partition($rowsByArm, 'legit'), 'attackPartition' => k1Partition($rowsByArm, 'attack'), 'rows' => $rowsByArm];
}

/** A distinct (normalised) browser major per index: Chrome 100+$u. */
function k1Ua(int $u): string
{
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/' . (100 + $u) . '.0.0.0 Safari/537.36';
}

/** Deterministic event helper. */
function k1Ev(int $t, string $ip, ?string $fp, ?string $acct, string $outcome, string $kind, array $extra = []): array
{
    return ['t' => $t, 'ip' => $ip, 'ua' => 'chrome126', 'fp' => $fp, 'acct' => $acct, 'outcome' => $outcome, 'kind' => $kind] + $extra;
}

/** @param list<array<string, mixed>> $ev */
function k1Sort(array $ev): array
{
    usort($ev, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

    return $ev;
}

/**
 * K1-specific streams (deterministic; no wall clock, no mt_rand). D2 is the PR #105 stream used UNCHANGED.
 *
 * @return array<string, array{title: string, class: string, events: list<array<string, mixed>>}>
 */
function k1Streams(): array
{
    $S = [];
    $d2 = dsgStreams()['D2'];
    $S['D2'] = ['title' => $d2['title'], 'class' => 'benign', 'events' => $d2['events']];

    // Benign shared NAT, N colleagues, STABLE client fingerprint each (so K2 churn cannot fire — isolates K1), one login per 40 s on a
    // rotating colleague for 40 min, 8 % typos on the colleague's own account.
    foreach ([3, 4, 5, 6, 12] as $n) {
        $r = new Lcg(200 + $n);
        $ev = [];
        for ($t = 0, $i = 0; $t <= 2400; $t += 40, $i++) {
            $u = $i % $n;
            $ev[] = k1Ev($t, NAT, "stable$u", "col$u", $r->pct() <= 8 ? 'fail' : 'ok', 'legit', ['who' => "col$u"]);
        }
        $S["N$n"] = ['title' => "N$n shared NAT: $n colleagues, stable fingerprints, 1 login / 40 s, 8% typos, 40 min", 'class' => 'benign', 'events' => $ev];
    }

    // K1-ISOLATION CONTROL (not a realistic population): the same colleagues, but each runs a different browser major, so every K2
    // (IP + normalised UA) sees exactly ONE fingerprint and K2 churn / device dilution cannot fire. Whatever is denied here is K1 (or
    // an independent score/K4/K5 protection), never §5.3.
    foreach ([3, 4, 5, 6, 12] as $n) {
        $r = new Lcg(300 + $n);
        $ev = [];
        for ($t = 0, $i = 0; $t <= 2400; $t += 40, $i++) {
            $u = $i % $n;
            $ev[] = k1Ev($t, NAT, "stable$u", "col$u", $r->pct() <= 8 ? 'fail' : 'ok', 'legit', ['who' => "col$u", 'uaRaw' => k1Ua($u)]);
        }
        $S["Nu$n"] = ['title' => "Nu$n K1-isolation control: $n colleagues, each a different browser major (K2 cannot churn), 1 login / 40 s, 8% typos, 40 min", 'class' => 'benign', 'events' => $ev];
    }
    // 12 isolated colleagues on trusted / half-trusted devices; and the isolated office plus an untrusted spray attacker on the same NAT.
    $r = new Lcg(312);
    $uT = [];
    $uM = [];
    for ($t = 0, $i = 0; $t <= 2400; $t += 40, $i++) {
        $u = $i % 12;
        $o = $r->pct() <= 8 ? 'fail' : 'ok';
        $uT[] = k1Ev($t, NAT, "stable$u", "col$u", $o, 'legit', ['who' => "col$u", 'uaRaw' => k1Ua($u), 'trusted' => true, 'sess' => "sess-col$u"]);
        $uM[] = k1Ev($t, NAT, "stable$u", "col$u", $o, 'legit', ['who' => "col$u", 'uaRaw' => k1Ua($u), 'trusted' => $u % 2 === 0, 'sess' => "sess-col$u"]);
    }
    $S['Nu12T'] = ['title' => 'Nu12T K1-isolation control, all 12 colleagues on trusted session devices', 'class' => 'benign', 'events' => $uT];
    $S['Nu12M'] = ['title' => 'Nu12M K1-isolation control, 6 trusted + 6 untrusted colleagues', 'class' => 'benign', 'events' => $uM];
    $ev = $uT;
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev(300 + $i * 3, NAT, null, "mixuvictim$i", 'fail', 'attack', ['uaRaw' => k1Ua(50)]);
    }
    $S['MIXU'] = ['title' => 'MIXU 12 trusted isolated colleagues + an untrusted spray attacker (own UA) behind the same NAT, 200 victims from t=300', 'class' => 'both', 'events' => k1Sort($ev)];
    $ev = $S['Nu12']['events'];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev(300 + $i * 3, NAT, null, "mixu2victim$i", 'fail', 'attack', ['uaRaw' => k1Ua(50)]);
    }
    $S['MIXN'] = ['title' => 'MIXN 12 isolated UNTRUSTED colleagues + an untrusted spray attacker (own UA) behind the same NAT, 200 victims from t=300', 'class' => 'both', 'events' => k1Sort($ev)];

    // The same 12 colleagues, all on TRUSTED session devices, and half trusted / half not.
    $r = new Lcg(212);
    $trusted = [];
    $mixed = [];
    for ($t = 0, $i = 0; $t <= 2400; $t += 40, $i++) {
        $u = $i % 12;
        $o = $r->pct() <= 8 ? 'fail' : 'ok';
        $trusted[] = k1Ev($t, NAT, "stable$u", "col$u", $o, 'legit', ['who' => "col$u", 'trusted' => true, 'sess' => "sess-col$u"]);
        $mixed[] = k1Ev($t, NAT, "stable$u", "col$u", $o, 'legit', ['who' => "col$u", 'trusted' => $u % 2 === 0, 'sess' => "sess-col$u"]);
    }
    $S['N12T'] = ['title' => 'N12T the 12 colleagues, ALL on trusted session devices', 'class' => 'benign', 'events' => $trusted];
    $S['N12M'] = ['title' => 'N12M the 12 colleagues, half trusted / half untrusted', 'class' => 'benign', 'events' => $mixed];

    // Attackers. All fail by construction (the Host outcome is a failure: wrong password against someone else's account).
    // SP1f: classic password spray: 1 IP, fresh client fingerprint per request, 200 distinct victims, 1 attempt / 3 s.
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.7', "spf$i", "victim$i", 'fail', 'attack');
    }
    $S['SP1f'] = ['title' => 'SP1f password spray: 1 IP, fresh fingerprint per request, 200 victims, 1 / 3 s', 'class' => 'attack', 'events' => $ev];
    // SP1n: same, scripted HTTP client without a client fingerprint (LOW).
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.7', null, "victim$i", 'fail', 'attack');
    }
    $S['SP1n'] = ['title' => 'SP1n password spray: 1 IP, no fingerprint (LOW), 200 victims, 1 / 3 s', 'class' => 'attack', 'events' => $ev];
    // SP3: credential stuffing: 1 IP, no fingerprint, 1000 breached pairs, 1 / s.
    $ev = [];
    for ($i = 0; $i < 1000; $i++) {
        $ev[] = k1Ev($i, '198.51.100.9', null, "cs$i", 'fail', 'attack');
    }
    $S['SP3'] = ['title' => 'SP3 credential stuffing: 1 IP, no fingerprint, 1000 distinct breached accounts, 1 / s', 'class' => 'attack', 'events' => $ev];
    // SP6: sustained single-IP spray for one hour (1 new victim / 3 s, no fingerprint): the verification budget of one IP per hour under each arm.
    $ev = [];
    for ($i = 0; $i < 1200; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.17', null, "hv$i", 'fail', 'attack', ['uaRaw' => k1Ua(90)]);
    }
    $S['SP6'] = ['title' => 'SP6 sustained spray for 1 hour: 1 IP, own UA, no fingerprint, 1 new victim / 3 s (1200 victims)', 'class' => 'attack', 'events' => $ev];
    // SP2: low-and-slow N-1 hover: 4 victims per 601 s cycle for 3 h, 1 IP, no fingerprint.
    $ev = [];
    for ($c = 0; $c < 18; $c++) {
        for ($j = 0; $j < 4; $j++) {
            $ev[] = k1Ev($c * 601 + $j * 10, '198.51.100.11', null, "slow" . ($c * 4 + $j), 'fail', 'attack');
        }
    }
    $S['SP2'] = ['title' => 'SP2 slow spray hovering at 4/5: 4 new victims per 601 s cycle, 3 h, 1 IP', 'class' => 'attack', 'events' => $ev];
    // SP4: distributed spray: 8 IPs round-robin, each IP stays at 4 distinct victims per 601 s (invisible to a per-IP set), 3 h.
    $ev = [];
    for ($c = 0; $c < 18; $c++) {
        for ($ip = 0; $ip < 8; $ip++) {
            for ($j = 0; $j < 4; $j++) {
                $ev[] = k1Ev($c * 601 + $ip * 7 + $j * 30, "198.51.100." . (20 + $ip), null, "dist" . ($c * 32 + $ip * 4 + $j), 'fail', 'attack');
            }
        }
    }
    $S['SP4'] = ['title' => 'SP4 distributed spray: 8 IPs, same UA, each 4 new victims per 601 s, 3 h', 'class' => 'attack', 'events' => k1Sort($ev)];
    // SP4u: same, but every botnet IP presents its own browser major (no shared UA for dilution), and SP4b stays at 3 victims per IP per cycle.
    foreach ([4 => 'SP4u', 3 => 'SP4b'] as $per => $id) {
        $ev = [];
        for ($c = 0; $c < 18; $c++) {
            for ($ip = 0; $ip < 8; $ip++) {
                for ($j = 0; $j < $per; $j++) {
                    $ev[] = k1Ev($c * 601 + $ip * 7 + $j * 30, "198.51.100." . (20 + $ip), null, "dist$id" . ($c * 32 + $ip * 4 + $j), 'fail', 'attack', ['uaRaw' => k1Ua(60 + $ip)]);
                }
            }
        }
        $S[$id] = ['title' => "$id distributed spray: 8 IPs, own browser major each, $per new victims per IP per 601 s, 3 h", 'class' => 'attack', 'events' => k1Sort($ev)];
    }
    // MIX: the attacker sits BEHIND the same NAT as the 12 colleagues (D2 traffic + SP1n against the NAT address).
    $ev = $d2['events'];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev(300 + $i * 3, NAT, null, "mixvictim$i", 'fail', 'attack');
    }
    $S['MIX'] = ['title' => 'MIX D2 office + a spray attacker behind the SAME NAT (200 victims, 1 / 3 s from t=300)', 'class' => 'both', 'events' => k1Sort($ev)];
    // MIXT: same, the colleagues are trusted devices; the attacker is not.
    $ev = [];
    foreach ($S['N12T']['events'] as $e) {
        $ev[] = $e;
    }
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev(300 + $i * 3, NAT, null, "mixtvictim$i", 'fail', 'attack');
    }
    $S['MIXT'] = ['title' => 'MIXT 12 TRUSTED colleagues + an untrusted spray attacker behind the same NAT', 'class' => 'both', 'events' => k1Sort($ev)];
    // ATK_TRUSTED: a compromised trusted device sprays (Host marks its session trusted): K1 is advisory for it.
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.13', 'stolen', "tv$i", 'fail', 'attack', ['trusted' => true, 'sess' => 'sess-stolen']);
    }
    $S['SP5'] = ['title' => 'SP5 spray from a trusted session device (compromised/forged trust): 200 victims, 1 / 3 s', 'class' => 'attack', 'events' => $ev];

    return $S;
}

/** Deterministic JSON emission for a scenario row, sorted by key for stable goldens. */
function k1Emit(string $scenario, array $row): void
{
    emit($scenario, $row);
}

/** ASSERT helper that never lets a failing assertion pass silently: failures exit non-zero (S0 invariants). */
final class K1Asserts
{
    public static int $failed = 0;

    /** @param array<string, mixed> $data */
    public static function check(string $scenario, string $name, bool $ok, array $data = []): void
    {
        if (! $ok) {
            self::$failed++;
        }
        emit($scenario, ['assert' => $name, 'ok' => $ok] + $data);
    }

    public static function finish(): void
    {
        exit(self::$failed > 0 ? 1 : 0);
    }
}

/** Compact "t:flag" run encoding for probe sequences: reuses wsbRuns over [t, blocked]. */
function k1Runs(array $rows): string
{
    return wsbRuns(array_map(static fn(array $r): array => ['t' => $r['t'], 'blocked' => $r['blocked']], $rows));
}
