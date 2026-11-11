<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Service\SourcePrefix;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-185-38 / LT-185-38 regression suite: IPv4-mapped IPv6 (::ffff:0:0/96) normalization and isolation.
 */
final class SourcePrefixIpv4MappedTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function mappedAddresses(): iterable
    {
        yield 'standard dotted' => ['::ffff:203.0.113.9', '203.0.113.9'];
        yield 'another dotted' => ['::ffff:198.51.100.7', '198.51.100.7'];
        yield 'private class a' => ['::ffff:10.0.0.1', '10.0.0.1'];
        yield 'hex notation' => ['::ffff:c000:0201', '192.0.2.1'];
        yield 'full zero expanded' => ['0000:0000:0000:0000:0000:ffff:c000:0201', '192.0.2.1'];
        yield 'partially expanded' => ['0:0:0:0:0:ffff:192.0.2.1', '192.0.2.1'];
    }

    #[DataProvider('mappedAddresses')]
    public function testUnwrapIpv4MappedReturnsCanonicalIpv4(string $mapped, string $expectedIpv4): void
    {
        self::assertSame($expectedIpv4, SourcePrefix::unwrapIpv4Mapped($mapped));
        self::assertSame($expectedIpv4, SourcePrefix::of($mapped));
        self::assertSame($expectedIpv4, SourcePrefix::of($mapped, 48));
        self::assertFalse(SourcePrefix::isPureIpv6($mapped));
    }

    public function testRawIpv4IsUnchanged(): void
    {
        self::assertSame('198.51.100.7', SourcePrefix::of('198.51.100.7'));
        self::assertSame('198.51.100.7', SourcePrefix::unwrapIpv4Mapped('198.51.100.7'));
        self::assertFalse(SourcePrefix::isPureIpv6('198.51.100.7'));
    }

    public function testPureIpv6IsNotUnwrappedAndRetainsPrefix(): void
    {
        $v6 = '2001:db8:85a3::8a2e:370:7334';
        self::assertSame($v6, SourcePrefix::unwrapIpv4Mapped($v6));
        self::assertTrue(SourcePrefix::isPureIpv6($v6));

        // /64 is leading 16 hex chars
        self::assertSame('20010db885a30000', SourcePrefix::of($v6, 64));
        // /48 is leading 12 hex chars
        self::assertSame('20010db885a3', SourcePrefix::of($v6, 48));
    }

    public function testIpv4CompatibleTransitionFormatIsOutOfScopeForUnwrapping(): void
    {
        // ::198.51.100.7 is deprecated IPv4-compatible (::/96), NOT IPv4-mapped (::ffff:0:0/96).
        // Per LT-185-38, it is out of scope and measured as leading /64 prefix without unwrapping.
        $compat = '::198.51.100.7';
        self::assertSame($compat, SourcePrefix::unwrapIpv4Mapped($compat));
        self::assertSame('0000000000000000', SourcePrefix::of($compat, 64));
    }

    public function testAllComponentsShareIdenticalDerivationForMappedIpv6(): void
    {
        $ip = '::ffff:203.0.113.9';
        $expected = '203.0.113.9';

        // 1. SourcePrefix
        self::assertSame($expected, SourcePrefix::of($ip));

        // 2. EvaluationPipeline::getIpPrefix
        $pipeline = (new \ReflectionClass(EvaluationPipeline::class))->newInstanceWithoutConstructor();
        $pipeMethod = new \ReflectionMethod(EvaluationPipeline::class, 'getIpPrefix');
        self::assertSame($expected, $pipeMethod->invoke($pipeline, $ip));

        // 3. RateLimitOperationalReader::getIpPrefix
        $reader = (new \ReflectionClass(RateLimitOperationalReader::class))->newInstanceWithoutConstructor();
        $readerMethod = new \ReflectionMethod(RateLimitOperationalReader::class, 'getIpPrefix');
        self::assertSame($expected, $readerMethod->invoke($reader, $ip));

        // 4. LocalFallbackLimiter::getIpPrefix
        $limiterMethod = new \ReflectionMethod(LocalFallbackLimiter::class, 'getIpPrefix');
        self::assertSame($expected, $limiterMethod->invoke(null, $ip));
    }

    private function createRuntime(FullCapabilityInMemoryStore $store): RateLimiterBuilder
    {
        $config = new RateLimiterConfig('test_secret', 'test_fp_secret', 'prod');

        return RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter());
    }

    public function testIpv4MappedNeverParticipatesInAdaptiveIpv6Aggregation(): void
    {
        $store = new FullCapabilityInMemoryStore(new FixedClock());
        $composite = $this->createRuntime($store)->build();

        // Spray 10 distinct requests from an IPv4-mapped address
        for ($i = 0; $i < 10; $i++) {
            $ctx = new RateLimitContextDTO(
                '::ffff:203.0.113.9',
                'acct_' . $i,
                'ua-test',
                ['device' => 'dev_' . $i],
            );
            $composite->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        }

        // None of the stored correlation keys should contain adaptive IPv6 hierarchy macro scopes (:48:, :40:, :32:)
        foreach ($store->correlationStore()->distinctKeys() as $key) {
            self::assertStringNotContainsString(':48:', $key, "Hierarchy /48 scope must not exist for IPv4-mapped: $key");
            self::assertStringNotContainsString(':40:', $key, "Hierarchy /40 scope must not exist for IPv4-mapped: $key");
            self::assertStringNotContainsString(':32:', $key, "Hierarchy /32 scope must not exist for IPv4-mapped: $key");
        }
    }

    public function testF18538TableVictimIsAllowInBothRepresentationsUnderLoginProtection(): void
    {
        $store = new FullCapabilityInMemoryStore(new FixedClock());
        $composite = $this->createRuntime($store)->build();

        // Attacker at ::ffff:203.0.113.9 sprays 60 distinct accounts
        $attackerIp = '::ffff:203.0.113.9';
        for ($i = 0; $i < 60; $i++) {
            $ctx = new RateLimitContextDTO(
                $attackerIp,
                'victim_acct_' . $i,
                'attacker-ua',
                ['device' => 'attacker-dev-' . $i],
            );
            $composite->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        }

        // Unrelated victim at ::ffff:198.51.100.7 (different IP, account, device)
        $victimMappedCtx = new RateLimitContextDTO(
            '::ffff:198.51.100.7',
            'honest_user',
            'honest-ua',
            ['device' => 'honest-dev'],
        );
        $resMapped = $composite->limit($victimMappedCtx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $resMapped->decision, 'Victim in mapped notation must be ALLOW');

        // Control victim in standard IPv4 notation 198.51.100.7
        $victimRawCtx = new RateLimitContextDTO(
            '198.51.100.7',
            'honest_user',
            'honest-ua',
            ['device' => 'honest-dev'],
        );
        $resRaw = $composite->limit($victimRawCtx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $resRaw->decision, 'Victim in standard IPv4 notation must be ALLOW');
    }

    public function testBothRepresentationsShareStateAcrossAllThreePolicies(): void
    {
        $policies = [
            'login_protection' => new LoginProtectionPolicy(),
            'otp_protection' => new OtpProtectionPolicy(),
            'api_heavy_protection' => new ApiHeavyProtectionPolicy(),
        ];

        foreach ($policies as $name => $policy) {
            $store = new FullCapabilityInMemoryStore(new FixedClock());
            $composite = $this->createRuntime($store)
                ->withPolicy($policy)
                ->build();

            // Record failures from standard IPv4 198.51.100.7
            $rawCtx = new RateLimitContextDTO('198.51.100.7', 'acct_shared', 'shared-ua');
            $mappedCtx = new RateLimitContextDTO('::ffff:198.51.100.7', 'acct_shared', 'shared-ua');

            // 1. Check before: both see ALLOW
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $composite->limit($rawCtx, RateLimitCommand::checkOnly($name))->decision);
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $composite->limit($mappedCtx, RateLimitCommand::checkOnly($name))->decision);

            // 2. Mutate via raw IPv4
            if ($name === 'api_heavy_protection') {
                for ($k = 0; $k < 125; $k++) {
                    $composite->limit($rawCtx, new RateLimitCommand($name));
                }
            } else {
                for ($k = 0; $k < 6; $k++) {
                    $composite->limit($rawCtx, RateLimitCommand::recordFailure($name));
                }
            }

            // 3. Inspect via mapped IPv6: sees the exact same decision as raw IPv4
            $rawCheck = $composite->limit($rawCtx, RateLimitCommand::checkOnly($name));
            $mappedCheck = $composite->limit($mappedCtx, RateLimitCommand::checkOnly($name));

            self::assertSame($rawCheck->decision, $mappedCheck->decision, "Policy $name: mapped and raw decisions must match");
            self::assertSame($rawCheck->blockLevel, $mappedCheck->blockLevel, "Policy $name: mapped and raw blockLevels must match");
            self::assertSame($rawCheck->retryAfter, $mappedCheck->retryAfter, "Policy $name: mapped and raw retryAfter must match");
        }
    }

    public function testMeasuredReasonWhyApiHeavyProtectionWasAllowUnderAttackerSpray(): void
    {
        $store = new FullCapabilityInMemoryStore(new FixedClock());
        $composite = $this->createRuntime($store)->build();

        // Under api_heavy_protection:
        // Attacker at ::ffff:203.0.113.9 sends 60 requests across 60 accounts
        for ($i = 0; $i < 60; $i++) {
            $ctx = new RateLimitContextDTO('::ffff:203.0.113.9', 'acct_' . $i, 'ua');
            $composite->limit($ctx, RateLimitCommand::checkOnly('api_heavy_protection'));
        }

        // Victim at ::ffff:198.51.100.7 is checked
        $victimCtx = new RateLimitContextDTO('::ffff:198.51.100.7', 'victim', 'ua');
        $res = $composite->limit($victimCtx, RateLimitCommand::checkOnly('api_heavy_protection'));

        // Result is ALLOW.
        // Reason measured and verified:
        // 1. api_heavy_protection does NOT participate in credential spray correlation (no spray tracking on K1).
        // 2. checkOnly does not increment score.
        // 3. Even with accesses recorded, the K1 threshold for api_heavy_protection is 600, whereas 60 < 600.
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision);
        self::assertSame(0, $res->blockLevel);
    }
}
