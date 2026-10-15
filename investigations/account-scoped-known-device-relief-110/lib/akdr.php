<?php

declare(strict_types=1);

// #110 Account-Scoped Known-Device Relief (AKDR) — isolated feasibility harness. EVIDENCE ONLY: not part of the package runtime, and NOT a
// candidate runtime.
//
// What runs: the repository's UNPATCHED EvaluationPipeline + DeviceIdentityResolver + EphemeralBucket at the pinned baseline (main after
// DEC-021; sha-256 asserted, exit 4 otherwise), the package's reference test stores and a controlled clock.
//
// What is MODELLED: each relief variant (A, B, C1, C2, C3, D1, D2) is a STORE-VIEW EMULATION. Only for a request the variant relieves (a
// known device for the request's own account), two injected decorators change what the unpatched pipeline sees:
//   * AkdrRateStore hides the persisted K1 and/or K2 block of that request's own scopes from checkBlock() (enforcement relief);
//   * AkdrCorrStore keeps (or, for C3, drops) the request's observation in the shared spray / churn / LOW-dilution bounded sets and their
//     WATCH flags, but answers the pipeline with a below-trigger count, so the request itself is never denied by those rules.
// When a variant keeps "trigger" semantics (A, B, C1, D1, D2), the collective block the unpatched pipeline would have persisted for the
// suppressed trigger (K2 L2 60 s for churn / LOW dilution, K1 L2 for spray) is persisted by the harness through the same Store method and
// the pipeline's own cycle constants (read by reflection). Every other protection (K3/K4/K5 scores and blocks, DEC-021 allowance and K5
// thresholds, budget, Anti-Equilibrium, device caps, distributed account, flood, DEC-003 cycles) is the real unpatched code.
// A variant result is therefore a MODEL of a possible rule on top of today's runtime — never a measurement of a candidate patch.
//
// Reuses, UNCHANGED: the PR #108 K1 harness (K1Store signal attribution, k1Ctx, k1Ev, k1Streams, k1Replay/k1Lab as the equivalence
// reference) and the PR #113 stream set (tmsStreams). Their provenance functions pin OTHER baselines and are deliberately not called.
require_once dirname(__DIR__, 2) . '/trusted-multi-session-s0/lib/tms.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\PenaltyLadder;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;

const AKDR_BASELINE_SHA = 'ebeb0a32c8fd42e0d4eb4d2500f30ea14036ad92';
const AKDR_RUNTIME_SHA256 = [
    EvaluationPipeline::class => '402139452040cb319208ca7a441b2f70210bf1acee4bd4a0ad666e8101ef6eab',
    DeviceIdentityResolver::class => 'ee654fcfa5d069cef5cfa37950812747aab3801e8f35a430fa132d4dca3c0e6c',
    EphemeralBucket::class => '00531031ebb4e864b5361a0b31332388db56c0d9e5e3c2752cf9756ec8febd8a',
];
/** Candidate / arm labels of earlier studies and the K1 harness self-test switches. Any of them set is a provenance error. */
const AKDR_FOREIGN_ENV = ['WSB_CANDIDATE', 'OQC_ARM', 'GNH_ARM', 'OAAA_ARM', 'K1_SELFTEST_C_NOOP', 'K1_SELFTEST_TTL_SHIFT', 'TMS_SELFTEST_BREAK_SESS'];

/**
 * Relief variants (MODELS, see the header). `hide`: block scopes not enforced on a relieved request. `classes`: bounded-set classes whose
 * trigger is suppressed for a relieved request (`churn` 600 s / cap 3, `dilLow` 600 s / cap 6 on a LOW request, `spray` 600 s / cap 5).
 * `consume`: keep = the relieved request is still added to the shared set and WATCH; exclude = it is not added. `trigger`: the collective
 * block the unpatched pipeline would persist for a suppressed trigger is persisted (for everybody else). `gate`: extra relief conditions.
 */
const AKDR_VARIANTS = [
    'MAIN' => ['title' => 'main as shipped (no relief)', 'hide' => [], 'classes' => [], 'consume' => 'keep', 'trigger' => true, 'gate' => []],
    'A' => ['title' => 'K2 enforcement relief; consumption and collective K2 triggers kept', 'hide' => ['K2'], 'classes' => ['churn', 'dilLow'], 'consume' => 'keep', 'trigger' => true, 'gate' => []],
    'B' => ['title' => 'K1 enforcement relief; consumption and collective K1 triggers kept (= shipped trusted advisory, extended to previously-verified)', 'hide' => ['K1'], 'classes' => ['spray'], 'consume' => 'keep', 'trigger' => true, 'gate' => []],
    'C1' => ['title' => 'K1+K2 relief; consume + trigger collective blocks, never enforced on the known request', 'hide' => ['K1', 'K2'], 'classes' => ['churn', 'dilLow', 'spray'], 'consume' => 'keep', 'trigger' => true, 'gate' => []],
    'C2' => ['title' => 'K1+K2 relief; consume, but known traffic never triggers a collective block', 'hide' => ['K1', 'K2'], 'classes' => ['churn', 'dilLow', 'spray'], 'consume' => 'keep', 'trigger' => false, 'gate' => []],
    'C3' => ['title' => 'K1+K2 relief; known traffic excluded from the shared sets (no consumption)', 'hide' => ['K1', 'K2'], 'classes' => ['churn', 'dilLow', 'spray'], 'consume' => 'exclude', 'trigger' => false, 'gate' => []],
    'D1' => ['title' => 'C1 only while the account\'s DEC-021 known-device allowance is not spent', 'hide' => ['K1', 'K2'], 'classes' => ['churn', 'dilLow', 'spray'], 'consume' => 'keep', 'trigger' => true, 'gate' => ['allowance']],
    'D2' => ['title' => 'D1 + at most R relieved accounts per IP per fixed 600 s window (R = 5, the existing K1 cap, illustration only)', 'hide' => ['K1', 'K2'], 'classes' => ['churn', 'dilLow', 'spray'], 'consume' => 'keep', 'trigger' => true, 'gate' => ['allowance', 'crossAccount']],
];
const AKDR_D2_R = 5;

