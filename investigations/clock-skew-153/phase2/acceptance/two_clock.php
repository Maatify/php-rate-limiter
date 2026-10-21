<?php

declare(strict_types=1);

/*
 * #153 phase 2, the two-clock arm (PLAN.md §4.7, §8 LT-P2-04, Owner decision):
 * one Redis, a writer Host whose clock is 1 s ahead and a reader Host at 0,
 * on the fixed code. It measures the residual that the Host duty "a fleet that
 * shares one Redis shares one time source" leaves when that duty is broken by
 * δ = 1 s, with a same-clock control for every cell (δ = 0).
 * argv: package root, Redis host, Redis port, output file.
 *
 * Every clock is anchored to Redis TIME (RedisAnchoredClock) and every clock
 * of a pair gets the same virtual advance, so writer and reader differ by
 * exactly δ while no real wait is needed.
 *
 *   TC-A1  snapshot: the writer's pre-check creates the device window; the
 *          reader's immediate pre-check on the same account: validator
 *          exceptions / 2,000.
 *   TC-A2  the reader's throwing period after the writer's creation: 20
 *          accounts, reader pre-checks back to back for 1.5 s (real time).
 *   TC-B   decay (login mirror without DISTRIBUTED_ACCOUNT): two failures at
 *          the writer, wait the writer's retryAfter, check at the reader:
 *          refused / 200.
 *   TC-E   lifecycle block (login_protection): three failures at the writer
 *          (L2 block), wait the writer's retryAfter, check at the reader:
 *          refused / 200.
 */

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Clock\RedisAnchoredClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

[$self, $root, $host, $port, $outFile] = $argv + [null, null, null, null, null];
require $root . '/vendor/autoload.php';

const WRITER_AHEAD = 1.0;
const MIRROR = 'login_mirror_without_distributed';

$port = (int) $port;
$handle = fopen($outFile, 'wb');
$emit = static function (array $row) use ($handle): void {
    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
};

$runtime = static function (RedisFullCapabilityStore $store, RedisAnchoredClock $clock): CompositeRateLimiterRuntimeInterface {
    $mirror = new class () extends LoginProtectionPolicy {
        public function getName(): string
        {
            return MIRROR;
        }

        public function getCapabilities(): array
        {
            return [PolicyCapabilityEnum::CREDENTIAL_SPRAY, PolicyCapabilityEnum::TRUSTED_AUTHENTICATION];
        }
    };

    return RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('two-clock-key', 'two-clock-fp', 'prod'), $store, new RecordingFailureSignalEmitter())
        ->withClock($clock)
        ->withPolicy($mirror)
        ->build();
};

$context = static function (string $account, int $index): RateLimitContextDTO {
    $ip = '198.' . (18 + intdiv($index, 65536)) . '.' . (intdiv($index, 256) % 256) . '.' . ($index % 256);

    return new RateLimitContextDTO($ip, 'Mozilla/5.0', $account, ['device' => 'd-' . $account]);
};

$attempt = static function (CompositeRateLimiterRuntimeInterface $runtime, RateLimitContextDTO $context, RateLimitCommand $command): array {
    try {
        $result = $runtime->limit($context, $command);

        return ['d' => $result->decision, 'ra' => $result->retryAfter, 're' => $result->metadata?->postPunishmentReentry !== null];
    } catch (Throwable $error) {
        return ['x' => $error::class . ': ' . $error->getMessage()];
    }
};

foreach (['two-clock' => WRITER_AHEAD, 'control' => 0.0] as $arm => $ahead) {
    // One store namespace per arm and cell, so the arms never share state.
    $cell = static function (string $name) use ($host, $port, $arm, $ahead, $runtime): array {
        $store = new RedisFullCapabilityStore(new RespRedisCommandExecutor($host, $port), 'two-clock:' . bin2hex(random_bytes(4)) . ':' . $arm . ':' . $name);
        $writerClock = new RedisAnchoredClock(new RespRedisCommandExecutor($host, $port), $ahead);
        $readerClock = new RedisAnchoredClock(new RespRedisCommandExecutor($host, $port), 0.0);

        return [$runtime($store, $writerClock), $runtime($store, $readerClock), $writerClock, $readerClock];
    };

    // TC-A1
    [$writer, $reader] = $cell('A1');
    for ($i = 0; $i < 2000; $i++) {
        $ctx = $context("A1-$i", $i);
        $w = $attempt($writer, $ctx, RateLimitCommand::checkOnly('login_protection'));
        $r = $attempt($reader, $ctx, RateLimitCommand::checkOnly('login_protection'));
        $emit(['cell' => 'TC-A1', 'arm' => $arm, 'ahead' => $ahead, 'i' => $i, 'w' => $w, 'r' => $r]);
    }

    // TC-A2
    [$writer, $reader] = $cell('A2');
    for ($j = 0; $j < 20; $j++) {
        $ctx = $context("A2-$j", 3000 + $j);
        $created = microtime(true);
        $attempt($writer, $ctx, RateLimitCommand::checkOnly('login_protection'));
        $lastThrow = null;
        $n = 0;
        $x = 0;
        while (microtime(true) - $created < 1.5) {
            $r = $attempt($reader, $ctx, RateLimitCommand::checkOnly('login_protection'));
            $n++;
            if (isset($r['x'])) {
                $x++;
                $lastThrow = microtime(true) - $created;
            }
        }
        $emit(['cell' => 'TC-A2', 'arm' => $arm, 'ahead' => $ahead, 'j' => $j, 'creationFraction' => fmod($created, 1.0), 'requests' => $n, 'exceptions' => $x, 'lastThrowAfter' => $lastThrow]);
    }

    // TC-B and TC-E: the client waits the writer's retryAfter (virtual), then the reader decides.
    foreach (['TC-B' => [MIRROR, 2], 'TC-E' => ['login_protection', 3]] as $name => [$policy, $failures]) {
        for ($i = 0; $i < 200; $i++) {
            [$writer, $reader, $writerClock, $readerClock] = $cell($name . '-' . $i);
            $ctx = $context("$name-$i", 5000 + $i);
            $last = [];
            for ($f = 0; $f < $failures; $f++) {
                $last = $attempt($writer, $ctx, RateLimitCommand::recordFailure($policy));
            }
            $wait = (float) ($last['ra'] ?? 0);
            $writerClock->advance($wait);
            $readerClock->advance($wait);
            $r = $attempt($reader, $ctx, RateLimitCommand::checkOnly($policy));
            $emit(['cell' => $name, 'arm' => $arm, 'ahead' => $ahead, 'i' => $i, 'writerLast' => $last, 'wait' => $wait, 'r' => $r]);
        }
    }
}
fclose($handle);
