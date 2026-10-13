<?php

declare(strict_types=1);

// #110 Guarded Network Headroom (GNH) study harness. EVIDENCE ONLY: not part of the package runtime.
//
// Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + REAL DeviceIdentityResolver + reference stores + controlled clock), the
// PR #104 wsb streams, the PR #105 D streams, the PR #108 K1 harness (K1Store, K1 streams, k1Aggregate), the PR #111 #110 streams and the
// PR #113 trusted multi-session streams. The pipeline under test is selected by LAB_ROOT + env GNH_ARM:
//   S0     the repository's UNPATCHED src/ (sha-256 asserted) — GNH_ARM must be unset/empty
//   TH5    the isolated patched tree (build_candidate.sh): K1 spray / K2 churn / LOW dilution caps x5, WATCH at the new N-1, S0 keys
//   GNH5   the isolated patched tree: S0 rule on S0 keys + x5 rule on a separate namespace + per-scope fixed-window headroom budget
// The injected correlation store is GnhStore (K1Store in arm 'A' = no TEST-ONLY controls) with cap-aware signal attribution and a log of
// the budget increments: every protection decision is the real code; the store only observes.
require_once dirname(__DIR__, 2) . '/nat-remediation-110/lib/oqc.php';
require_once dirname(__DIR__, 2) . '/trusted-multi-session-s0/lib/tms.php';
require_once __DIR__ . '/cells.php';

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;

const GNH_BASELINE_SHA = '1ad9af289f83f86346667a014dd486f9c4b8fc08';
const GNH_BASE_PIPELINE_SHA256 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
const GNH_PATCHED_PIPELINE_SHA256 = 'd7413b9dc497a45d41dcf5f522b5e092e3a957149fad173a010d094fe80d657a';
const GNH_ARM_TITLE = [
    'S0' => 'S0 as shipped (unpatched)',
    'TH5' => 'TH5: K1/K2/LOW caps x5 only (control)',
    'GNH5' => 'GNH5: caps x5 + per-scope fixed-window headroom budget',
];

function gnhArm(): string
{
    $a = getenv('GNH_ARM');

    return $a === false || $a === '' ? 'S0' : $a;
}

/** Runtime-evidence level of the code path that actually executed (never merged across levels). */
function gnhLevel(): string
{
    return gnhArm() === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME';
}

/**
 * Proves WHICH EvaluationPipeline was loaded. S0 must be the repository's own unpatched file (pinned hash, GNH_ARM empty); a candidate arm
 * must be the patched copy (pinned hash) living OUTSIDE the repository's src/. A foreign study's arm variable must be absent. Any mismatch
 * aborts with exit 4.
 *
 * @return array<string, mixed>
 */
