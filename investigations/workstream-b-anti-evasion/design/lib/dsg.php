<?php

declare(strict_types=1);

// Design & Proof Gate (#97 / #100) — shared harness. Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + REAL
// DeviceIdentityResolver + reference stores + controlled clock) and the PR #104 wsb harness (streams L1–L4 / X1–X8 / Y*, wsbMeasure).
// LAB_ROOT selects the package tree under test:
//   S0              : the repository checkout (UNPATCHED src/; pipeline sha-256 asserted below)
//   EQN / EQW / EQL / EQV : the isolated tree made by design/build_candidate.sh (baseline + patches/eq-candidates.patch), selected with
//                     env WSB_CANDIDATE. The loaded EvaluationPipeline must be byte-identical to the pinned patched file.
require_once dirname(__DIR__, 2) . '/lib/wsb.php';
require_once dirname(__DIR__) . '/fixtures/design.php';

const DSG_BASE_SHA = '107b795d9454a89ea5f5752d6a7ccf7053a6e7e0';
const DSG_PATCHED_PIPELINE_SHA256 = '98d95cc97836066ea8e76cf8399a41ede457c1e3dca156fdaa05b1b3096813c4';
const DSG_VARIANTS = ['S0', 'EQN', 'EQW', 'EQL', 'EQV'];

/**
 * Proves WHICH EvaluationPipeline was loaded. S0 must be byte-identical to the pinned baseline AND be the repository's own file; a
 * candidate must be byte-identical to the pinned patched file AND live outside the repository's src/. Any mismatch aborts (exit 4).
 *
 * @return array<string, mixed>
 */
