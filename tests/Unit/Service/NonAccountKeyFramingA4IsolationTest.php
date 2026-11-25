<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A4 isolation regression across non-account score, block, correlation and IPv6 families.
 *
 * On the pre-G2 baseline (`main@8cf34c55`), colon-delimited policy/environment joins allow two distinct
 * `(policy, environment)` pairs sharing one store and secret to collide on K1, K2, K3, correlation and
 * IPv6 adaptive state (RED before G2; GREEN after G2).
 */
#[Group('php32')]
#[Group('g2-non-account-framing')]
final class NonAccountKeyFramingA4IsolationTest extends TestCase
{
    private const SECRET = 'synthetic-a4-secret-not-for-production';
    private const FP_SECRET = 'synthetic-a4-fp-secret-not-for-production';
    private const NOW = '@1793793600';

    public function testK1ScoreAndBlockStayIsolatedAcrossAmbiguousPolicyEnvironmentPairs(): void
    {
        $clock = new FixedClock(self::NOW);
        $store = new FullCapabilityInMemoryStore($clock);

        $policyA = $this->namedApiPolicy('a:rate_limiter:k1:v2:b', ['k1' => 2, 'k2' => 999, 'k3' => 999]);
        $policyB = $this->namedApiPolicy('a', ['k1' => 2, 'k2' => 999, 'k3' => 999]);

        $builderA = $this->builder($store, $clock, 'c')->withPolicy($policyA);
        $builderB = $this->builder($store, $clock, 'b:rate_limiter:k1:v2:c')->withPolicy($policyB);

        $runtimeA = $builderA->build();
        $readerB = $builderB->buildOperationalReader();
        $runtimeB = $builderB->build();

        $context = new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0 Chrome/126.0.0.0');
        $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        $blockedA = $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blockedA->decision);

        $snapshotB = $readerB->readScorePolicy($context, $policyB->getName());
        self::assertSame(0, $snapshotB->scopes->k1->effectiveScore, 'Policy B K1 score must not observe Policy A state.');
        self::assertNull($snapshotB->scopes->k1->activeHardBlock, 'Policy B K1 block must not observe Policy A block.');