const FIREFOX_130 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';
const OFFICE_NAT = '198.51.100.20';

/**
 * Proves WHICH runtime ran (exit 4 otherwise): the repository's own unpatched files at the pinned hashes, no foreign label, and the
 * pipeline constants the trigger model relies on.
 *
 * @return array<string, mixed>
 */
function akdrProvenance(): array
{
    foreach (AKDR_FOREIGN_ENV as $env) {
        $v = getenv($env);
        if ($v !== false && $v !== '') {
            fwrite(STDERR, "AKDR provenance violation: $env is set ($v); this study has no candidate arm\n");
            exit(4);
        }
    }
    $repo = realpath(dirname(__DIR__, 3));
    $hashes = [];
    foreach (AKDR_RUNTIME_SHA256 as $class => $pinned) {
        $file = (string) (new \ReflectionClass($class))->getFileName();
        $sha = hash_file('sha256', $file);
        $inRepo = $repo !== false && str_starts_with((string) realpath($file), $repo . '/src/');
        if ($sha !== $pinned || ! $inRepo) {
            fwrite(STDERR, "AKDR provenance violation: $class is not the repository's pinned baseline ($sha, $file)\n");
            exit(4);
        }
        $hashes[substr($class, strrpos($class, '\\') + 1)] = $sha;
    }

    return ['level' => 'MAIN_RUNTIME_OBSERVED + RELIEF_STORE_VIEW_MODEL', 'baseSha' => AKDR_BASELINE_SHA, 'sha256' => $hashes, 'patched' => false, 'cycle' => akdrCycleConstants()];
}

/** @return array{window: int, threshold: int, pause: int, retention: int} the pipeline's own DEC-003 cycle constants (reflection). */
function akdrCycleConstants(): array
{
    $r = new \ReflectionClass(EvaluationPipeline::class);

    return [
        'window' => (int) $r->getConstant('CYCLE_WINDOW_SECONDS'),
        'threshold' => (int) $r->getConstant('CYCLE_THRESHOLD'),
        'pause' => (int) $r->getConstant('DECAY_PAUSE_SECONDS'),
        'retention' => (int) $r->getConstant('PAUSE_HISTORY_RETENTION_SECONDS'),
    ];
}

/** Rate store with a per-request hidden-block view (enforcement relief) and a cycle log (attribution). */
final class AkdrRateStore extends InMemoryRateLimitStore
{
    /** @var array<string, true> keys whose persisted block is NOT enforced on the current (relieved) request */
    public array $hidden = [];
    /** @var list<array{key: string, result: HardBlockCycleResultDTO, by: string}> */
    public array $cycleLog = [];
    public string $writer = 'pipeline';

    public function checkBlock(string $key): ?BlockStateDTO
    {
        if (isset($this->hidden[$key])) {
            return null;
        }

        return parent::checkBlock($key);
    }

    public function blockWithCycleTracking(
        string $currentKey,
        ?string $previousKey,
        int $level,
        int $durationSeconds,
        int $now,
        int $cycleWindowSeconds,
        int $cycleThreshold,
        int $pauseSeconds,
        int $pauseHistoryRetentionSeconds,
    ): HardBlockCycleResultDTO {
        // The store's own cycle bookkeeping must always see the real block state, never the relief view.
        $hidden = $this->hidden;
        $this->hidden = [];
        try {
            $result = parent::blockWithCycleTracking($currentKey, $previousKey, $level, $durationSeconds, $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
        } finally {
            $this->hidden = $hidden;
        }
        $this->cycleLog[] = ['key' => $currentKey, 'result' => $result, 'by' => $this->writer];

        return $result;
    }
}

/**
 * Correlation store: K1Store signal attribution (arm A, nothing disabled) plus the per-request relief view of the shared network sets.
 */
final class AkdrCorrStore extends K1Store
{
    /** @var array{classes: list<string>, consume: string, low: bool}|null relief view of the current request, null = none */
    public ?array $relief = null;
    /** @var array<string, true> classes whose trigger was suppressed during the current relieved request */
    public array $suppressed = [];
    /** @var array<string, int> lifetime relieved observations per class (kept / excluded) */
    public array $reliefOps = [];
    private ?string $pendingWatch = null;

    public function __construct(\Maatify\SharedCommon\Contracts\ClockInterface $clock)
    {
        parent::__construct($clock, 'A');
    }

