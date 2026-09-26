<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimiterBuilderWorkflowTest extends TestCase
{
    public function testDefaultBuilderExecutesEveryDefaultPolicyThroughPublicInterface(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        $limiter = $this->buildLimiter(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $clock,
            $store,
        );

        self::assertInstanceOf(RateLimiterInterface::class, $limiter);

        $loginContext = $this->context('default-login-account', ['device' => 'login']);
        $loginResult = $limiter->limit(
            $loginContext,
            RateLimitCommand::recordFailure('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $loginResult->decision);
        self::assertSame(
            3,
            $store->get($this->scopeKey('login_protection', 'k4', $loginContext, 'key-secret', 'fingerprint-secret'))?->value,
        );

        $otpContext = $this->context('default-otp-account', ['device' => 'otp']);
        $otpResult = $limiter->limit(
            $otpContext,
            RateLimitCommand::recordFailure('otp_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $otpResult->decision);
        self::assertSame(1, $otpResult->blockLevel);
        self::assertSame(
            5,
            $store->get($this->scopeKey('otp_protection', 'k4', $otpContext, 'key-secret', 'fingerprint-secret'))?->value,
        );

        $apiContext = $this->context('default-api-account', ['device' => 'api']);
        $apiResult = $limiter->limit(
            $apiContext,
            new RateLimitCommand('api_heavy_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $apiResult->decision);
        foreach (['k1', 'k2', 'k3'] as $scope) {
            self::assertSame(
                1,
                $store->get($this->scopeKey(
                    'api_heavy_protection',
                    $scope,
                    $apiContext,
                    'key-secret',
                    'fingerprint-secret',
                ))?->value,
                $scope,
            );
        }
    }

    /**
     * @param string|null $previousOuter
     * @param string|null $previousFingerprint
     * @param string $stateOuter
     * @param string $stateFingerprint
     */
    #[DataProvider('rotationCases')]
    public function testBuilderReadsPreviousK5BlockThroughPublicApiWithoutCrossPairing(
        string $currentOuter,
        string $currentFingerprint,
        ?string $previousOuter,
        ?string $previousFingerprint,
        string $stateOuter,
        string $stateFingerprint,
    ): void {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->knownDeviceContext('rotation-block-account');
        $previousKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            $stateOuter,
            $stateFingerprint,
        );
        $currentKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            $currentOuter,
            $currentFingerprint,
        );
        $store->block($previousKey, 2, 600);

        $limiter = $this->buildLimiter(
            new RateLimiterConfig(
                $currentOuter,
                $currentFingerprint,
                'prod',
                $previousOuter,
                $previousFingerprint,
            ),
            $clock,
            $store,
        );

        $result = $limiter->limit(
            $context,
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(2, $store->checkBlock($previousKey)?->level);
        self::assertNull($store->checkBlock($currentKey));

        if ($previousOuter !== null && $previousFingerprint !== null) {
            self::assertNull($store->checkBlock($this->scopeKey(
                'login_protection',
                'k5',
                $context,
                $currentOuter,
                $previousFingerprint,
            )));
            self::assertNull($store->checkBlock($this->scopeKey(
                'login_protection',
                'k5',
                $context,
                $previousOuter,
                $currentFingerprint,
            )));
        }
    }

    /**
     * @param string|null $previousOuter
     * @param string|null $previousFingerprint
     * @param string $stateOuter
     * @param string $stateFingerprint
     */
    #[DataProvider('rotationCases')]
    public function testBuilderWritesCurrentK5AndLeavesPreviousK5ReadOnly(
        string $currentOuter,
        string $currentFingerprint,
        ?string $previousOuter,
        ?string $previousFingerprint,
        string $stateOuter,
        string $stateFingerprint,
    ): void {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->knownDeviceContext('rotation-write-account');
        $previousKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            $stateOuter,
            $stateFingerprint,
        );
        $currentKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            $currentOuter,
            $currentFingerprint,
        );
        $store->set($previousKey, 2, 86400);

        $limiter = $this->buildLimiter(
            new RateLimiterConfig(
                $currentOuter,
                $currentFingerprint,
                'prod',
                $previousOuter,
                $previousFingerprint,
            ),
            $clock,
            $store,
        );

        $result = $limiter->limit(
            $context,
            RateLimitCommand::recordFailure('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(2, $store->get($previousKey)?->value);
        self::assertSame(4, $store->get($currentKey)?->value);

        if ($previousOuter !== null && $previousFingerprint !== null) {
            self::assertNull($store->get($this->scopeKey(
                'login_protection',
                'k5',
                $context,
                $currentOuter,
                $previousFingerprint,
            )));
            self::assertNull($store->get($this->scopeKey(
                'login_protection',
                'k5',
                $context,
                $previousOuter,
                $currentFingerprint,
            )));
        }
    }

    public function testBuilderWithoutRotationDoesNotReadForeignGeneration(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        $context = $this->knownDeviceContext('no-rotation-account');
        $foreignKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            'foreign-outer',
            'foreign-fingerprint',
        );
        $currentKey = $this->scopeKey(
            'login_protection',
            'k5',
            $context,
            'current-outer',
            'current-fingerprint',
        );
        $store->block($foreignKey, 2, 600);
        $store->set($foreignKey, 2, 86400);

        $limiter = $this->buildLimiter(
            new RateLimiterConfig('current-outer', 'current-fingerprint', 'prod'),
            $clock,
            $store,
        );

        $result = $limiter->limit(
            $context,
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(0, $result->blockLevel);
        self::assertSame(2, $store->checkBlock($foreignKey)?->level);
        self::assertSame(2, $store->get($foreignKey)?->value);
        self::assertNull($store->checkBlock($currentKey));
        self::assertNull($store->get($currentKey));
    }

    /**
     * @return iterable<string, array{string, string, ?string, ?string, string, string}>
     */
    public static function rotationCases(): iterable
    {
        yield 'outer-only' => [
            'new-outer',
            'stable-fingerprint',
            'old-outer',
            null,
            'old-outer',
            'stable-fingerprint',
        ];
        yield 'fingerprint-only' => [
            'stable-outer',
            'new-fingerprint',
            null,
            'old-fingerprint',
            'stable-outer',
            'old-fingerprint',
        ];
        yield 'both-rotated' => [
            'new-outer',
            'new-fingerprint',
            'old-outer',
            'old-fingerprint',
            'old-outer',
            'old-fingerprint',
        ];
    }

    private function buildLimiter(
        RateLimiterConfig $config,
        FixedClock $clock,
        InMemoryRateLimitStore $store,
    ): RateLimiterInterface {
        return (new RateLimiterBuilder(
            $config,
            $store,
            new StatefulInMemoryCorrelationStore($clock),
            new InMemoryCircuitBreakerStore(),
            new RecordingFailureSignalEmitter(),
        ))->withClock($clock)->build();
    }

    /**
     * @param array<string, mixed> $fingerprint
     */
    private function context(string $accountId, array $fingerprint): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.40',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            $fingerprint,
        );
    }

    private function knownDeviceContext(string $accountId): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.40',
            'Mozilla/5.0 Chrome/123.0.0.0',
            $accountId,
            ['device' => 'stable'],
            null,
            false,
            [],
            true,
        );
    }

    private function scopeKey(
        string $policy,
        string $scope,
        RateLimitContextDTO $context,
        string $outerSecret,
        string $fingerprintSecret,
    ): string {
        $base = "{$policy}:rate_limiter:{$scope}:v2:prod:";
        $fingerprintHash = $this->fingerprintHash($context, $fingerprintSecret);
        $raw = match ($scope) {
            'k1' => $base . $context->ip,
            'k2' => $base . $context->ip . ':' . DeviceIdentityResolver::normalizeUserAgent($context->ua),
            'k3' => $base . $context->ip . ':' . $fingerprintHash,
            'k4' => $base . ($context->accountId ?? ''),
            'k5' => $base . ($context->accountId ?? '') . ':' . $fingerprintHash,
            default => throw new \InvalidArgumentException("Unsupported scope: {$scope}"),
        };

        return hash_hmac('sha256', $raw, $outerSecret);
    }

    private function fingerprintHash(RateLimitContextDTO $context, string $secret): string
    {
        $hash = (new DeviceIdentityResolver(new FingerprintHasher($secret)))
            ->resolve($context)
            ->fingerprintHash;

        self::assertNotNull($hash);

        return $hash;
    }
}