        $allowedB = $runtimeB->limit($context, RateLimitCommand::checkOnly($policyB->getName()));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $allowedB->decision);
    }

    public function testK2ScoreAndBlockStayIsolatedAcrossAmbiguousPolicyEnvironmentPairs(): void
    {
        $clock = new FixedClock(self::NOW);
        $store = new FullCapabilityInMemoryStore($clock);

        $policyA = $this->namedApiPolicy('a:rate_limiter:k2:v2:b', ['k1' => 999, 'k2' => 2, 'k3' => 999]);
        $policyB = $this->namedApiPolicy('a', ['k1' => 999, 'k2' => 2, 'k3' => 999]);

        $builderA = $this->builder($store, $clock, 'c')->withPolicy($policyA);
        $builderB = $this->builder($store, $clock, 'b:rate_limiter:k2:v2:c')->withPolicy($policyB);

        $runtimeA = $builderA->build();
        $readerB = $builderB->buildOperationalReader();

        $context = new RateLimitContextDTO('203.0.113.11', 'Mozilla/5.0 Chrome/126.0.0.0');
        $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        $blockedA = $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $blockedA->decision);

        $snapshotB = $readerB->readScorePolicy($context, $policyB->getName());
        self::assertSame(0, $snapshotB->scopes->k2->effectiveScore, 'Policy B K2 score must not observe Policy A state.');
        self::assertNull($snapshotB->scopes->k2->activeHardBlock, 'Policy B K2 block must not observe Policy A block.');
    }

    public function testK3ScoreAndBlockStayIsolatedAcrossAmbiguousPolicyEnvironmentPairs(): void
    {
        $clock = new FixedClock(self::NOW);
        $store = new FullCapabilityInMemoryStore($clock);

        $policyA = $this->namedApiPolicy('a:rate_limiter:k3:v2:b', ['k1' => 999, 'k2' => 999, 'k3' => 2]);
        $policyB = $this->namedApiPolicy('a', ['k1' => 999, 'k2' => 999, 'k3' => 2]);

        $builderA = $this->builder($store, $clock, 'c')->withPolicy($policyA);
        $builderB = $this->builder($store, $clock, 'b:rate_limiter:k3:v2:c')->withPolicy($policyB);

        $runtimeA = $builderA->build();
        $readerB = $builderB->buildOperationalReader();

        $context = new RateLimitContextDTO('203.0.113.12', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['canvas' => 'v1']);
        $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        $blockedA = $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blockedA->decision);

        $snapshotB = $readerB->readScorePolicy($context, $policyB->getName());
        self::assertNotNull($snapshotB->scopes->k3);
        self::assertSame(0, $snapshotB->scopes->k3->effectiveScore, 'Policy B K3 score must not observe Policy A state.');
        self::assertNull($snapshotB->scopes->k3->activeHardBlock, 'Policy B K3 block must not observe Policy A block.');
    }

    public function testDilutionCorrelationStaysIsolatedAcrossAmbiguousPolicyEnvironmentPairs(): void
    {
        $clock = new FixedClock(self::NOW);
        $store = new FullCapabilityInMemoryStore($clock);

        $policyA = $this->namedLoginPolicy('a:rate_limiter:correlation:dilution:v1:b');
        $policyB = $this->namedLoginPolicy('a');

        $runtimeA = $this->builder($store, $clock, 'c')->withPolicy($policyA)->build();
        $runtimeB = $this->builder($store, $clock, 'b:rate_limiter:correlation:dilution:v1:c')->withPolicy($policyB)->build();

        // Five distinct IPv4 addresses on Policy A bring dilution count to 5 (one shy of immediate 6-IP block).
        for ($i = 1; $i <= 5; $i++) {
            $ctx = new RateLimitContextDTO("198.51.100.{$i}", 'Mozilla/5.0 Chrome/126.0.0.0');
            $res = $runtimeA->limit($ctx, RateLimitCommand::checkOnly($policyA->getName()));
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision);
        }

        // First request on Policy B with the same passive-UA fingerprint from a 6th IP must NOT inherit Policy A's 5-IP dilution count.
        $ctxB = new RateLimitContextDTO('198.51.100.6', 'Mozilla/5.0 Chrome/126.0.0.0');
        $resB = $runtimeB->limit($ctxB, RateLimitCommand::checkOnly($policyB->getName()));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $resB->decision, 'Policy B dilution state must not collide with Policy A.');
    }

    public function testIpv6AdaptiveHierarchyStaysIsolatedAcrossAmbiguousPolicyEnvironmentPairs(): void
    {
        $clock = new FixedClock(self::NOW);
        $rateStore = new InMemoryRateLimitStore($clock);
        $correlationStore = new StatefulInMemoryCorrelationStore($clock);
        $circuitStore = new InMemoryCircuitBreakerStore();

        $policyA = $this->namedLoginPolicy('a:rate_limiter:ipv6_adaptive:hierarchy:v1:48:b');
        $policyB = $this->namedLoginPolicy('a');

        $runtimeA = (new RateLimiterBuilder(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, 'c'),
            $rateStore,
            $correlationStore,
            $circuitStore,
            new RecordingFailureSignalEmitter(),
        ))->withClock($clock)->withPolicy($policyA)->build();

        $runtimeB = (new RateLimiterBuilder(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, 'b:rate_limiter:ipv6_adaptive:hierarchy:v1:48:c'),
            $rateStore,
            $correlationStore,
            $circuitStore,
            new RecordingFailureSignalEmitter(),
        ))->withClock($clock)->withPolicy($policyB)->build();

        // Policy A activates /48 with 2 distinct /64 prefixes.
        $runtimeA->limit(new RateLimitContextDTO('2001:db8:1:1::1', 'Mozilla/5.0 Chrome/126.0.0.0', 'acct-1'), RateLimitCommand::checkOnly($policyA->getName()));
        $runtimeA->limit(new RateLimitContextDTO('2001:db8:1:2::1', 'Mozilla/5.0 Chrome/126.0.0.0', 'acct-2'), RateLimitCommand::checkOnly($policyA->getName()));

        $keysAfterA = $correlationStore->distinctKeys();
        // Policy B sends 1 request in the same /48; it must NOT activate /48 by colliding with Policy A's /48 set.
        $runtimeB->limit(new RateLimitContextDTO('2001:db8:1:3::1', 'Mozilla/5.0 Chrome/126.0.0.0', 'acct-3'), RateLimitCommand::checkOnly($policyB->getName()));
        $keysAfterB = $correlationStore->distinctKeys();

        // On the buggy baseline, Policy B collides on Policy A's /48 hierarchy key (which already reached cap=2),
        // immediately activating /48 macro spray/churn on Policy B's very first request (extra /48 macro keys appear).
        $sharedHierarchyKey = hash_hmac('sha256', 'a:rate_limiter:ipv6_adaptive:hierarchy:v1:48:b:rate_limiter:ipv6_adaptive:hierarchy:v1:48:c:20010db80001', self::SECRET);
        self::assertNotContains(
            $sharedHierarchyKey,
            $keysAfterA,
            'Hierarchy scope key must not use the ambiguous pre-G2 colon-joined preimage.',
        );
        self::assertNotEmpty(array_diff($keysAfterB, $keysAfterA));
    }

    private function builder(FullCapabilityInMemoryStore $store, FixedClock $clock, string $envScope): RateLimiterBuilder
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, $envScope),
            $store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock);
    }

    /** @param array<string, int> $limits */
    private function namedApiPolicy(string $name, array $limits): ApiHeavyProtectionPolicy
    {
        return new class ($name, $limits) extends ApiHeavyProtectionPolicy {
            /** @param array<string, int> $limits */
            public function __construct(private readonly string $policyName, array $limits)
            {
                parent::__construct($limits);
            }

            public function getName(): string
            {
                return $this->policyName;
            }
        };
    }

    private function namedLoginPolicy(string $name): LoginProtectionPolicy
    {
        return new class ($name) extends LoginProtectionPolicy {
            public function __construct(private readonly string $policyName) {}

            public function getName(): string
            {
                return $this->policyName;
            }
        };
    }
}
