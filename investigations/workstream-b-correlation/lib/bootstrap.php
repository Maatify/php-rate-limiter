<?php

declare(strict_types=1);

// Evidence-only harness for Workstream B (#97 / #100). NOT part of the package runtime.
// Runs the REAL EvaluationPipeline + REAL DeviceIdentityResolver over the package's reference
// test stores with a controlled clock. LAB_ROOT selects which package tree is exercised
// (current checkout by default; a `git archive` of a tag for published-artifact runs).

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;

$labRoot = getenv('LAB_ROOT');
if ($labRoot === false || $labRoot === '') {
    $labRoot = dirname(__DIR__, 3);
}
require $labRoot . '/vendor/autoload.php';

const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const CHROME_B = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';
const NAT = '203.0.113.50';

class CountingCorrelationStore extends StatefulInMemoryCorrelationStore
{
    public int $distinctCalls = 0;
    public int $watchCalls = 0;

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        $this->distinctCalls++;

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->watchCalls++;

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }
}

/**
 * Disables ONLY the churn (600 s / cap 3) and dilution (600 s / cap 6) distinct sets; every other correlation
 * capability (credential spray cap 5, distributed account snapshots, device caps 10/50, flood, WATCH) stays real.
 * Used to measure what remains if §5.3 enforcement were removed.
 */
final class ChurnDilutionDisabledStore extends CountingCorrelationStore
{
    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
    {
        if ($ttlSeconds === 600 && ($maxDistinct === 3 || $maxDistinct === 6)) {
            $this->distinctCalls++;

            return new \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO(1, true);
        }

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }
}

/** Records every persisted hard-block cycle transition (DEC-003 measurement). */
final class CycleRecordingStore extends InMemoryRateLimitStore
{
    /** @var list<array{key: string, result: HardBlockCycleResultDTO}> */
    public array $cycleLog = [];

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
        $result = parent::blockWithCycleTracking(
            $currentKey,
            $previousKey,
            $level,
            $durationSeconds,
            $now,
            $cycleWindowSeconds,
            $cycleThreshold,
            $pauseSeconds,
            $pauseHistoryRetentionSeconds,
        );
        $this->cycleLog[] = ['key' => $currentKey, 'result' => $result];

        return $result;
    }
}

final class CustomApiOveruseWithTightLimits extends ApiHeavyProtectionPolicy
{
    public function __construct()
    {
        parent::__construct(['k1' => 40, 'k2' => 20, 'k3' => 30]);
    }

    public function getName(): string
    {
        return 'custom_api_overuse';
    }
}

final class Lab
{
    public const SECRET = 'lab-secret';

    public FixedClock $clock;
    public CycleRecordingStore $store;
    public CountingCorrelationStore $corr;
    public EvaluationPipeline $pipeline;
    public DeviceIdentityResolver $resolver;
    public BlockPolicyInterface $policy;
    public int $t0;

    public string $secret;

