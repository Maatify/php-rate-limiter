<?php

declare(strict_types=1);

// Workstream B / Anti-Evasion — shared harness. Reuses the PR #103 Lab (REAL EvaluationPipeline + REAL DeviceIdentityResolver +
// reference stores + controlled clock) unchanged. LAB_ROOT selects the package tree under test:
//   S0 (baseline)  : the repository checkout (UNPATCHED src/, hash asserted below)
//   A1 / PW / P2   : the isolated patched tree made by build_candidate.sh, selected with env WSB_CANDIDATE
require dirname(__DIR__, 2) . '/workstream-b-correlation/lib/bootstrap.php';
require dirname(__DIR__, 2) . '/workstream-b-correlation/lib/replay.php';
require dirname(__DIR__) . '/fixtures/anti_evasion.php';

use Maatify\RateLimiter\Service\EvaluationPipeline;

const WSB_BASE_SHA = '56f601cdc9a7062391395c326f1867d472a4ee49';
const WSB_BASE_PIPELINE_SHA256 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';

function wsbVariant(): string
{
    $v = getenv('WSB_CANDIDATE');

    return $v === false || $v === '' ? 'S0' : $v;
}

/** Runtime-evidence level of the code path that actually executed (task §3: never merge these levels). */
function wsbLevel(): string
{
    return wsbVariant() === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME';
}

/**
 * Proves WHICH EvaluationPipeline was loaded. S0 must be byte-identical to the pinned baseline; a candidate must be the patched
 * copy living OUTSIDE the repository. A mismatch aborts the run (exit 4) instead of mislabelling evidence.
 *
 * @return array<string, mixed>
 */