    private function reliefClass(int $ttl, int $cap): ?string
    {
        if ($this->relief === null || $ttl !== 600) {
            return null;
        }
        $cls = match ($cap) {
            3 => 'churn',
            5 => 'spray',
            6 => $this->relief['low'] ? 'dilLow' : null,
            default => null,
        };

        return $cls !== null && in_array($cls, $this->relief['classes'], true) ? $cls : null;
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        $this->pendingWatch = null;
        $cls = $this->reliefClass($ttlSeconds, $maxDistinct);
        if ($cls === null) {
            return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
        }
        if ($this->relief['consume'] === 'exclude') {
            $this->reliefOps[$cls . ':excluded'] = ($this->reliefOps[$cls . ':excluded'] ?? 0) + 1;
            $this->distinctCalls++;

            return new BoundedDistinctResultDTO(1, true);
        }
        $this->reliefOps[$cls . ':kept'] = ($this->reliefOps[$cls . ':kept'] ?? 0) + 1;
        $r = parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
        if ($r->count >= $maxDistinct) {
            $this->suppressed[$cls] = true;

            return new BoundedDistinctResultDTO(max(1, $maxDistinct - 2), true);
        }
        if ($r->count === $maxDistinct - 1) {
            // The pipeline will now increment this rule's WATCH flag (real consumption); the answer it gets is neutralized.
            $this->pendingWatch = $cls;
        }

        return $r;
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        if ($this->reliefClass($ttlSeconds, $maxDistinct) !== null) {
            throw new \LogicException('AKDR relief model does not cover secret rotation (analytic only, see SECURITY_ANALYSIS.md)');
        }

        return parent::addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $n = parent::incrementWatchFlag($key, $ttlSeconds);
        if ($this->pendingWatch !== null && $ttlSeconds === 1800 && ! str_starts_with($key, 'watch:')) {
            $cls = $this->pendingWatch;
            $this->pendingWatch = null;
            if ($n >= 2) {
                $this->suppressed[$cls] = true;
            }

            return 1;
        }

        return $n;
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        if ($this->pendingWatch !== null) {
            throw new \LogicException('AKDR relief model does not cover secret rotation (analytic only, see SECURITY_ANALYSIS.md)');
        }

        return parent::incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }

    public function endRequest(): array
    {
        $s = array_keys($this->suppressed);
        sort($s);
        $this->suppressed = [];
        $this->pendingWatch = null;
        $this->relief = null;

        return $s;
    }
}

/** Real pipeline + real resolver + AKDR decorators + controlled clock. Mirrors the PR #103 Lab construction exactly. */
final class AkdrLab
{
    public const SECRET = 'lab-secret';

    public FixedClock $clock;
    public AkdrRateStore $store;
    public AkdrCorrStore $corr;
    public EvaluationPipeline $pipeline;
    public DeviceIdentityResolver $resolver;
    public BlockPolicyInterface $policy;
    public int $t0;
    public string $secret = self::SECRET;

    public function __construct(string $policy = 'login')
    {
        $this->clock = new FixedClock('2026-01-01 12:00:00');
        $this->t0 = (new \DateTimeImmutable('2026-01-01 12:00:00'))->getTimestamp();
        $this->store = new AkdrRateStore($this->clock);
        $this->corr = new AkdrCorrStore($this->clock);
        $this->policy = match ($policy) {
            'login' => new LoginProtectionPolicy(),
            'otp' => new OtpProtectionPolicy(),
        };
        $this->pipeline = new EvaluationPipeline(
            $this->store,
            $this->corr,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->corr),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->corr),
            $this->secret,
            'prod',
            $this->clock,
            null,
        );
        $this->resolver = new DeviceIdentityResolver(new FingerprintHasher($this->secret), null);
    }

    public function at(int $t): void
    {
        $this->clock->setNow((new \DateTimeImmutable('@' . ($this->t0 + $t)))->setTimezone($this->clock->getTimezone()));
    }

    private function h(string $s): string
    {
        return hash_hmac('sha256', $s, $this->secret);
    }

    /** @return array<string, string|null> canonical keys, derived exactly as EvaluationPipeline::buildKeys() (IPv4 only here) */
    public function keys(RateLimitContextDTO $c): array
    {
        $d = $this->resolver->resolve($c);
        $p = $this->policy->getName();

        return [
            'K1' => $this->h("{$p}:rate_limiter:k1:v2:prod:{$c->ip}"),
            'K2' => $this->h("{$p}:rate_limiter:k2:v2:prod:{$c->ip}:{$d->normalizedUa}"),
            'K3' => $this->h("{$p}:rate_limiter:k3:v2:prod:{$c->ip}:{$d->fingerprintHash}"),
            'K4' => $c->accountId !== null ? $this->h("{$p}:rate_limiter:k4:v2:prod:{$c->accountId}") : null,
            'K5' => $c->accountId !== null ? $this->h("{$p}:rate_limiter:k5:v2:prod:{$c->accountId}:{$d->fingerprintHash}") : null,
        ];
    }

    /** DEC-021 known-device allowance counter of the account (the pipeline's own auxiliary key), 0 when absent / expired. */
    public function allowanceCount(string $account): int
    {
        $key = $this->h("{$this->policy->getName()}:rate_limiter:aux:known_device_allowance:v1:prod:{$account}");

        return $this->store->getBudget($key)?->count ?? 0;
    }

    public function allowanceLimit(): ?int
    {
        return $this->policy instanceof KnownDeviceAccountAllowancePolicyInterface ? $this->policy->getKnownDeviceAccountAllowance() : null;
    }

    /** @return array{d: string, lvl: ?int, retry: ?int} */
    public function run(RateLimitContextDTO $c, string $cmd): array
    {
        $name = $this->policy->getName();
        $command = match ($cmd) {
            'check' => RateLimitCommand::checkOnly($name),
            'fail' => RateLimitCommand::recordFailure($name),
            'ok' => RateLimitCommand::recordSuccess($name),
        };
        $r = $this->pipeline->process($this->policy, $c, $command, $this->resolver->resolve($c));

        return ['d' => $r->decision === 'ALLOW' ? 'A' : ($r->decision === 'HARD_BLOCK' ? 'H' : 'S'), 'lvl' => $r->blockLevel, 'retry' => $r->retryAfter];
    }
}

