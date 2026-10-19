<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementScenarios as Sc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * NM-7 on settlement (5b2.1/PLAN.md §5): 16 and 64 processes, 3 rounds. Cap 4 with one settled failure: exactly 3 distinct
 * accounts are admitted per round, each adds a due pending member, and the counted size never exceeds the cap. Detector:
 * a non-atomic control (count, then write) over-admits in at least one round.
 */
#[Group('settle')]
#[Group('nm7')]
final class AdmissionSettlementNm7RedisTest extends AdmissionRedisGateTestCase
{
    private const KEY = 's:nm7s';

    private const CAP = 4;

    /** @return list<bool> */
    private function race(int $processes, string $mode): array
    {
        $spec = base64_encode(json_encode(['namespace' => self::NAMESPACE, 'now' => Sc::N, 'mode' => $mode, 'key' => self::KEY, 'capacity' => self::CAP], JSON_THROW_ON_ERROR));
        $barrier = microtime(true) + 1.5 + ($processes / 10);
        $handles = [];
        for ($index = 0; $index < $processes; $index++) {
            $command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, dirname(__DIR__, 2) . '/Support/Admission/settle-race-worker.php', $this->host, (string) $this->port, sprintf('%.6f', $barrier), (string) $index, $spec])) . ' 2>&1';
            $handle = popen($command, 'r');
            if ($handle === false) {
                throw new \RuntimeException('Unable to start a settlement race worker.');
            }
            $handles[] = $handle;
        }
        $out = [];
        foreach ($handles as $handle) {
            $text = (string) stream_get_contents($handle);
            if (pclose($handle) !== 0 || $text === '') {
                throw new \RuntimeException('Settlement race worker failed: ' . $text);
            }
            $decoded = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
            $out[] = is_array($decoded) && ($decoded['ok'] ?? null) === true;
        }

        return $out;
    }

    /** @return list<int> admitted per round */
    private function rounds(int $processes, string $mode): array
    {
        $admitted = [];
        for ($round = 0; $round < 3; $round++) {
            $this->resetRedis();
            $this->store->applyAdmissionOutcome(\Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder::outcome(
                AdmissionOutcomeKindEnum::FAILURE,
                Sc::member(self::KEY, 'seed', self::CAP),
            ), Sc::N);
            $admitted[] = count(array_filter($this->race($processes, $mode)));
        }

        return $admitted;
    }

    private function countedSize(): int
    {
        return ReplyValue::integer($this->raw('SCARD', $this->key(AdmissionScopeKindEnum::SET_SIZE, self::KEY)))
            + ReplyValue::integer($this->raw('ZCOUNT', $this->keys->pendingKey('login', self::KEY), '-inf', (string) Sc::N));
    }

    /** @return iterable<string, array{int}> */
    public static function levels(): iterable
    {
        yield '16 processes' => [16];
        yield '64 processes' => [64];
    }

    #[DataProvider('levels')]
    public function testSettledSizeNeverExceedsTheCapUnderConcurrency(int $processes): void
    {
        self::assertSame([3, 3, 3], $this->rounds($processes, 'store'));
        self::assertLessThanOrEqual(self::CAP, $this->countedSize());
    }

    public function testDetectorNonAtomicSettlementExceedsTheCapUnderTheSameConcurrency(): void
    {
        $all = [...$this->rounds(16, 'nonatomic'), ...$this->rounds(64, 'nonatomic')];
        self::assertGreaterThan(3, max($all), 'DETECTOR: the non-atomic control must exceed the cap: ' . json_encode($all));
    }
}