function wsbProvenance(): array
{
    $file = (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = hash_file('sha256', (string) $file);
    // "inRepo" = the loaded file IS the repository's own src/ file (the candidate tree may live under results/work/ inside the repo dir).
    $repoPipeline = realpath(dirname(__DIR__, 3) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath((string) $file) === $repoPipeline;
    if (wsbVariant() === 'S0') {
        if ($sha !== WSB_BASE_PIPELINE_SHA256 || ! $inRepo) {
            fwrite(STDERR, "S0 provenance violation: EvaluationPipeline is not the pinned unpatched baseline ($sha)\n");
            exit(4);
        }
    } else {
        if ($sha === WSB_BASE_PIPELINE_SHA256 || $inRepo) {
            fwrite(STDERR, "candidate provenance violation: loaded pipeline is the baseline or the repository's own src file ($sha)\n");
            exit(4);
        }
    }

    return ['variant' => wsbVariant(), 'level' => wsbLevel(), 'baseSha' => WSB_BASE_SHA, 'pipelineSha256' => $sha, 'patched' => $sha !== WSB_BASE_PIPELINE_SHA256];
}

/** @var array<string, string> */
const WSB_UA = ['chrome126' => CHROME, 'chrome127' => CHROME_B];

/**
 * Policy shapes: login = Login with Host account id; login-anon = Login without account id; otp; api (API_OVERUSE, the Host only
 * calls recordSuccess, so every outcome is a success); api_custom = custom API_OVERUSE subclass.
 */
function wsbShapeOutcome(string $shape, string $outcome): string
{
    return in_array($shape, ['api', 'api_custom'], true) ? 'ok' : $outcome;
}

function wsbLabPolicy(string $shape): string
{
    return match ($shape) {
        'login', 'login-anon' => 'login',
        default => $shape,
    };
}

/**
 * Host-realistic replay with extra observables. $flow: pre+out | out | pre.
 *
 * @param list<array<string, mixed>> $events
 * @return array{events: list<array<string, mixed>>, keyScope: array<string, string>}
 */
function wsbReplay(Lab $lab, array $events, string $flow, string $shape): array
{
    $out = [];
    $keyScope = [];
    foreach ($events as $ev) {
        $lab->at($ev['t']);
        $acct = $shape === 'login-anon' || str_starts_with($shape, 'api') ? null : $ev['acct'];
        $c = $lab->ctx($ev['ip'], WSB_UA[$ev['ua']] ?? CHROME, $acct, $ev['fp'] === null ? null : fp($ev['fp']));
        $outcome = wsbShapeOutcome($shape, $ev['outcome']);
        $steps = [];
        $blocked = false;
        $reached = false;
        $retry = null;
        $level = null;
        if ($flow !== 'out') {
            $r = $lab->run($c, 'check');
            $steps[] = 'check:' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl'] . '/' . $r['retry']);
            $blocked = $r['d'] !== 'A';
            $reached = ! $blocked;
            if ($blocked) {
                $retry = $r['retry'];
                $level = $r['lvl'];
            }
        }
        if ($flow !== 'pre' && ! $blocked) {
            $r = $lab->run($c, $outcome);
            $steps[] = $outcome . ':' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl'] . '/' . $r['retry']);
            $blocked = $r['d'] !== 'A';
            $reached = true; // the Host already verified (record-only) or the check allowed it
            if ($blocked) {
                $retry = $r['retry'];
                $level = $r['lvl'];
            }
        }
        $now = $lab->clock->now()->getTimestamp();
        foreach ($lab->keys($c) as $name => $k) {
            if ($k !== null) {
                $keyScope[$k] = $name;
            }
        }
        $keys = $lab->keys($c);
        $rem = static function (Lab $lab, ?string $key, int $now): ?int {
            $b = $key === null ? null : $lab->store->checkBlock($key);

            return $b === null ? null : $b->expiresAt - $now;
        };
        $out[] = [
            't' => $ev['t'],
            'kind' => $ev['kind'],
            'outcome' => $outcome,
            'blocked' => $blocked,
            'reached' => $reached,
            'retry' => $retry,
            'level' => $level,
            'steps' => implode('>', $steps),
            'k2' => $rem($lab, $keys['K2'], $now),
            'k3' => $rem($lab, $keys['K3'], $now),
        ];
    }

    return ['events' => $out, 'keyScope' => $keyScope];
}

/**
 * Full attack-vs-legitimate measurement of one (stream, shape, flow) on the loaded runtime, plus a paired
 * "churn/dilution correlation disabled" control run used ONLY to attribute denials to correlation (counterfactual).
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function wsbMeasure(array $events, string $shape, string $flow): array
{
    // WSB_SELFTEST_CORR_OFF=1 (run.sh self-test only): the "live" run uses the correlation-disabled store, simulating a regression
    // that silently removes churn/dilution protection. The S0 invariants asserted in A01 must then FAIL.
    $lab = new Lab(wsbLabPolicy($shape), null, getenv('WSB_SELFTEST_CORR_OFF') === '1');
    $on = wsbReplay($lab, $events, $flow, $shape);
    $ctl = wsbReplay(new Lab(wsbLabPolicy($shape), null, true), $events, $flow, $shape);

    $issued = [];
    foreach ($lab->store->cycleLog as $c) {
        $scope = $on['keyScope'][$c['key']] ?? '?';
        $issued[$scope] = ($issued[$scope] ?? 0) + 1;
    }
    ksort($issued);

    $atk = ['n' => 0, 'reached' => 0, 'denied' => 0, 'deniedByCorrelation' => 0, 'deniedOther' => 0, 'firstDenyT' => null, 'lastDenyT' => null];
    $leg = ['n' => 0, 'denied' => 0, 'deniedByCorrelation' => 0, 'deniedOther' => 0, 'firstDenyT' => null, 'lastDenyT' => null];
    $retries = [];
    $levels = [];
    $firstRetry = null;
    foreach ($on['events'] as $i => $r) {
        $isAtk = $r['kind'] === 'attack';
        $bucket = &$leg;
        if ($isAtk) {
            $bucket = &$atk;
        }
        $bucket['n']++;
        if ($isAtk && $r['reached']) {
            $bucket['reached']++;
        }
        if ($r['blocked']) {
            $bucket['denied']++;
            $byCorr = ! $ctl['events'][$i]['blocked'];
            $bucket[$byCorr ? 'deniedByCorrelation' : 'deniedOther']++;
            $bucket['firstDenyT'] ??= $r['t'];
            $bucket['lastDenyT'] = $r['t'];
            $retries[$r['retry']] = true;
            $levels[$r['level']] = true;
            $firstRetry ??= $r['retry'];
        }
        unset($bucket);
    }
    ksort($retries);
    ksort($levels);
    $legitAllowedAfter = null;
    if ($leg['lastDenyT'] !== null) {
        foreach ($on['events'] as $r) {
            if ($r['kind'] === 'legit' && $r['t'] > $leg['lastDenyT'] && ! $r['blocked']) {
                $legitAllowedAfter = $r['t'];
                break;
            }
        }
    }

    return [
        'attackAttempts' => $atk['n'],
        'attackReachedVerification' => $atk['reached'],
        'attackDenied' => $atk['denied'],
        'attackDeniedByCorrelation' => $atk['deniedByCorrelation'],
        'attackDeniedOther' => $atk['deniedOther'],
        'attackFirstDenyT' => $atk['firstDenyT'],
        'legitAttempts' => $leg['n'],
        'legitDenied' => $leg['denied'],
        'legitDeniedByCorrelation' => $leg['deniedByCorrelation'],
        'legitDeniedOther' => $leg['deniedOther'],
        'legitFirstDenyT' => $leg['firstDenyT'],
        'legitLastDenyT' => $leg['lastDenyT'],
        'legitFirstAllowedAfterLastDeny' => $legitAllowedAfter,
        'blockIssuancesByScope' => $issued,
        'blockIssuancesTotal' => array_sum($issued),
        'firstDenialRetryAfter' => $firstRetry,
        'denialRetryAfterValues' => array_keys($retries),
        'denialLevels' => array_keys($levels),
        'controlLegitDenied' => count(array_filter($ctl['events'], static fn(array $r): bool => $r['kind'] === 'legit' && $r['blocked'])),
        'controlAttackReachedVerification' => count(array_filter($ctl['events'], static fn(array $r): bool => $r['kind'] === 'attack' && $r['reached'])),
    ];
}

/** Assertion helper: emits one machine-readable row and counts failures; scripts exit non-zero when any assertion failed. */
final class WsbAsserts
{
    public static int $failed = 0;

    /** @param array<string, mixed> $data */
    public static function check(string $scenario, string $name, bool $ok, array $data = []): void
    {
        if (! $ok) {
            self::$failed++;
        }
        emit($scenario, ['variant' => wsbVariant(), 'assert' => $name, 'ok' => $ok] + $data);
    }

    /**
     * S0 assertions are baseline invariants (the unmodified runtime must satisfy them): a failure is a harness/regression error
     * and exits non-zero. For a candidate runtime a failed assertion is a FINDING (a gate the candidate does not meet): it is
     * recorded with ok:false in the golden output and diffed by run.sh, and the script still exits 0.
     * WSB_STRICT=1 (used by the self-test stage) makes every failed assertion fatal.
     */
    public static function finish(): void
    {
        $strict = getenv('WSB_STRICT') === '1' || wsbVariant() === 'S0';
        exit($strict && self::$failed > 0 ? 1 : 0);
    }
}

/**
 * Active (unexpired) entries of the in-memory reference correlation store, read by reflection (the store exposes no inventory).
 *
 * @return array{sets: int, flags: int, members: int, maxSet: int}
 */
function wsbStoreStats(Lab $lab, ?int $at = null): array
{
    $now = $at ?? $lab->clock->now()->getTimestamp();
    $ref = new \ReflectionClass(\Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore::class);
    $sets = $ref->getProperty('sets')->getValue($lab->corr);
    $flags = $ref->getProperty('flags')->getValue($lab->corr);
    $n = 0;
    $members = 0;
    $max = 0;
    foreach ($sets as $s) {
        if ($s['expiresAt'] !== null && $s['expiresAt'] > $now) {
            $n++;
            $members += count($s['items']);
            $max = max($max, count($s['items']));
        }
    }
    $f = 0;
    foreach ($flags as $s) {
        if ($s['expiresAt'] !== null && $s['expiresAt'] > $now) {
            $f++;
        }
    }

    return ['sets' => $n, 'flags' => $f, 'members' => $members, 'maxSet' => $max];
}

/**
 * Compact run of per-second probes: [t, blocked] -> "a0-61,B62-70" style string and counts. Used by the boundary scenarios.
 *
 * @param list<array{t: int, blocked: bool}> $rows
 */
function wsbRuns(array $rows): string
{
    $out = [];
    $cur = null;
    foreach ($rows as $r) {
        if ($cur === null || $cur['b'] !== $r['blocked']) {
            if ($cur !== null) {
                $out[] = ($cur['b'] ? 'B' : 'a') . "{$cur['from']}-{$cur['to']}";
            }
            $cur = ['b' => $r['blocked'], 'from' => $r['t'], 'to' => $r['t']];
        } else {
            $cur['to'] = $r['t'];
        }
    }
    if ($cur !== null) {
        $out[] = ($cur['b'] ? 'B' : 'a') . "{$cur['from']}-{$cur['to']}";
    }

    return implode(',', $out);
}

/**
 * Correlation store that records, per (ttl, declared cap) class, how many bounded-set writes were made and the largest logical count
 * returned. It lets the cap assertions distinguish the DECLARED cap of each state class (a candidate that "removes the cap" declares
 * a larger one) from the sets the reference store happens to hold. Optionally disables ONLY the 600 s churn / dilution sets
 * (the same control as ChurnDilutionDisabledStore) so enforcement cannot short-circuit the storage write under test.
 */
final class CapRecordingStore extends CountingCorrelationStore
{
    /** @var array<string, array{calls: int, maxCount: int}> */
    public array $classes = [];

    public function __construct(\Maatify\SharedCommon\Contracts\ClockInterface $clock, private readonly bool $disableChurnDilution = false)
    {
        parent::__construct($clock);
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        if ($this->disableChurnDilution && $ttlSeconds === 600 && ($maxDistinct === 3 || $maxDistinct === 6)) {
            $this->distinctCalls++;

            return new \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO(1, true);
        }
        $r = parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
        $cls = "$ttlSeconds/$maxDistinct";
        $this->classes[$cls] ??= ['calls' => 0, 'maxCount' => 0];
        $this->classes[$cls]['calls']++;
        $this->classes[$cls]['maxCount'] = max($this->classes[$cls]['maxCount'], $r->count);

        return $r;
    }
}

/** A Lab whose correlation store is a CapRecordingStore (Lab is final; its stores and pipeline are public, so rebuild the pipeline). */
function wsbRecordingLab(string $policy, bool $disableChurnDilution): Lab
{
    $lab = new Lab($policy);
    $corr = new CapRecordingStore($lab->clock, $disableChurnDilution);
    $lab->corr = $corr;
    $lab->pipeline = new \Maatify\RateLimiter\Service\EvaluationPipeline(
        $lab->store,
        $corr,
        new \Maatify\RateLimiter\Service\BudgetTracker($lab->store, $lab->clock),
        new \Maatify\RateLimiter\Service\AntiEquilibriumGate($corr),
        new \Maatify\RateLimiter\Service\DecayCalculator($lab->clock),
        new \Maatify\RateLimiter\Service\EphemeralBucket($corr),
        Lab::SECRET,
        'prod',
        $lab->clock,
    );

    return $lab;
}

/**
 * Self-test material (run.sh only): a Lab whose EVALUATION PIPELINE sees a clock one second BEHIND the store clock. Persisted hard
 * blocks are stamped with the pipeline's `now`, so as the store (and the harness) see them every block ends 1 s earlier — the exact
 * block boundary moves by one second. The exact-boundary assertions of A02 must FAIL under this mutation.
 */
function wsbShiftedBlockLab(string $policy): Lab
{
    $lab = new Lab($policy);
    $ahead = new class ($lab->clock) implements \Maatify\SharedCommon\Contracts\ClockInterface {
        public function __construct(private readonly \Maatify\SharedCommon\Contracts\ClockInterface $inner)
        {
        }

        public function now(): \DateTimeImmutable
        {
            return $this->inner->now()->modify('-1 second');
        }

        public function sleep(int $seconds): void
        {
        }

        public function getTimezone(): \DateTimeZone
        {
            return $this->inner->getTimezone();
        }
    };
    $lab->pipeline = new \Maatify\RateLimiter\Service\EvaluationPipeline(
        $lab->store,
        $lab->corr,
        new \Maatify\RateLimiter\Service\BudgetTracker($lab->store, $ahead),
        new \Maatify\RateLimiter\Service\AntiEquilibriumGate($lab->corr),
        new \Maatify\RateLimiter\Service\DecayCalculator($ahead),
        new \Maatify\RateLimiter\Service\EphemeralBucket($lab->corr),
        Lab::SECRET,
        'prod',
        $ahead,
    );

    return $lab;
}