/** Is this request a known device for its OWN account by the public contract (DECISION_MATRIX §0)? The library cannot verify it. */
function akdrIsKnown(RateLimitContextDTO $c): bool
{
    return $c->accountId !== null
        && (($c->sessionDeviceId !== null && $c->sessionDeviceId !== '' && $c->isSessionTrusted) || $c->isDevicePreviouslyVerifiedForAccount);
}

/**
 * Per-replay relief state (D2 cross-account window counter). MODEL ONLY: a harness counter, not a store capability; not concurrency safe.
 */
final class AkdrReliefState
{
    /** @var array<string, array<string, true>> "ip|window" => relieved accounts */
    public array $windows = [];
}

/** Relief decision for one request under a variant (evaluated once per logical attempt, before its check). */
function akdrRelieve(string $variant, AkdrLab $lab, RateLimitContextDTO $c, AkdrReliefState $state): bool
{
    $v = AKDR_VARIANTS[$variant];
    if ($v['classes'] === [] || ! akdrIsKnown($c)) {
        return false;
    }
    if (in_array('allowance', $v['gate'], true)) {
        $limit = $lab->allowanceLimit();
        if ($limit === null || $lab->allowanceCount((string) $c->accountId) >= $limit) {
            return false;
        }
    }
    if (in_array('crossAccount', $v['gate'], true)) {
        $w = $c->ip . '|' . intdiv($lab->clock->now()->getTimestamp() - $lab->t0, 600);
        $set = $state->windows[$w] ?? [];
        if (! isset($set[(string) $c->accountId]) && count($set) >= AKDR_D2_R) {
            return false;
        }
        $state->windows[$w][(string) $c->accountId] = true;
    }

    return true;
}

/**
 * One pipeline command under the variant's relief view (when $relieved). Persists the suppressed collective triggers when the variant
 * keeps trigger semantics. Returns the decision and the suppressed classes.
 *
 * @return array{d: string, lvl: ?int, retry: ?int, suppressed: list<string>}
 */
function akdrStep(AkdrLab $lab, RateLimitContextDTO $c, string $cmd, string $variant, bool $relieved): array
{
    $v = AKDR_VARIANTS[$variant];
    if (! $relieved) {
        $r = $lab->run($c, $cmd);

        return $r + ['suppressed' => []];
    }
    $keys = $lab->keys($c);
    $lab->store->hidden = [];
    foreach ($v['hide'] as $scope) {
        $lab->store->hidden[(string) $keys[$scope]] = true;
    }
    $lab->corr->relief = ['classes' => $v['classes'], 'consume' => $v['consume'], 'low' => $lab->resolver->resolve($c)->confidence === 'LOW'];
    try {
        $r = $lab->run($c, $cmd);
    } finally {
        $lab->store->hidden = [];
        $suppressed = $lab->corr->endRequest();
    }
    if ($v['trigger'] && $suppressed !== []) {
        $cy = akdrCycleConstants();
        $now = $lab->clock->now()->getTimestamp();
        $lab->store->writer = 'model-trigger';
        try {
            if (in_array('churn', $suppressed, true) || in_array('dilLow', $suppressed, true)) {
                $lab->store->blockWithCycleTracking((string) $keys['K2'], null, 2, 60, $now, $cy['window'], $cy['threshold'], $cy['pause'], $cy['retention']);
            }
            if (in_array('spray', $suppressed, true)) {
                $lab->store->blockWithCycleTracking((string) $keys['K1'], null, 2, PenaltyLadder::getDuration(2), $now, $cy['window'], $cy['threshold'], $cy['pause'], $cy['retention']);
            }
        } finally {
            $lab->store->writer = 'pipeline';
        }
    }

    return $r + ['suppressed' => $suppressed];
}

/**
 * One logical attempt in a Host flow, with per-request attribution (same method as k1Replay: enforced persisted blocks on the request's
 * scopes BEFORE it, else blocks issued by it, labelled with the correlation signals it observed).
 *
 * flow: pre+out (checkOnly, then the outcome only if allowed) | pre (checkOnly only) | out (record-only)
 *
 * @param array<string, string> $origin persisted-block origin labels by key (carried across the replay)
 * @return array<string, mixed>
 */