function gnhProvenance(): array
{
    $file = (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = hash_file('sha256', (string) $file);
    $repoPipeline = realpath(dirname(__DIR__, 3) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath((string) $file) === $repoPipeline;
    $arm = gnhArm();
    foreach (['OQC_ARM', 'WSB_CANDIDATE'] as $foreign) {
        $v = getenv($foreign);
        if ($v !== false && $v !== '') {
            fwrite(STDERR, "GNH provenance violation: $foreign is set ($v); this study's arm is GNH_ARM only\n");
            exit(4);
        }
    }
    if (! in_array($arm, GNH_ARMS_LIST, true)) {
        fwrite(STDERR, "GNH provenance violation: unknown arm '$arm'\n");
        exit(4);
    }
    if ($arm === 'S0') {
        if ($sha !== GNH_BASE_PIPELINE_SHA256 || ! $inRepo) {
            fwrite(STDERR, "S0 provenance violation: loaded EvaluationPipeline is not the repository's unpatched baseline ($sha)\n");
            exit(4);
        }
    } elseif ($sha !== GNH_PATCHED_PIPELINE_SHA256 || $inRepo) {
        fwrite(STDERR, "candidate provenance violation: loaded pipeline is not the pinned patched candidate or is the repository's own src file ($sha)\n");
        exit(4);
    }

    return ['arm' => $arm, 'level' => gnhLevel(), 'baseSha' => GNH_BASELINE_SHA, 'pipelineSha256' => $sha, 'patched' => $sha !== GNH_BASE_PIPELINE_SHA256];
}

/**
 * K1Store with cap-aware attribution for the three arms and a log of the GNH5 budget increments. It never changes a result.
 *   - a bounded set of the spray / churn / dilution rule is recognised by (ttl 600, cap in {5,25} / {3,15} / {6,30}); the distributed
 *     account rule only through the snapshot methods (cap 4), so the IPv6 /40 hierarchy set (also cap 4) is not mislabelled;
 *   - on arm GNH5 the S0-key sets are NOT enforcing (they mark the headroom ZONE); the x5 sets ("gnh5:x5:" keys) and an exhausted budget are;
 *   - a budget increment ("gnh5:budget:<rule>:" WATCH key) <= GNH_BUDGET_PER_RULE[rule] is one HEADROOM ADMISSION of that rule.
 */
class GnhStore extends K1Store
{
    private string $lastRule = '';

    /** TTL (seconds) each correlation key was written with — storage-bound evidence */
    /** @var array<string, int> */
    public array $ttlOf = [];

    /** lifetime log of every budget increment: [rule, value] */
    /** @var list<array{0: string, 1: int}> */
    public array $budgetLog = [];

    public function resetSignals(): void
    {
        parent::resetSignals();
        $this->sig['headroom'] = 0;
        $this->sig['budgetMax'] = 0;
        $this->sig['zone'] = [];
        $this->lastRule = '';
    }

    private static function setRule(int $ttl, int $cap): ?string
    {
        if ($ttl !== 600) {
            return null;
        }

        return match ($cap) {
            5, 25 => 'spray',
            3, 15 => 'churn',
            6, 30 => 'dilution',
            default => null,
        };
    }

    private function met(string $rule): void
    {
        $field = ['spray' => 'sprayMet', 'churn' => 'churnMet', 'dilution' => 'dilMet', 'dist' => 'distMet'][$rule];
        $this->sig[$field] = true;
    }

    private function noteSet(string $key, int $ttl, int $cap, int $count, ?string $rule): void
    {
        $this->ttlOf[$key] = $ttl;
        $cls = "$ttl/$cap";
        $this->classCalls[$cls] = ($this->classCalls[$cls] ?? 0) + 1;
        $this->lastRule = $rule ?? '';
        if ($rule === null) {
            return;
        }
        $x5 = str_starts_with($key, 'gnh5:x5:');
        if ($rule === 'spray' && ! $x5) {
            $this->sig['spray'] = max($this->sig['spray'], $count);
            $this->sig['sprayOps']++;
        }
        if ($count < $cap) {
            return;
        }
        if (gnhArm() !== 'GNH5' || $x5 || $rule === 'dist') {
            $this->met($rule);
        } else {
            $this->sig['zone'][$rule] = true;
        }
    }

    private function noteWatch(string $key, int $n, int $ttl): void
    {
        $this->ttlOf[$key] = $ttl;
        if (str_starts_with($key, 'gnh5:budget:')) {
            $rule = explode(':', $key)[2];
            $this->budgetLog[] = [$rule, $n];
            $this->sig['budgetMax'] = max($this->sig['budgetMax'], $n);
            if ($n > GNH_BUDGET_PER_RULE[$rule]) {
                $this->met($rule);
            } else {
                $this->sig['headroom']++;
            }

            return;
        }
        if ($ttl !== 1800 || $n < 2) {
            return;
        }
        $x5 = str_starts_with($key, 'gnh5:x5:');
        $bare = $x5 ? substr($key, 8) : $key;
        $rule = str_starts_with($bare, 'credential_spray:') ? 'spray' : (str_starts_with($bare, 'watch:') ? '' : $this->lastRule);
        if ($rule === '') {
            return;
        }
        if (gnhArm() !== 'GNH5' || $x5 || $rule === 'dist') {
            $this->met($rule);
        } else {
            $this->sig['zone'][$rule] = true;
        }
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        $r = CountingCorrelationStore::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
        $this->noteSet($key, $ttlSeconds, $maxDistinct, $r->count, self::setRule($ttlSeconds, $maxDistinct));

        return $r;
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        $r = StatefulInMemoryCorrelationStore::addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
        $this->noteSet($currentKey, $ttlSeconds, $maxDistinct, $r->count, self::setRule($ttlSeconds, $maxDistinct));

        return $r;
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        $r = StatefulInMemoryCorrelationStore::addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
        $this->noteSet($key, $ttlSeconds, $maxDistinct, $r->count, $ttlSeconds === 600 && $maxDistinct === 4 ? 'dist' : null);

        return $r;
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        $r = StatefulInMemoryCorrelationStore::addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
        $this->noteSet($currentKey, $ttlSeconds, $maxDistinct, $r->count, $ttlSeconds === 600 && $maxDistinct === 4 ? 'dist' : null);

        return $r;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $n = CountingCorrelationStore::incrementWatchFlag($key, $ttlSeconds);
        $this->noteWatch($key, $n, $ttlSeconds);

        return $n;
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        $n = StatefulInMemoryCorrelationStore::incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
        $this->noteWatch($currentKey, $n, $ttlSeconds);

        return $n;
    }
}

/** Lab (REAL pipeline, REAL resolver, reference stores, controlled clock) with a GnhStore. With $share the stores/clock of an earlier Lab are reused. */
function gnhLab(string $policy = 'login', ?string $previousSecret = null, string $secret = Lab::SECRET, ?Lab $share = null, ?bool $fingerprintRotation = null): Lab
{
    $lab = new Lab($policy, $previousSecret, false, $secret, $share, $fingerprintRotation);
    if ($share === null) {
        $lab->corr = new GnhStore($lab->clock, 'A');
    }

    return gnhRewire($lab, $secret, $previousSecret);
}

/** Rebuild the REAL pipeline of $lab over its (possibly replaced) stores and clock. */
function gnhRewire(Lab $lab, string $secret = Lab::SECRET, ?string $previousSecret = null): Lab
{
    $clock = $lab->clock;
    $corr = $lab->corr;
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

function gnhStore(Lab $lab): GnhStore
{
    assert($lab->corr instanceof GnhStore);

    return $lab->corr;
}

/** Alive correlation keys (sets + WATCH flags) and set members of the reference store at $now. @return array{0: int, 1: int} */
function gnhAlive(Lab $lab): array
{
    static $ref = null;
    $ref ??= new \ReflectionClass(StatefulInMemoryCorrelationStore::class);
    $now = $lab->clock->now()->getTimestamp();
    $keys = 0;
    $members = 0;
    foreach ($ref->getProperty('sets')->getValue($lab->corr) as $s) {
        if ($s['expiresAt'] !== null && $s['expiresAt'] > $now) {
            $keys++;
            $members += count($s['items']);
        }
    }
    foreach ($ref->getProperty('flags')->getValue($lab->corr) as $f) {
        if ($f['expiresAt'] !== null && $f['expiresAt'] > $now) {
            $keys++;
        }
    }

    return [$keys, $members];
}

/**
 * Host-realistic replay with per-request attribution — the PR #108 k1Replay method UNCHANGED (persisted blocks active before the request,
 * blocks issued by it from the DEC-003 cycle log, origin = correlation signals observed during it), extended with the final decision, the
 * level and Retry-After of a denial, the GNH5 headroom admissions / budget values of the request and the alive correlation keys after it.
 *
 * flow: pre+out | pre | out   (checkOnly then the outcome only if allowed / checkOnly only / record-only)
 *
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function gnhReplay(Lab $lab, array $events, string $flow, string $shape, bool $peak = true): array
{
    $store = gnhStore($lab);
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
        $level = null;
        $decision = 'ALLOW';
        $headroom = 0;
        $budgetMax = 0;
        if ($flow !== 'out') {
            $r = $lab->run($c, 'check');
            $headroom += $store->sig['headroom'];
            $budgetMax = max($budgetMax, $store->sig['budgetMax']);
            $steps[] = 'check:' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl'] . '/' . $r['retry']);
            $blocked = $r['d'] !== 'A';
            $reached = ! $blocked;
            if ($blocked) {
                [$retry, $level, $decision] = [$r['retry'], $r['lvl'], $r['d'] === 'H' ? 'HARD_BLOCK' : 'SOFT_BLOCK'];
            }
        }
        if ($flow !== 'pre' && ! $blocked) {
            $h0 = $store->sig['headroom'];
            $r = $lab->run($c, $outcome);
            $headroom += $store->sig['headroom'] - $h0;
            $budgetMax = max($budgetMax, $store->sig['budgetMax']);
            $steps[] = $outcome . ':' . ($r['d'] === 'A' ? 'A' : $r['d'] . $r['lvl'] . '/' . $r['retry']);
            $blocked = $r['d'] !== 'A';
            $reached = true;
            if ($blocked) {
                [$retry, $level, $decision] = [$r['retry'], $r['lvl'], $r['d'] === 'H' ? 'HARD_BLOCK' : 'SOFT_BLOCK'];
            }
        }
        $sigs = [];
        foreach (['sprayMet' => 'spray', 'churnMet' => 'churn', 'dilMet' => 'dil', 'distMet' => 'dist'] as $f => $label) {
            if ($store->sig[$f]) {
                $sigs[] = $label;
            }
        }
        $label = $sigs === [] ? 'score/other' : implode('+', $sigs);
        $issued = [];
        $issuedKeys = [];
        foreach (array_slice($lab->store->cycleLog, $cyc0) as $cy) {
            $name = $scope[$cy['key']] ?? 'K5*';
            $issued[] = $name;
            $issuedKeys[] = $cy['key'];
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
        [$aliveKeys, $aliveMembers] = $peak ? gnhAlive($lab) : [0, 0];
        $rows[] = [
            't' => $ev['t'],
            'kind' => $ev['kind'],
            'who' => $ev['who'] ?? null,
            'trusted' => (bool) ($ev['trusted'] ?? false),
            'subject' => $ev['corr'] ?? $ev['acct'] ?? null,
            'blocked' => $blocked,
            'reached' => $reached,
            'retry' => $retry,
            'level' => $level,
            'decision' => $decision,
            'steps' => implode('>', $steps),
            'src' => $src,
            'sprayCount' => $store->sig['spray'],
            'sprayMet' => $store->sig['sprayMet'],
            'sprayOps' => $store->sig['sprayOps'],
            'issued' => $issued,
            'issuedKeys' => $issuedKeys,
            'headroom' => $headroom,
            'budgetMax' => $budgetMax,
            'aliveKeys' => $aliveKeys,
            'aliveMembers' => $aliveMembers,
        ];
    }

    return $rows;
}

/**
 * Compact aggregates of one replay: the PR #108 k1Aggregate fields UNCHANGED plus the fields of task §6 (requests, final decisions,
 * issuances and re-issues, level / Retry-After, attacker reach per fixed 600 s window, headroom admissions, budget, peak storage).
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function gnhAggregate(array $rows): array
{
    $agg = k1Aggregate($rows);
    $decisions = [];
    $levels = [];
    $perWindow = [];
    $seenKeys = [];
    $issuances = 0;
    $reissues = 0;
    $maxLevel = 0;
    $maxRetry = 0;
    $headroom = 0;
    $budgetMax = 0;
    $peakKeys = 0;
    $peakMembers = 0;
    $t0 = $rows === [] ? 0 : min(array_column($rows, 't'));
    foreach ($rows as $r) {
        $decisions[$r['decision']] = ($decisions[$r['decision']] ?? 0) + 1;
        if ($r['blocked']) {
            $levels['L' . $r['level']] = ($levels['L' . $r['level']] ?? 0) + 1;
            $maxLevel = max($maxLevel, (int) $r['level']);
            $maxRetry = max($maxRetry, (int) $r['retry']);
        }
        if ($r['kind'] === 'attack' && $r['reached']) {
            $w = intdiv($r['t'] - $t0, 600);
            $perWindow[$w] = ($perWindow[$w] ?? 0) + 1;
        }
        foreach ($r['issuedKeys'] as $k) {
            $issuances++;
            if (isset($seenKeys[$k])) {
                $reissues++;
            }
            $seenKeys[$k] = true;
        }
        $headroom += $r['headroom'];
        $budgetMax = max($budgetMax, $r['budgetMax']);
        $peakKeys = max($peakKeys, $r['aliveKeys']);
        $peakMembers = max($peakMembers, $r['aliveMembers']);
    }
    ksort($decisions);
    ksort($levels);
    $by = $agg['blockIssuancesByScope'];

    return [
        'requests' => count($rows),
        'legitAttempts' => $agg['legitAttempts'],
        'legitDenied' => $agg['legitDenied'],
        'attackAttempts' => $agg['attackAttempts'],
        'attackReachedVerification' => $agg['attackReachedVerification'],
        'attackDenied' => $agg['attackDenied'],
        'attackDistinctSubjectsVerified' => $agg['attackDistinctSubjectsVerified'],
        'attackReachedMaxPer600' => $perWindow === [] ? 0 : max($perWindow),
        'correlationIssuances' => ($by['K1'] ?? 0) + ($by['K2'] ?? 0),
        'blockIssuances' => $issuances,
        'blockReissues' => $reissues,
        'maxLevel' => $maxLevel,
        'maxRetryAfter' => $maxRetry,
        'headroomAdmissions' => $headroom,
        'budgetMax' => $budgetMax,
        'peakCorrelationKeys' => $peakKeys,
        'peakCorrelationMembers' => $peakMembers,
        'decisions' => $decisions,
        'deniedLevels' => $levels,
        'denialRetryAfter' => $agg['denialRetryAfter'],
        'blockIssuancesByScope' => $by,
        'legitFirstDenyT' => $agg['legitFirstDenyT'],
        'attackFirstDenyT' => $agg['attackFirstDenyT'],
        'legitDeniedBySource' => $agg['legitDeniedBySource'],
        'attackDeniedBySource' => $agg['attackDeniedBySource'],
    ];
}

/**
 * One (stream, shape, flow) on the loaded runtime arm → aggregates (+ the per-request rows when $withRows).
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function gnhMeasure(array $events, string $shape, string $flow, bool $withRows = false, bool $peak = true): array
{
    $lab = gnhLab(wsbLabPolicy($shape));
    $rows = gnhReplay($lab, oqcShapeEvents($events, $shape), $flow, $shape, $peak);
    $agg = gnhAggregate($rows);
    if ($withRows) {
        $agg['rows'] = $rows;
    }

    return $agg;
}

/**
 * Every stream of the study: the #111 matrix streams (which include the #108, #104 and #105 streams), the #113 trusted multi-session
 * streams, and the streams below (aimed at the x5 headroom itself).
 *
 * @return array<string, array{title: string, class: string, events: list<array<string, mixed>>}>
 */
function gnhStreams(): array
{
    $S = oqcStreams();
    $tms = tmsStreams();
    foreach (['TMS', 'TMSd', 'TMSs', 'TMR', 'TMX', 'TMU', 'TPAIRT', 'TPAIRM'] as $id) {
        $S[$id] = ['title' => $tms[$id]['title'], 'class' => gnhStreamClasses()[$id], 'events' => $tms[$id]['events']];
    }

    // XN1 — N-1 gaming at the x5 level: 1 IP, own UA, 13 client fingerprints cycled (x5 churn N-1 is 14) and 23 victim accounts cycled
    // (x5 spray N-1 is 24), one failing attempt / 3 s for 30 min (600 attempts). S0 stops it at 3 fingerprints / 5 subjects; a pure x5
    // threshold never reaches its own trigger.
    $ev = [];
    for ($i = 0; $i < 600; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.120', 'xn' . ($i % 13), 'xnv' . ($i % 23), 'fail', 'attack', ['uaRaw' => k1Ua(80)]);
    }
    $S['XN1'] = ['title' => 'XN1 x5 N-1 gaming: 1 IP, 13 fingerprints and 23 victims cycled below the x5 triggers, 1 / 3 s, 600 attempts', 'class' => 'attack', 'events' => $ev];
    // XN1n — the same without a client fingerprint (K1 only): 23 victims cycled.
    $ev = [];
    for ($i = 0; $i < 600; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.121', null, 'xnnv' . ($i % 23), 'fail', 'attack', ['uaRaw' => k1Ua(81)]);
    }
    $S['XN1n'] = ['title' => 'XN1n x5 N-1 gaming, no fingerprint: 1 IP, 23 victims cycled below the x5 spray trigger, 1 / 3 s, 600 attempts', 'class' => 'attack', 'events' => $ev];
    // XN1k — the same IP+UA used ONLY for K2: 13 fingerprints cycled against 3 victims (the K1 set never reaches the S0 N-1 of 4).
    $ev = [];
    for ($i = 0; $i < 600; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.122', 'xk' . ($i % 13), 'xkv' . ($i % 3), 'fail', 'attack', ['uaRaw' => k1Ua(82)]);
    }
    $S['XN1k'] = ['title' => 'XN1k x5 N-1 gaming on K2 only: 13 fingerprints cycled against 3 victims, 1 / 3 s, 600 attempts', 'class' => 'attack', 'events' => $ev];
    // BURN — one party consumes the NAT headroom: the Nu12 isolated office (12 colleagues, own browser major each, stable fingerprints,
    // 1 login / 40 s, 8 % typos, 40 min) plus, behind the same NAT with its own UA and no fingerprint, a neighbour cycling 5 victims at
    // 1 / 20 s from t=300 (it keeps the K1 set at the S0 trigger and spends the K1 headroom budget).
    $ev = $S['Nu12']['events'];
    for ($i = 0, $t = 300; $t <= 2400; $t += 20, $i++) {
        $ev[] = k1Ev($t, NAT, null, 'burnv' . ($i % 5), 'fail', 'attack', ['uaRaw' => k1Ua(52)]);
    }
    $S['BURN'] = ['title' => 'BURN Nu12 isolated office + a NAT neighbour cycling 5 victims at 1 / 20 s from t=300 (spends the K1 headroom)', 'class' => 'both', 'events' => k1Sort($ev)];
    // N12V — the N12 office on devices previously verified for their own account (isDevicePreviouslyVerifiedForAccount = true), untrusted.
    $ev = [];
    foreach ($S['N12']['events'] as $e) {
        $e['verified'] = true;
        $ev[] = $e;
    }
    $S['N12V'] = ['title' => 'N12V the N12 office, every device previously verified for its own account (untrusted sessions)', 'class' => 'benign', 'events' => $ev];

    foreach ($S as $id => $s) {
        if (! isset(gnhStreamClasses()[$id])) {
            unset($S[$id]);
        }
    }

    return $S;
}

/**
 * Assertion helper. S0 assertions are baseline invariants (hard-fail, exit 1). On a candidate arm a failed assertion is a FINDING (recorded
 * with ok:false, diffed by run.sh, exit 0). GNH_STRICT=1 (self-tests) makes every failed assertion fatal.
 */
final class GnhAsserts
{
    public static int $failed = 0;

    /** @param array<string, mixed> $data */
    public static function check(string $scenario, string $name, bool $ok, array $data = []): void
    {
        if (getenv('GNH_SELFTEST_BREAK') === '1') {
            $ok = false;
        }
        if (! $ok) {
            self::$failed++;
        }
        emit($scenario, ['arm' => gnhArm(), 'assert' => $name, 'ok' => $ok] + $data);
    }

    public static function finish(): void
    {
        $strict = getenv('GNH_STRICT') === '1' || gnhArm() === 'S0';
        exit($strict && self::$failed > 0 ? 1 : 0);
    }
}

/** "t:flag" encoding of a probe sequence. */
function gnhSteps(array $rows): array
{
    return array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B' : 'a'), $rows);
}
