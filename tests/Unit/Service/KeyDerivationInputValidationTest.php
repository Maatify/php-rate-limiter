<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\RateLimitOperationalReader;
use Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regression coverage for G7-F01: the public Advanced / low-level
 * key-derivation constructors must reject the same blank key/environment
 * inputs that RateLimiterConfig already rejects, before any key derivation
 * or store/correlation access occurs.
 */
final class KeyDerivationInputValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function blankValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
        yield 'tabs and newlines' => ["\t\n"];
    }

    // --- FingerprintHasher ---------------------------------------------

    #[DataProvider('blankValues')]
    public function testFingerprintHasherRejectsBlankSecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Fingerprint secret must not be empty or whitespace-only.');

        new FingerprintHasher($blank);
    }

    public function testFingerprintHasherAcceptsValidSecretAndHashesAsBefore(): void
    {
        $hasher = new FingerprintHasher('fingerprint-secret');

        self::assertSame(
            hash_hmac('sha256', 'identity-input', 'fingerprint-secret'),
            $hasher->hash('identity-input'),
        );
    }

    public function testFingerprintHasherDoesNotTrimSurroundingWhitespaceInSecret(): void
    {
        $hasher = new FingerprintHasher(' fingerprint-secret ');

        self::assertSame(
            hash_hmac('sha256', 'identity-input', ' fingerprint-secret '),
            $hasher->hash('identity-input'),
        );
        self::assertNotSame(
            hash_hmac('sha256', 'identity-input', 'fingerprint-secret'),
            $hasher->hash('identity-input'),
        );
    }

    // --- EvaluationPipeline ----------------------------------------------

    #[DataProvider('blankValues')]
    public function testEvaluationPipelineRejectsBlankActiveKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Active key secret must not be empty or whitespace-only.');

        $this->pipeline($blank, 'prod', null);
    }

    #[DataProvider('blankValues')]
    public function testEvaluationPipelineRejectsBlankEnvironmentScope(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Environment scope must not be empty or whitespace-only.');

        $this->pipeline('active-secret', $blank, null);
    }

    #[DataProvider('blankValues')]
    public function testEvaluationPipelineRejectsBlankNonNullPreviousKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Previous key secret must not be empty or whitespace-only.');

        $this->pipeline('active-secret', 'prod', $blank);
    }

    public function testEvaluationPipelineAcceptsNullPreviousKeySecret(): void
    {
        $pipeline = $this->pipeline('active-secret', 'prod', null);

        self::assertInstanceOf(EvaluationPipeline::class, $pipeline);
    }

    public function testEvaluationPipelineRejectsBeforeAnyStoreOrCorrelationAccess(): void
    {
        $throwingStore = new ThrowingRateLimitStore();
        $throwingCorrelationStore = $this->throwingCorrelationStore();

        try {
            $this->pipeline('', 'prod', null, $throwingStore, $throwingCorrelationStore);
            self::fail('Expected RateLimiterException was not thrown.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Active key secret must not be empty or whitespace-only.', $exception->getMessage());
        }
    }

    public function testEvaluationPipelineAcceptedSecretWithSurroundingWhitespaceIsUsedVerbatimForKeyDerivation(): void
    {
        $secretWithWhitespace = ' ws-secret ';
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        $correlationStore = new StatefulInMemoryCorrelationStore($clock);
        $pipeline = $this->pipeline($secretWithWhitespace, 'prod', null, $store, $correlationStore, $clock);

        $policy = new OtpProtectionPolicy();
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acct_123');
        $device = new DeviceIdentityDTO('hash_123', 'HIGH', false, false, 'Mozilla');

        // The k1 key is computed using the exact, unmodified secret string
        // (including surrounding whitespace). Pre-seeding a hard block on
        // that literal key is only observed by process() if the constructor
        // preserved the secret byte-for-byte instead of trimming it.
        $k1Key = hash_hmac('sha256', 'otp_protection:rate_limiter:k1:v2:prod:127.0.0.1', $secretWithWhitespace);
        $store->block($k1Key, 2, 600);

        $result = $pipeline->process($policy, $context, RateLimitCommand::checkOnly('otp_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
    }

    private function pipeline(
        string $keySecret,
        string $envScope,
        ?string $previousKeySecret,
        ?RateLimitStoreInterface $store = null,
        ?CorrelationStoreInterface $correlationStore = null,
        ?FixedClock $clock = null,
    ): EvaluationPipeline {
        $clock ??= new FixedClock('2025-01-01 12:00:00');
        $store ??= new InMemoryRateLimitStore($clock);
        $correlationStore ??= new StatefulInMemoryCorrelationStore($clock);

        return new EvaluationPipeline(
            $store,
            $correlationStore,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($clock),
            new EphemeralBucket($correlationStore),
            $keySecret,
            $envScope,
            $clock,
            $previousKeySecret,
        );
    }

    // --- RateLimitOperationalReader ---------------------------------------

    #[DataProvider('blankValues')]
    public function testOperationalReaderRejectsBlankActiveKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Active key secret must not be empty or whitespace-only.');

        $this->operationalReader($blank, 'prod', null);
    }

    #[DataProvider('blankValues')]
    public function testOperationalReaderRejectsBlankEnvironmentScope(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Environment scope must not be empty or whitespace-only.');

        $this->operationalReader('active-secret', $blank, null);
    }

    #[DataProvider('blankValues')]
    public function testOperationalReaderRejectsBlankNonNullPreviousKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Previous key secret must not be empty or whitespace-only.');

        $this->operationalReader('active-secret', 'prod', $blank);
    }

    public function testOperationalReaderAcceptsNullPreviousKeySecret(): void
    {
        $reader = $this->operationalReader('active-secret', 'prod', null);

        self::assertInstanceOf(RateLimitOperationalReader::class, $reader);
    }

    public function testOperationalReaderRejectsBeforeAnyStoreOrCircuitBreakerAccess(): void
    {
        $throwingStore = new ThrowingRateLimitStore();
        $throwingCircuitBreakerStore = $this->throwingCircuitBreakerStore();

        try {
            $this->operationalReader('', 'prod', null, $throwingStore, $throwingCircuitBreakerStore);
            self::fail('Expected RateLimiterException was not thrown.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Active key secret must not be empty or whitespace-only.', $exception->getMessage());
        }
    }

    private function operationalReader(
        string $keySecret,
        string $envScope,
        ?string $previousKeySecret,
        ?RateLimitStoreInterface $store = null,
        ?CircuitBreakerStoreInterface $circuitBreakerStore = null,
    ): RateLimitOperationalReader {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store ??= new InMemoryRateLimitStore($clock);
        $circuitBreakerStore ??= $this->throwingCircuitBreakerStore();

        return new RateLimitOperationalReader(
            new DeviceIdentityResolver(new FingerprintHasher('resolver-secret')),
            $store,
            $circuitBreakerStore,
            new DecayCalculator($clock),
            $clock,
            $keySecret,
            $envScope,
            $previousKeySecret,
        );
    }

    // --- FixedWindowSimpleRateLimiter -------------------------------------

    #[DataProvider('blankValues')]
    public function testFixedWindowSimpleRateLimiterRejectsBlankActiveKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Active key secret must not be empty or whitespace-only.');

        $this->simpleLimiter($blank, 'prod', null);
    }

    #[DataProvider('blankValues')]
    public function testFixedWindowSimpleRateLimiterRejectsBlankEnvironmentScope(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Environment scope must not be empty or whitespace-only.');

        $this->simpleLimiter('active-secret', $blank, null);
    }

    #[DataProvider('blankValues')]
    public function testFixedWindowSimpleRateLimiterRejectsBlankNonNullPreviousKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Previous key secret must not be empty or whitespace-only.');

        $this->simpleLimiter('active-secret', 'prod', $blank);
    }

    public function testFixedWindowSimpleRateLimiterAcceptsNullPreviousKeySecret(): void
    {
        $limiter = $this->simpleLimiter('active-secret', 'prod', null);

        self::assertInstanceOf(FixedWindowSimpleRateLimiter::class, $limiter);
    }

    public function testFixedWindowSimpleRateLimiterRejectsBeforeAnyStorageOperation(): void
    {
        $throwingStore = new ThrowingRateLimitStore();

        try {
            $this->simpleLimiter('', 'prod', null, $throwingStore);
            self::fail('Expected RateLimiterException was not thrown.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Active key secret must not be empty or whitespace-only.', $exception->getMessage());
        }
    }

    private function simpleLimiter(
        string $keySecret,
        string $environmentScope,
        ?string $previousKeySecret,
        ?RateLimitStoreInterface $store = null,
    ): FixedWindowSimpleRateLimiter {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store ??= new InMemoryRateLimitStore($clock);

        return new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('checkout', 3, 60)],
            $store,
            $clock,
            $keySecret,
            $environmentScope,
            $previousKeySecret,
        );
    }

    // --- SimpleRateLimitOperationalReader ---------------------------------

    #[DataProvider('blankValues')]
    public function testSimpleOperationalReaderRejectsBlankActiveKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Active key secret must not be empty or whitespace-only.');

        $this->simpleOperationalReader($blank, 'prod', null);
    }

    #[DataProvider('blankValues')]
    public function testSimpleOperationalReaderRejectsBlankEnvironmentScope(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Environment scope must not be empty or whitespace-only.');

        $this->simpleOperationalReader('active-secret', $blank, null);
    }

    #[DataProvider('blankValues')]
    public function testSimpleOperationalReaderRejectsBlankNonNullPreviousKeySecret(string $blank): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Previous key secret must not be empty or whitespace-only.');

        $this->simpleOperationalReader('active-secret', 'prod', $blank);
    }

    public function testSimpleOperationalReaderAcceptsNullPreviousKeySecret(): void
    {
        $reader = $this->simpleOperationalReader('active-secret', 'prod', null);

        self::assertInstanceOf(SimpleRateLimitOperationalReader::class, $reader);
    }

    public function testSimpleOperationalReaderRejectsBeforeReadBudgetOrHealthAccess(): void
    {
        $throwingStore = new ThrowingRateLimitStore();

        try {
            $this->simpleOperationalReader('', 'prod', null, $throwingStore);
            self::fail('Expected RateLimiterException was not thrown.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Active key secret must not be empty or whitespace-only.', $exception->getMessage());
        }
    }

    private function simpleOperationalReader(
        string $keySecret,
        string $environmentScope,
        ?string $previousKeySecret,
        ?RateLimitStoreInterface $store = null,
    ): SimpleRateLimitOperationalReader {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store ??= new InMemoryRateLimitStore($clock);

        return new SimpleRateLimitOperationalReader(
            [new FixedWindowThrottlePolicy('checkout', 3, 60)],
            $store,
            $clock,
            $keySecret,
            $environmentScope,
            $previousKeySecret,
        );
    }

    /**
     * Test-only double proving no correlation-store access can occur while a
     * key-derivation constructor is still validating its inputs.
     */
    private function throwingCorrelationStore(): CorrelationStoreInterface
    {
        return new class implements CorrelationStoreInterface {
            public function addDistinct(string $key, string $item, int $ttlSeconds): int
            {
                throw new RuntimeException('Correlation store offline');
            }

            public function incrementWatchFlag(string $key, int $ttlSeconds): int
            {
                throw new RuntimeException('Correlation store offline');
            }

            public function getWatchFlag(string $key): int
            {
                throw new RuntimeException('Correlation store offline');
            }
        };
    }

    /**
     * Test-only double proving no circuit-breaker store access can occur
     * while a key-derivation constructor is still validating its inputs.
     */
    private function throwingCircuitBreakerStore(): CircuitBreakerStoreInterface
    {
        return new class implements CircuitBreakerStoreInterface {
            public function load(string $policyName): ?CircuitBreakerStateDTO
            {
                throw new RuntimeException('Circuit breaker store offline');
            }

            public function save(string $policyName, CircuitBreakerStateDTO $state): void
            {
                throw new RuntimeException('Circuit breaker store offline');
            }
        };
    }
}