function akdrAttempt(AkdrLab $lab, RateLimitContextDTO $c, string $outcome, string $flow, string $variant, AkdrReliefState $state, array &$origin): array
{
    $relieved = akdrRelieve($variant, $lab, $c, $state);
    $keys = $lab->keys($c);
    $hiddenScopes = $relieved ? AKDR_VARIANTS[$variant]['hide'] : [];
    $now = $lab->clock->now()->getTimestamp();
    $before = [];
    foreach ($keys as $name => $key) {
        if ($key === null || in_array($name, $hiddenScopes, true)) {
            continue;
        }
        // Same rule as k1Replay (PR #108): any unexpired persisted block on the request's enforced scopes, whatever its level.
        $b = $lab->store->checkBlock($key);
        if ($b !== null && $b->expiresAt > $now) {
            $before[] = $name;
        }
    }
    $lab->corr->resetSignals();
    $cyc0 = count($lab->store->cycleLog);
    $check = '-';
    $record = '-';
    $blocked = false;
    $reached = false;
    $retry = null;
    $suppressed = [];
    if ($flow !== 'out') {
        $r = akdrStep($lab, $c, 'check', $variant, $relieved);
        $check = $r['d'];
        $blocked = $r['d'] !== 'A';
        $reached = ! $blocked;
        $retry = $blocked ? $r['retry'] : null;
        $suppressed = array_merge($suppressed, $r['suppressed']);
    }
    if ($flow !== 'pre' && ! $blocked) {
        $r = akdrStep($lab, $c, $outcome, $variant, $relieved);
        $record = $r['d'];
        $reached = true;
        $blocked = $r['d'] !== 'A';
        $retry = $blocked ? $r['retry'] : null;
        $suppressed = array_merge($suppressed, $r['suppressed']);
    }
    $sig = $lab->corr->sig;
    $sigs = [];
    foreach (['sprayMet' => 'spray', 'churnMet' => 'churn', 'dilMet' => 'dil', 'distMet' => 'dist'] as $f => $n) {
        if ($sig[$f]) {
            $sigs[] = $n;
        }
    }
    $label = $sigs === [] ? 'score/other' : implode('+', $sigs);
    $scopeByKey = [];
    foreach ($keys as $name => $key) {
        if ($key !== null) {
            $scopeByKey[$key] = $name;
        }
    }
    $issued = [];
    $issuedByPipeline = [];
    foreach (array_slice($lab->store->cycleLog, $cyc0) as $cy) {
        $name = ($scopeByKey[$cy['key']] ?? 'K5*') . ($cy['by'] === 'model-trigger' ? '^' : '');
        $issued[] = $name;
        if ($cy['by'] !== 'model-trigger') {
            // A collective trigger persisted by the relief model never applies to the relieved request itself: not a denial source.
            $issuedByPipeline[] = $name;
        }
        $origin[$cy['key']] = $label;
    }
    $src = null;
    if ($blocked) {
        if ($before !== []) {
            $parts = [];
            foreach ($keys as $name => $key) {
                if ($key !== null && in_array($name, $before, true)) {
                    $parts[] = $name . '(' . ($origin[$key] ?? '?') . ')';
                }
            }
            $src = 'persisted:' . implode('+', $parts);
        } elseif ($issuedByPipeline !== []) {
            $src = 'issued:' . implode('+', $issuedByPipeline) . '(' . $label . ')';
        } else {
            $src = 'unpersisted(' . $label . ')';
        }
    }
    sort($suppressed);

    return [
        'relieved' => $relieved,
        'check' => $check,
        'record' => $record,
        'blocked' => $blocked,
        'reached' => $reached,
        'retry' => $retry,
        'src' => $src,
        'issued' => $issued,
        'suppressed' => array_values(array_unique($suppressed)),
    ];
}

/**
 * Replay a fixed event stream (no retries) under a variant. Events are k1Ev() events (k1Ctx() builds the context).
 *
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function akdrReplay(array $events, string $policy, string $flow, string $variant): array
{
    $lab = new AkdrLab($policy);
    $state = new AkdrReliefState();
    $origin = [];
    $rows = [];
    foreach ($events as $ev) {
        $lab->at($ev['t']);
        $c = k1Ctx($ev, 'login');
        $a = akdrAttempt($lab, $c, (string) $ev['outcome'], $flow, $variant, $state, $origin);
        $rows[] = ['t' => $ev['t'], 'kind' => $ev['kind'], 'who' => $ev['who'] ?? null, 'subject' => $ev['acct'] ?? null, 'known' => akdrIsKnown($c)] + $a;
    }

    return $rows;
}

/** Coarse denial cause: the enforcing key scopes named by the attribution (`^` = a collective trigger persisted by the relief model). */
function akdrCause(?string $src): string
{
    if ($src === null) {
        return 'none';
    }
    $head = (string) preg_replace('/\([^)]*\)/', '', $src);
    preg_match_all('/K[1-5]\*?\^?/', $head, $m);
    $scopes = array_values(array_unique($m[0]));
    sort($scopes);
    $kind = str_starts_with($src, 'persisted:') ? 'persisted' : (str_starts_with($src, 'issued:') ? 'issued' : 'unpersisted');

    return $kind . ':' . ($scopes === [] ? '-' : implode('+', $scopes));
}

/** Decision characters of a replay: check / record / relieved strings (the archived per-request evidence). */
function akdrTrace(array $rows): array
{
    return [
        'check' => implode('', array_column($rows, 'check')),
        'record' => implode('', array_column($rows, 'record')),
        'relieved' => implode('', array_map(static fn(array $r): string => $r['relieved'] ? 'R' : '-', $rows)),
    ];
}

