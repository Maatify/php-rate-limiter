<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourcePrefixPublicRedisTest extends TestCase
{
    private RespRedisCommandExecutor $redis;
    private int $now;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Requires isolated Redis.');
        }
        $this->redis = new RespRedisCommandExecutor($host, (int) $port);
        $this->now = time();
        $this->redis->execute(['FLUSHDB']);
    }

    /** @return iterable<string, array{string}> */
    public static function policies(): iterable
    {
        foreach (['login_protection', 'otp_protection', 'api_heavy_protection'] as $policy) {
            yield $policy => [$policy];
        }
    }

    private function builder(RedisCommandExecutorInterface $executor): RateLimiterBuilder
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('mapped-source-secret', 'mapped-source-fingerprint', 'test'),
            new RedisFullCapabilityStore($executor, 'mapped-source'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . $this->now));
    }

    private function context(string $ip, int $index): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: $ip, ua: 'Mozilla/5.0 Chrome/123', accountId: 'mapped-account-' . $index, clientFingerprint: ['device' => 'mapped-device-' . $index]);
    }

    /** @return array<string, string> */
    private function state(): array
    {
        $keys = $this->redis->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->redis->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $state[$key] = $dump;
        }
        ksort($state);

        return $state;
    }

    #[DataProvider('policies')]
    public function testMappedAndPlainRequestsHaveIdenticalStateAndOperationalReads(string $policy): void
    {
        $control = null;
        foreach ([false, true] as $alternate) {
            $this->redis->execute(['FLUSHDB']);
            $builder = $this->builder($this->redis);
            $runtime = $builder->build();
            $reader = $builder->buildOperationalReader();
            $results = [];
            for ($i = 0; $i < 4; $i++) {
                $context = $this->context($alternate && $i % 2 === 1 ? '::ffff:192.0.2.17' : '192.0.2.17', $i);
                foreach ([RateLimitCommand::checkOnly($policy), new RateLimitCommand($policy), RateLimitCommand::recordFailure($policy), RateLimitCommand::recordSuccess($policy)] as $command) {
                    $results[] = $runtime->limit($context, $command);
                }
            }
            $plain = $reader->readScorePolicy($this->context('192.0.2.17', 1), $policy);
            $mapped = $reader->readScorePolicy($this->context('::ffff:192.0.2.17', 1), $policy);
            self::assertEquals($plain, $mapped);
            $state = $this->state();
            self::assertNotEmpty($state);
            if ($alternate) {
                self::assertEquals($control, [$results, $state]);
            } else {
                $control = [$results, $state];
            }
        }
    }

    public function testMappedApiClientsKeepTheSameSourceIsolationAsPlainIpv4(): void
    {
        $control = null;
        foreach (['', '::ffff:'] as $prefix) {
            $this->redis->execute(['FLUSHDB']);
            $runtime = $this->builder($this->redis)->build();
            $command = new RateLimitCommand('api_heavy_protection');
            foreach (['192.0.2.51', '192.0.2.52', '192.0.2.53'] as $index => $ip) {
                self::assertTrue($runtime->limit($this->context($prefix . $ip, $index), $command)->isAllowed());
            }
            $state = $this->state();
            self::assertNotEmpty($state);
            if ($prefix === '') {
                $control = $state;
            } else {
                self::assertSame($control, $state);
            }
        }
    }

    public function testAdmissionUsesOneSourceAcrossRepresentations(): void
    {
        $runtime = PublicRedisAdmissionFixture::build($this->redis, 'mapped-admission', $this->now);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
        $command = RateLimitCommand::checkOnly('login_protection');
        for ($i = 0; $i < 24; $i++) {
            $result = $runtime->limit($this->context($i % 2 === 0 ? '192.0.2.19' : '::ffff:192.0.2.19', $i), $command);
            self::assertTrue($result->isAllowed());
            self::assertNotNull($result->metadata?->permit);
        }
        $refusal = $runtime->limit($this->context('::ffff:192.0.2.19', 24), $command);
        self::assertFalse($refusal->isAllowed());
        self::assertSame('source_capacity', $refusal->metadata?->cause);
        self::assertTrue($runtime->limit($this->context('192.0.2.20', 24), $command)->isAllowed());
    }

    public function testFallbackSharesOneLocalBudgetAcrossRepresentations(): void
    {
        $outage = new CallableRedisCommandExecutor(static function (array $command): never {
            throw new BackendFailureException('Synthetic Redis outage.');
        });
        $runtime = $this->builder($outage)->build();
        $command = RateLimitCommand::checkOnly('api_heavy_protection');
        for ($i = 0; $i <= 60; $i++) {
            $context = $this->context($i % 2 === 0 ? '192.0.2.218' : '::ffff:192.0.2.218', $i);
            $result = $runtime->limit($context, $command);
            self::assertContains($result->failureMode, ['FAIL_OPEN', 'DEGRADED_MODE']);
            self::assertSame($i < 60, $result->isAllowed());
        }
    }
}