function dsgProvenance(): array
{
    if (! in_array(wsbVariant(), DSG_VARIANTS, true)) {
        fwrite(STDERR, 'unknown design variant: ' . wsbVariant() . "\n");
        exit(4);
    }
    $file = (new \ReflectionClass(\Maatify\RateLimiter\Service\EvaluationPipeline::class))->getFileName();
    $sha = hash_file('sha256', (string) $file);
    $repoPipeline = realpath(dirname(__DIR__, 4) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath((string) $file) === $repoPipeline;
    if (wsbVariant() === 'S0') {
        if ($sha !== WSB_BASE_PIPELINE_SHA256 || ! $inRepo) {
            fwrite(STDERR, "S0 provenance violation: EvaluationPipeline is not the pinned unpatched baseline ($sha)\n");
            exit(4);
        }
    } elseif ($sha !== DSG_PATCHED_PIPELINE_SHA256 || $inRepo) {
        fwrite(STDERR, "candidate provenance violation: loaded pipeline is not the pinned patched copy outside the repository ($sha)\n");
        exit(4);
    }

    return ['variant' => wsbVariant(), 'level' => wsbLevel(), 'baseSha' => DSG_BASE_SHA, 'pipelineSha256' => $sha, 'patched' => $sha !== WSB_BASE_PIPELINE_SHA256];
}

/**
 * Design-specific streams (all deterministic; no wall clock, no mt_rand). They extend, never replace, the published streams.
 *
 * @return array<string, array{title: string, events: list<array<string, mixed>>}>
 */
function dsgStreams(): array
{
    $e = static fn(int $t, string $ip, ?string $fp, ?string $acct, string $o, string $k, string $u = 'chrome126'): array => ['t' => $t, 'ip' => $ip, 'ua' => $u, 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => $k];
    $sort = static function (array $ev): array {
        usort($ev, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

        return $ev;
    };
    $S = [];

    // D1 — LOW population at scale (#97): 600 distinct legitimate users, one login each, one per second, one popular UA, no
    // client fingerprint, 8 % typo failures. The published L2/L3 (121 users) are too small to show population behaviour.
    $r = new Lcg(101);
    $ev = [];
    for ($t = 0, $n = 1; $t < 600; $t++, $n++) {
        $ev[] = $e($t, 'pop' . $n, null, "pu$n", $r->pct() <= 8 ? 'fail' : 'ok', 'legit');
    }
    $S['D1'] = ['title' => 'D1 LOW population at scale: 600 users, 1 login/s, one UA, 8% typos', 'events' => $ev];

    // D2 — shared NAT office (#100): 12 colleagues, one NAT IP, one UA, each login = a fresh client fingerprint
    // (storage cleared / private window), 1 login per 20 s for 40 minutes, 8 % typos on the colleague's own account.
    $r = new Lcg(103);
    $ev = [];
    for ($t = 0, $n = 1; $t <= 2400; $t += 20, $n++) {
        $ev[] = $e($t, NAT, "office$n", 'off' . ($n % 12), $r->pct() <= 8 ? 'fail' : 'ok', 'legit');
    }
    $S['D2'] = ['title' => 'D2 shared NAT office: 12 colleagues, fresh fingerprint per login, 8% typos, 40 min', 'events' => $ev];

    // D3 — D1 population plus an 8-IP botnet failing against new victim accounts (collateral at scale; X8 at population size).
    $ev = $S['D1']['events'];
    for ($t = 0, $n = 0; $t < 600; $t += 3, $n++) {
        $ev[] = $e($t, 'bot' . ($n % 8), null, "bv$n", 'fail', 'attack');
    }
    $S['D3'] = ['title' => 'D3 LOW population (D1) + 8-IP botnet failing every 3 s', 'events' => $sort($ev)];

    // D4 — cheap evidence against a LOW scope: the D1 population plus ONE attacker IP that fails once every 50 s
    // (a single throw-away IP is enough to supply "fresh scored evidence" to a wide LOW scope).
    $ev = $S['D1']['events'];
    for ($t = 25, $n = 0; $t < 600; $t += 50, $n++) {
        $ev[] = $e($t, 'one-attacker', null, "av$n", 'fail', 'attack');
    }
    $S['D4'] = ['title' => 'D4 LOW population (D1) + ONE attacker IP failing once / 50 s (evidence forgery against a wide LOW scope)', 'events' => $sort($ev)];

    // D5 — success padding cannot cancel evidence on churn: attacker rotates fresh fingerprints on one IP; every failing request
    // is followed by a successful login on its own account (padding). The failures are still failures.
    $ev = [];
    for ($t = 0, $n = 0; $t <= 600; $t += 10, $n++) {
        $ev[] = $e($t, '198.51.100.55', "pad$n", "pv$n", 'fail', 'attack');
        $ev[] = $e($t + 5, '198.51.100.55', "pad$n", 'ownacct', 'ok', 'attack');
    }
    $S['D5'] = ['title' => 'D5 churn attacker pads every failure with a success on its own account', 'events' => $ev];

    // D6 — burst then silence on a NAT: 3 failing fingerprints once (an attacker or three colleagues mistyping), then ONE legitimate
    // client succeeding every 5 s for 25 minutes (recovery without history-only re-block).
    $ev = [
        $e(0, NAT, 'burst1', 'b1', 'fail', 'attack'),
        $e(1, NAT, 'burst2', 'b2', 'fail', 'attack'),
        $e(2, NAT, 'burst3', 'b3', 'fail', 'attack'),
    ];
    for ($t = 5; $t <= 1500; $t += 5) {
        $ev[] = $e($t, NAT, 'LEG', 'legit', 'ok', 'legit');
    }
    $S['D6'] = ['title' => 'D6 one 3-fingerprint failing burst on a NAT, then a lone legitimate client every 5 s', 'events' => $ev];

    // D7 — persistent single-K2 attacker: fresh fingerprint + fresh account every second for 30 minutes (bounds the sustained leak
    // per block cycle of every variant).
    $ev = [];
    for ($t = 0; $t <= 1800; $t++) {
        $ev[] = $e($t, '198.51.100.123', "per$t", "pa$t", 'fail', 'attack');
    }
    $S['D7'] = ['title' => 'D7 persistent attacker on one K2: fresh fingerprint + account every second for 30 min', 'events' => $ev];

    return $S;
}

/** Every stream of the design matrix, in report order: the published/#104 primary order, then the D streams. */
function dsgAllStreams(): array
{
    return wsbStreams() + dsgStreams();
}

/**
 * Store whose per-command operation counts are exact: every public CorrelationStore call is counted by kind. Used for the Redis-call
 * cost comparison (the reference store is behaviourally identical to the Redis one for these operations; the counts are API calls).
 */
final class OpCountingStore extends CountingCorrelationStore
{
    /** @var array<string, int> */
    public array $ops = ['distinct' => 0, 'watchInc' => 0, 'watchGet' => 0, 'rotation' => 0];

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $this->ops['distinct']++;

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $this->ops['rotation']++;

        return parent::addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->ops['watchInc']++;

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }

    public function getWatchFlag(string $key): int
    {
        $this->ops['watchGet']++;

        return parent::getWatchFlag($key);
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $this->ops['distinct']++;

        return parent::addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO
    {
        $this->ops['rotation']++;

        return parent::addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public function total(): int
    {
        return array_sum($this->ops);
    }
}

/** A Lab whose correlation store counts every operation by kind (Lab is final; stores and pipeline are public, so rebuild the pipeline). */
function dsgCountingLab(string $policy = 'login', ?string $previousSecret = null, string $secret = Lab::SECRET, ?bool $fingerprintRotation = null): Lab
{
    $lab = new Lab($policy, $previousSecret, false, $secret, null, $fingerprintRotation);
    $corr = new OpCountingStore($lab->clock);
    $lab->corr = $corr;
    $lab->pipeline = new \Maatify\RateLimiter\Service\EvaluationPipeline(
        $lab->store,
        $corr,
        new \Maatify\RateLimiter\Service\BudgetTracker($lab->store, $lab->clock),
        new \Maatify\RateLimiter\Service\AntiEquilibriumGate($corr),
        new \Maatify\RateLimiter\Service\DecayCalculator($lab->clock),
        new \Maatify\RateLimiter\Service\EphemeralBucket($corr),
        $secret,
        'prod',
        $lab->clock,
        $previousSecret,
    );

    return $lab;
}