    /**
     * @param 'login'|'otp'|'api'|'api_custom' $policy
     * @param bool|null $fingerprintRotation null = follow $previousSecret; true/false forces a previous fingerprint hasher
     */
    public function __construct(
        string $policy = 'login',
        ?string $previousSecret = null,
        bool $correlationDisabled = false,
        string $secret = self::SECRET,
        ?Lab $shareStateWith = null,
        ?bool $fingerprintRotation = null,
        ?string $previousFingerprintSecret = null,
    ) {
        $this->secret = $secret;
        if ($shareStateWith !== null) {
            $this->clock = $shareStateWith->clock;
            $this->store = $shareStateWith->store;
            $this->corr = $shareStateWith->corr;
        } else {
            $this->clock = new FixedClock('2026-01-01 12:00:00');
            $this->store = new CycleRecordingStore($this->clock);
            $this->corr = $correlationDisabled ? new ChurnDilutionDisabledStore($this->clock) : new CountingCorrelationStore($this->clock);
        }
        $this->t0 = (new \DateTimeImmutable('2026-01-01 12:00:00'))->getTimestamp();
        $this->policy = match ($policy) {
            'login' => new LoginProtectionPolicy(),
            'otp' => new OtpProtectionPolicy(),
            'api' => new ApiHeavyProtectionPolicy(),
            'api_custom' => new CustomApiOveruseWithTightLimits(),
        };
        $this->pipeline = new EvaluationPipeline(
            $this->store,
            $this->corr,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->corr),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->corr),
            $secret,
            'prod',
            $this->clock,
            $previousSecret,
        );
        $rotateFp = $fingerprintRotation ?? ($previousSecret !== null);
        $this->resolver = new DeviceIdentityResolver(
            new FingerprintHasher($secret),
            $rotateFp ? new FingerprintHasher($previousFingerprintSecret ?? $previousSecret ?? 'previous-fp-secret') : null,
        );
    }

    public function at(int $secondsFromStart): void
    {
        $this->clock->setNow((new \DateTimeImmutable('@' . ($this->t0 + $secondsFromStart)))->setTimezone($this->clock->getTimezone()));
    }

    public function t(): int
    {
        return $this->clock->now()->getTimestamp() - $this->t0;
    }

    /** @param array<string, mixed>|null $fp */
    public function ctx(string $ip, string $ua, ?string $acct = null, ?array $fp = null, ?string $session = null, bool $trusted = false): RateLimitContextDTO
    {
        return new RateLimitContextDTO($ip, $ua, $acct, $fp, $session, $trusted);
    }

    /**
     * One independent public command through the real resolver + pipeline.
     *
     * @param 'check'|'ok'|'fail' $cmd
     * @return array{cmd: string, d: string, lvl: ?int, retry: ?int, conf: string, dOps: int, wOps: int}
     */
    public function run(RateLimitContextDTO $c, string $cmd): array
    {
        $name = $this->policy->getName();
        $command = match ($cmd) {
            'check' => RateLimitCommand::checkOnly($name),
            'fail' => RateLimitCommand::recordFailure($name),
            'ok' => RateLimitCommand::recordSuccess($name),
        };
        $device = $this->resolver->resolve($c);
        $d0 = $this->corr->distinctCalls;
        $w0 = $this->corr->watchCalls;
        $r = $this->pipeline->process($this->policy, $c, $command, $device);

        return [
            'cmd' => $cmd,
            'd' => $r->decision === 'ALLOW' ? 'A' : ($r->decision === 'HARD_BLOCK' ? 'H' : 'S'),
            'lvl' => $r->blockLevel,
            'retry' => $r->retryAfter,
            'conf' => $device->confidence,
            'dOps' => $this->corr->distinctCalls - $d0,
            'wOps' => $this->corr->watchCalls - $w0,
        ];
    }

    /**
     * One LOGICAL attempt as a host may integrate it.
     *
     * $flow: 'pre+out'              host-realistic: checkOnly, then record the outcome ONLY if checkOnly allowed
     *        'pre+out-unconditional' INVALID CONTROL: records even after a denial (the pipeline then returns early on the
     *                                active block, so state is unchanged; kept only to show that control explicitly)
     *        'pre'                  checkOnly only
     *        'out'                  record-only (recordSuccess / recordFailure only)
     * $outcome: 'ok' | 'fail' (ignored by flow 'pre').
     *
     * @return array<string, mixed>
     */
    public function attempt(RateLimitContextDTO $c, string $flow = 'pre+out', string $outcome = 'ok'): array
    {
        $steps = [];
        if ($flow !== 'out') {
            $steps[] = $this->run($c, 'check');
        }
        $denied = $steps !== [] && $steps[0]['d'] !== 'A';
        if ($flow !== 'pre' && ! ($denied && $flow === 'pre+out')) {
            $steps[] = $this->run($c, $outcome);
        }
        $fmt = static fn(array $s): string => $s['cmd'] . ':' . ($s['d'] === 'A' ? 'A' : $s['d'] . $s['lvl'] . '/' . $s['retry']);

        return [
            't' => $this->t(),
            'steps' => implode('>', array_map($fmt, $steps)),
            'blocked' => count(array_filter($steps, static fn(array $s): bool => $s['d'] !== 'A')) > 0,
            'blockCmd' => (array_values(array_filter($steps, static fn(array $s): bool => $s['d'] !== 'A'))[0]['cmd'] ?? null),
            'allowedThenBlockedBy' => (static function (array $steps): ?string {
                foreach ($steps as $i => $s) {
                    if ($s['d'] !== 'A') {
                        return $i > 0 ? $s['cmd'] : null;
                    }
                }

                return null;
            })($steps),
            'conf' => $steps[0]['conf'],
            'dOps' => array_sum(array_column($steps, 'dOps')),
            'wOps' => array_sum(array_column($steps, 'wOps')),
        ];
    }

    private function h(string $s): string
    {
        return hash_hmac('sha256', $s, $this->secret);
    }

    public function ipPrefix(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);

            return substr(bin2hex((string) $packed), 0, 16);
        }

        return $ip;
    }

    /** @return array<string, string|null> canonical keys, derived exactly as EvaluationPipeline::buildKeys() */
    public function keys(RateLimitContextDTO $c): array
    {
        $d = $this->resolver->resolve($c);
        $p = $this->policy->getName();
        $pre = $this->ipPrefix($c->ip);

        return [
            'K1' => $this->h("{$p}:rate_limiter:k1:v2:prod:{$pre}"),
            'K2' => $this->h("{$p}:rate_limiter:k2:v2:prod:{$pre}:{$d->normalizedUa}"),
            'K3' => $this->h("{$p}:rate_limiter:k3:v2:prod:{$pre}:{$d->fingerprintHash}"),
            'K4' => $c->accountId !== null ? $this->h("{$p}:rate_limiter:k4:v2:prod:{$c->accountId}") : null,
            'K5' => $c->accountId !== null ? $this->h("{$p}:rate_limiter:k5:v2:prod:{$c->accountId}:{$d->fingerprintHash}") : null,
        ];
    }

    /** @return array<string, string> persisted blocks (level@remaining) per canonical key */
    public function blocks(RateLimitContextDTO $c): array
    {
        $out = [];
        $now = $this->clock->now()->getTimestamp();
        foreach ($this->keys($c) as $name => $key) {
            $b = $key === null ? null : $this->store->checkBlock($key);
            if ($b !== null) {
                $out[$name] = 'L' . $b->level . '@+' . ($b->expiresAt - $now) . 's';
            }
        }

        return $out;
    }

    /** @return array<string, int> persisted scores per canonical key */
    public function scores(RateLimitContextDTO $c): array
    {
        $out = [];
        foreach ($this->keys($c) as $name => $key) {
            $s = $key === null ? null : $this->store->get($key);
            if ($s !== null) {
                $out[$name] = $s->value;
            }
        }

        return $out;
    }
}

/** Deterministic JSON-lines emitter: stable key order, no timestamps of wall clock. */
function emit(string $scenario, array $row): void
{
    echo json_encode(['s' => $scenario] + $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
}

/** @param array<string, mixed>|null $fp */
function fp(string $d): array
{
    return ['canvas' => $d, 'tz' => 'UTC'];
}

/** Deterministic PRNG independent of PHP's mt_rand implementation. */
final class Lcg
{
    public function __construct(private int $state)
    {
    }

    public function pct(): int
    {
        $this->state = ($this->state * 1103515245 + 12345) & 0x7fffffff;

        return ($this->state >> 8) % 100 + 1;
    }
}