/**
 * Aggregate metrics, re-derivable from the trace + the deterministic schedule (report.php re-derives every security / availability count).
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function akdrAggregate(array $rows): array
{
    $leg = ['n' => 0, 'denied' => 0];
    $atk = ['n' => 0, 'reached' => 0, 'denied' => 0, 'relieved' => 0];
    $perAccount = [];
    $legSrc = [];
    $atkSrc = [];
    $issued = [];
    $first600 = 0;
    $legDeniedWho = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'attack') {
            $atk['n']++;
            $atk['relieved'] += $r['relieved'] ? 1 : 0;
            if ($r['reached']) {
                $atk['reached']++;
                $perAccount[(string) $r['subject']] = ($perAccount[(string) $r['subject']] ?? 0) + 1;
                if ($r['t'] < 600) {
                    $first600++;
                }
            }
            if ($r['blocked']) {
                $atk['denied']++;
                $atkSrc[$r['src']] = ($atkSrc[$r['src']] ?? 0) + 1;
            }
        } else {
            $leg['n']++;
            if ($r['blocked']) {
                $leg['denied']++;
                $legSrc[$r['src']] = ($legSrc[$r['src']] ?? 0) + 1;
                $legDeniedWho[(string) $r['who']] = true;
            }
        }
        foreach ($r['issued'] as $s) {
            $issued[$s] = ($issued[$s] ?? 0) + 1;
        }
    }
    ksort($perAccount);
    ksort($legSrc);
    ksort($atkSrc);
    ksort($issued);

    return [
        'legitAttempts' => $leg['n'],
        'legitDenied' => $leg['denied'],
        'legitUsersDenied' => count($legDeniedWho),
        'attackAttempts' => $atk['n'],
        'attackRelieved' => $atk['relieved'],
        'attackReached' => $atk['reached'],
        'attackDenied' => $atk['denied'],
        'attackAccountsReached' => count($perAccount),
        'attackMaxPerAccount' => $perAccount === [] ? 0 : max($perAccount),
        'attackReachedFirst600' => $first600,
        'legitDeniedBySource' => $legSrc,
        'attackDeniedBySource' => $atkSrc,
        'blockIssuances' => $issued,
    ];
}

// ---------------------------------------------------------------------------------------------------------------------------------------
// V streams: known-device attackers and their controls (Login / OTP with account). #113 streams are reused unchanged (tmsStreams()).
// ---------------------------------------------------------------------------------------------------------------------------------------

/** @return array<string, array{title: string, class: string, events: list<array<string, mixed>>, compliant: bool}> */
function akdrVStreams(): array
{
    $S = [];
    foreach (tmsStreams() as $id => $s) {
        $S[$id] = ['title' => $s['title'], 'class' => $s['class'], 'events' => $s['events'], 'compliant' => ! in_array($id, ['TMX', 'TMXb', 'SP5'], true)];
    }
    $dense = static function (int $n, callable $f): array {
        $ev = [];
        for ($i = 0; $i < $n; $i++) {
            $ev[] = $f($i, $i * 3);
        }

        return $ev;
    };
    // Previously-verified (untrusted) known devices, one per target account: stable client fingerprint (MEDIUM) or none (LOW).
    $S['VMSd'] = ['title' => 'VMSd 12 accounts, one previously-verified device each (MEDIUM, stable fp), 200 failures at 1 / 3 s cycling', 'class' => 'attack', 'compliant' => true,
        'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, 'vdev' . ($i % 12), 'vic' . ($i % 12), 'fail', 'attack', ['who' => 'atk', 'verified' => true]))];
    $S['VMSdL'] = ['title' => 'VMSdL as VMSd but LOW (no client fingerprint: every device shares the UA-cohort DeviceFP)', 'class' => 'attack', 'compliant' => true,
        'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, null, 'vic' . ($i % 12), 'fail', 'attack', ['who' => 'atk', 'verified' => true]))];
    $S['VMR'] = ['title' => 'VMR VMSd rotating the client fingerprint on every attempt (verified flag kept per account)', 'class' => 'attack', 'compliant' => true,
        'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, "vrot$i", 'vic' . ($i % 12), 'fail', 'attack', ['who' => 'atk', 'verified' => true]))];
    $S['TMUA'] = ['title' => 'TMUA TMSd rotating the browser major on every attempt (20 majors; trusted session per account kept)', 'class' => 'attack', 'compliant' => true,
        'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, 'stable' . ($i % 12), 'vic' . ($i % 12), 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => 'sess-vic' . ($i % 12), 'uaRaw' => k1Ua($i % 20)]))];
    // Scale: k accounts, one trusted session each (k = 12 is TMSd).
    foreach ([1, 2, 5, 50] as $k) {
        $S["KS$k"] = ['title' => "KS$k $k account(s), one trusted session each, 200 failures at 1 / 3 s cycling", 'class' => 'attack', 'compliant' => true,
            'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, 'stable' . ($i % $k), 'vic' . ($i % $k), 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => 'sess-vic' . ($i % $k)]))];
    }
    // Several known devices / sessions for ONE account.
    foreach ([3, 10] as $d) {
        $S["MD$d"] = ['title' => "MD$d one account, $d trusted sessions (distinct devices) alternating, 200 failures at 1 / 3 s", 'class' => 'attack', 'compliant' => true,
            'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, 'md' . ($i % $d), 'vic0', 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => 'sess-md' . ($i % $d)]))];
    }
    // Host-violating / misconfigured: the proof of account A used for account B.
    $S['VMX'] = ['title' => 'VMX Host-violating: ONE device marked previously-verified for all 12 targets (proof of vic0 reused), 200 at 1 / 3 s', 'class' => 'attack', 'compliant' => false,
        'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, 'vstolen', 'vic' . ($i % 12), 'fail', 'attack', ['who' => 'atk', 'verified' => true]))];
    $S['SPV'] = ['title' => 'SPV Host misconfiguration: verified flag set for every claimed account (cookie-only); LOW spray of 200 fresh accounts, 1 / 3 s', 'class' => 'attack', 'compliant' => false,
        'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, null, "victim$i", 'fail', 'attack', ['who' => 'atk', 'verified' => true]))];
    $S['SP1n'] = ['title' => 'SP1n credential-less spray: no fingerprint, unknown, 200 fresh accounts, 1 / 3 s (#108)', 'class' => 'attack', 'compliant' => true,
        'events' => $dense(200, static fn(int $i, int $t): array => k1Ev($t, NAT, null, "victim$i", 'fail', 'attack', ['who' => 'atk']))];
    // 24 h horizons: the per-account bound over a day.
    $ev = [];
    for ($i = 0; $i < 2880; $i++) {
        $ev[] = k1Ev($i * 30, NAT, 'stable' . ($i % 12), 'vic' . ($i % 12), 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => 'sess-vic' . ($i % 12)]);
    }
    $S['D24T'] = ['title' => 'D24T 12 trusted sessions, 1 failure / 30 s cycling, 24 h (2880)', 'class' => 'attack', 'compliant' => true, 'events' => $ev];
    $ev = [];
    for ($i = 0; $i < 2880; $i++) {
        $ev[] = k1Ev($i * 30, NAT, null, 'vic' . ($i % 12), 'fail', 'attack', ['who' => 'atk', 'verified' => true]);
    }
    $S['D24V'] = ['title' => 'D24V 12 previously-verified LOW devices, 1 failure / 30 s cycling, 24 h (2880)', 'class' => 'attack', 'compliant' => true, 'events' => $ev];
    $ev = [];
    for ($i = 0; $i < 1440; $i++) {
        $ev[] = k1Ev($i * 60, NAT, 'stable0', 'vic0', 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => 'sess-vic0']);
    }
    $S['D24K1'] = ['title' => 'D24K1 ONE compliant trusted session on its own account, 1 failure / 60 s, 24 h (1440)', 'class' => 'attack', 'compliant' => true, 'events' => $ev];

    return $S;
}

/** @return list<array{0: string, 1: string, 2: string, 3: string}> [stream, policy, flow, variant] */
function akdrVCells(): array
{
    $cells = [];
    $login = ['N12T', 'N12M', 'N12', 'TMS', 'TMSd', 'TMSs', 'TMR', 'TMU', 'TMUA', 'VMSd', 'VMSdL', 'VMR', 'KS1', 'KS2', 'KS5', 'KS50', 'MD3', 'MD10', 'TMX', 'TMXb', 'VMX', 'SP5', 'SPV', 'SP1n', 'TPAIRT', 'TPAIRM', 'D24T', 'D24V', 'D24K1'];
    foreach ($login as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            foreach (array_keys(AKDR_VARIANTS) as $v) {
                if ($flow === 'out' && ! in_array($v, ['MAIN', 'C1'], true)) {
                    continue;
                }
                $cells[] = [$id, 'login', $flow, $v];
            }
        }
    }
    foreach (['N12T', 'TMS', 'TMSd', 'VMSd', 'VMSdL', 'KS1', 'TMX', 'D24K1'] as $id) {
        foreach (['pre+out', 'pre'] as $flow) {
            foreach (['MAIN', 'C1', 'C3', 'D1'] as $v) {
                $cells[] = [$id, 'otp', $flow, $v];
            }
        }
    }

    return $cells;
}

// ---------------------------------------------------------------------------------------------------------------------------------------
// Office engine (O and B groups): browsers that retry. One NAT, one Firefox major, one account per browser.
//   * arrival: one browser every intdiv(1800, N) s from 09:00 in a deterministic per-day order (a 30-minute morning wave);
//   * ~10 % typo: the first logical attempt fails, a new attempt follows 15 s later with the right password;
//   * a denied check is retried after max(retryAfter, 60) s, at most 3 retries per browser per day (H02 rule, PR #118);
//   * success = an admitted check followed by a recorded successful outcome (Host flow pre+out).
// Attackers on the same NAT and the same browser major:
//   * NC — credential-less sprayer (H02 cadence): one attempt per 60 s from 08:50 to 10:00, fresh account each time, no fingerprint,
//          never known;
//   * KC — credentialed known attacker: 12 accounts with one previously-verified device each (same fingerprint mode as the office),
//          every attempt fails, 1 / 3 s cycling the accounts for 600 s from 09:05.
// Token per pipeline request (report.php replays them): browser `<idx>@<t><o|f><try><check><record><R|->`, attacker
// `<N|K><idx>@<t><check><record><R|->`. <t> is seconds from 08:50 of that day.
// ---------------------------------------------------------------------------------------------------------------------------------------

const AKDR_OFFICE_UA = FIREFOX_130;
const AKDR_DAY_START = 8 * 3600 + 50 * 60;

/** @return array<string, mixed> office request context */
function akdrOfficeCtx(string $mode, int $idx, bool $known, string $prefix = 'u'): RateLimitContextDTO
{
    $fp = $mode === 'MED' ? ['canvas' => "dev-$prefix$idx", 'tz' => 'UTC'] : null;

    return new RateLimitContextDTO(OFFICE_NAT, AKDR_OFFICE_UA, "acct-$prefix$idx", $fp, null, false, [], $known, null);
}

/**
 * One office day on $lab (state carries across calls). $known: idx => bool (Host's previously-verified fact at the start of the day).
 *
 * @param array<int, bool> $known
 * @return array{tokens: list<string>, users: array<int, array{first: int, success: ?int, denied: int}>, attack: array{n: int, reached: int, accounts: array<string, int>}, relievedRequests: int}
 */
function akdrOfficeDay(AkdrLab $lab, AkdrReliefState $state, array &$origin, int $dayIndex, int $n, string $mode, array $known, string $attacker, string $variant, int $seed): array
{
    $base = $dayIndex * 86400 + AKDR_DAY_START;
    $rng = new Lcg($seed + 7919 * $dayIndex);
    $order = range(0, $n - 1);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = $rng->pct() % ($i + 1);
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
    }
    $spacing = intdiv(1800, $n);
    $queue = [];
    $seq = 0;
    foreach ($order as $pos => $idx) {
        $typo = $rng->pct() <= 10;
        $queue[] = ['t' => 600 + $pos * $spacing, 'seq' => $seq++, 'who' => 'u', 'idx' => $idx, 'outcome' => $typo ? 'fail' : 'ok', 'try' => 0];
    }
    if ($attacker === 'NC') {
        for ($t = 0, $i = 0; $t <= 4200; $t += 60, $i++) {
            $queue[] = ['t' => $t, 'seq' => $seq++, 'who' => 'N', 'idx' => $i, 'outcome' => 'fail', 'try' => 0];
        }
    } elseif ($attacker === 'KC') {
        for ($i = 0; $i < 200; $i++) {
            $queue[] = ['t' => 900 + $i * 3, 'seq' => $seq++, 'who' => 'K', 'idx' => $i % 12, 'outcome' => 'fail', 'try' => 0];
        }
    }
    $users = [];
    $retries = [];
    $tokens = [];
    $attack = ['n' => 0, 'reached' => 0, 'accounts' => []];
    $relievedRequests = 0;
    $causes = ['legit' => [], 'attack' => []];
    while ($queue !== []) {
        usort($queue, static fn(array $a, array $b): int => [$a['t'], $a['seq']] <=> [$b['t'], $b['seq']]);
        $e = array_shift($queue);
        $lab->at($base + $e['t']);
        if ($e['who'] === 'u') {
            $c = akdrOfficeCtx($mode, $e['idx'], $known[$e['idx']] ?? false);
        } elseif ($e['who'] === 'N') {
            $c = new RateLimitContextDTO(OFFICE_NAT, AKDR_OFFICE_UA, "nc-d$dayIndex-{$e['idx']}", null, null, false, [], false, null);
        } else {
            $c = akdrOfficeCtx($mode, $e['idx'], true, 'kc');
        }
        $a = akdrAttempt($lab, $c, $e['outcome'], 'pre+out', $variant, $state, $origin);
        $relievedRequests += $a['relieved'] ? 1 : 0;
        $tail = $a['check'] . $a['record'] . ($a['relieved'] ? 'R' : '-');
        if ($a['blocked']) {
            $side = $e['who'] === 'u' ? 'legit' : 'attack';
            $cause = akdrCause($a['src']);
            $causes[$side][$cause] = ($causes[$side][$cause] ?? 0) + 1;
        }
        if ($e['who'] !== 'u') {
            $tokens[] = $e['who'] . $e['idx'] . '@' . $e['t'] . $tail;
            $attack['n']++;
            if ($a['reached']) {
                $attack['reached']++;
                $acct = $e['who'] . $e['idx'];
                $attack['accounts'][$acct] = ($attack['accounts'][$acct] ?? 0) + 1;
            }
            continue;
        }
        $tokens[] = $e['idx'] . '@' . $e['t'] . ($e['outcome'] === 'ok' ? 'o' : 'f') . $e['try'] . $tail;
        $u = $users[$e['idx']] ?? ['first' => $e['t'], 'success' => null, 'denied' => 0];
        if ($a['check'] !== 'A') {
            $u['denied']++;
            $used = $retries[$e['idx']] ?? 0;
            if ($used < 3) {
                $retries[$e['idx']] = $used + 1;
                $queue[] = ['t' => $e['t'] + max((int) $a['retry'], 60), 'seq' => $seq++, 'who' => 'u', 'idx' => $e['idx'], 'outcome' => $e['outcome'], 'try' => $used + 1];
            }
        } elseif ($e['outcome'] === 'fail') {
            $queue[] = ['t' => $e['t'] + 15, 'seq' => $seq++, 'who' => 'u', 'idx' => $e['idx'], 'outcome' => 'ok', 'try' => $e['try']];
        } else {
            $u['success'] = $e['t'];
        }
        $users[$e['idx']] = $u;
    }
    ksort($users);
    ksort($attack['accounts']);
    ksort($causes['legit']);
    ksort($causes['attack']);

    return ['tokens' => $tokens, 'users' => $users, 'attack' => $attack, 'relievedRequests' => $relievedRequests, 'causes' => $causes];
}

/** Deterministic known set: the first round(p * N) browsers of a seeded permutation. @return array<int, bool> */
function akdrKnownSet(int $n, int $pct, int $seed): array
{
    $rng = new Lcg($seed);
    $order = range(0, $n - 1);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = $rng->pct() % ($i + 1);
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
    }
    $k = intdiv($n * $pct + 50, 100);
    $known = array_fill(0, $n, false);
    foreach (array_slice($order, 0, $k) as $idx) {
        $known[$idx] = true;
    }

    return $known;
}

/** O-group cells: [size, mode, knownPct, attacker, variant]. */
function akdrOCells(): array
{
    $cells = [];
    foreach ([12, 50, 100, 250] as $n) {
        foreach (['LOW', 'MED'] as $mode) {
            foreach ([[0, 'none'], [50, 'none'], [90, 'none'], [100, 'none'], [90, 'NC'], [90, 'KC']] as [$pct, $atk]) {
                foreach (array_keys(AKDR_VARIANTS) as $v) {
                    $cells[] = [$n, $mode, $pct, $atk, $v];
                }
            }
        }
    }

    return $cells;
}

const AKDR_B_DAYS = 14;
const AKDR_B_SIZE = 100;
/** B-group runs: label => [variant, attacker]. */
const AKDR_B_RUNS = [
    'MAIN' => ['MAIN', 'none'], 'C1' => ['C1', 'none'], 'C2' => ['C2', 'none'], 'C3' => ['C3', 'none'], 'D1' => ['D1', 'none'],
    'MAIN+NC' => ['MAIN', 'NC'], 'C1+NC' => ['C1', 'NC'], 'C2+NC' => ['C2', 'NC'], 'C3+NC' => ['C3', 'NC'],
];
