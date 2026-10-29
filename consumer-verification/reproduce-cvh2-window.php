<?php

declare(strict_types=1);

// #173 (investigations/cvh2-window-173/PLAN.md §4, §9): deterministic reproduction of the CVH-2 window split.
// The source capacity counts in fixed windows of intdiv(t, 600) on the runtime's clock. This script runs the
// CVH-2 sequence of verify.php (24 admissions from one source, then a 25th) on an admission runtime whose
// FixedClock it moves itself, 20 times per clock position, each in its own environment (own Redis keys).
//
//   CVH2_REPRO_MODE=crossing   the clock starts 1 s before a 600 s boundary and moves 2 s forward after the
//                              12th admission: the count splits and the 25th is ALLOWED (the phenomenon, (a))
//   CVH2_REPRO_MODE=positions  the clock stays fixed (no advance) at five positions around a boundary: the 25th
//                              is refused, SOFT_BLOCK level 1 source_capacity / source, at every position ((b))
//
// It prints one JSON line per sequence and a summary, and exits 0 only if every sequence matched its mode.
// Run through scripts/ci/run-single-consumer-verification.sh with CONSUMER_VERIFICATION_SCRIPT set to this file.

use ConsumerVerification\FixedClock;
use ConsumerVerification\RecordingFailureSignalEmitter;
use ConsumerVerification\RespRedisCommandExecutor;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;

require __DIR__ . '/vendor/autoload.php';

const REPETITIONS = 20;
const WINDOW = 600;

$mode = getenv('CVH2_REPRO_MODE') ?: 'crossing';
$host = getenv('REDIS_INTEGRATION_HOST');
$port = getenv('REDIS_INTEGRATION_PORT');
if (! is_string($host) || ! is_string($port) || ! ctype_digit($port)) {
    throw new RuntimeException('Redis lifecycle variables are missing.');
}
$raw = new RespRedisCommandExecutor($host, (int) $port);
$raw->execute(['FLUSHDB']);
$store = new RedisFullCapabilityStore($raw, 'consumer-cvh2-repro');
$clock = new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$at = static function (int $unix) use ($clock): void {
    $clock->setNow((new DateTimeImmutable('@' . $unix))->setTimezone(new DateTimeZone('UTC')));
};
$context = static fn(string $account, string $ip): RateLimitContextDTO => new RateLimitContextDTO(
    $ip, 'Mozilla/5.0 consumer-admission', $account, ['device' => 'device-' . $account], null, false, [], false, null,
);
$outcome = static fn(?string $permit): RateLimitCommand => new RateLimitCommand('login_protection', 1, false, false, true, $permit);

/** The CVH-2 sequence at $start; $advanceAfter: the admission index after which the clock moves 2 s forward. */
$sequence = static function (string $env, int $start, ?int $advanceAfter) use ($store, $clock, $at, $context, $outcome): array {
    $at($start);
    $runtime = RateLimiterBuilder::fromFullCapabilityStore(
        new RateLimiterConfig('admission-key', 'admission-fingerprint', $env, bindingKeySecret: 'admission-binding'),
        $store,
        new RecordingFailureSignalEmitter(),
    )->withPolicy(new LoginProtectionAdmissionPolicy())->withClock($clock)->build();
    if (! $runtime instanceof AdmissionFenceControlInterface || $runtime->openAdmissionFence('login') !== 1) {
        return ['valid' => false, 'error' => 'fence'];
    }
    $windows = [];
    for ($i = 0; $i < 24; $i++) {
        $windows[intdiv($clock->now()->getTimestamp(), WINDOW)] = true;
        $c = $context('cvh2-' . $i, '203.0.113.120');
        $r = $runtime->limit($c, RateLimitCommand::checkOnly('login_protection'));
        if ($r->decision !== RateLimitResultDTO::DECISION_ALLOW || ! is_string($r->metadata?->permit)) {
            return ['valid' => false, 'error' => "admission {$i} not allowed"];
        }
        $runtime->limit($c, $outcome($r->metadata->permit));
        if ($advanceAfter !== null && $i === $advanceAfter) {
            $at($start + 2);
        }
    }
    $windows[intdiv($clock->now()->getTimestamp(), WINDOW)] = true;
    $r = $runtime->limit($context('cvh2-24', '203.0.113.120'), RateLimitCommand::checkOnly('login_protection'));

    return ['valid' => true, 'windows' => count($windows), 'decision' => $r->decision, 'level' => $r->blockLevel,
        'cause' => $r->metadata?->cause, 'scope' => $r->metadata?->context?->scope];
};

$boundary = (intdiv(time(), WINDOW) + 1) * WINDOW;
$random = random_int(0, WINDOW - 1);
$cases = $mode === 'crossing'
    ? ['boundary-1 then +2 s after the 12th' => [$boundary - 1, 11]]
    : ['boundary-1' => [$boundary - 1, null], 'boundary' => [$boundary, null], 'boundary+1' => [$boundary + 1, null],
        'boundary+599' => [$boundary + 599, null], "boundary+{$random} (random)" => [$boundary + $random, null]];
$expected = static fn(array $row): bool => $mode === 'crossing'
    ? $row['decision'] === RateLimitResultDTO::DECISION_ALLOW
    : $row['decision'] === RateLimitResultDTO::DECISION_SOFT_BLOCK && $row['level'] === 1
        && $row['cause'] === 'source_capacity' && $row['scope'] === 'source';

$summary = [];
foreach ($cases as $name => [$start, $advanceAfter]) {
    $hits = 0;
    for ($repetition = 0; $repetition < REPETITIONS; $repetition++) {
        $row = $sequence("cvh2-repro-{$mode}-" . md5($name) . "-{$repetition}", $start, $advanceAfter);
        $holds = $row['valid'] && $expected($row);
        $hits += (int) $holds;
        echo json_encode(['mode' => $mode, 'case' => $name, 'start' => $start, 'repetition' => $repetition] + $row + ['holds' => $holds], JSON_UNESCAPED_SLASHES), "\n";
    }
    $summary[$name] = "{$hits}/" . REPETITIONS;
}
$all = array_sum(array_map(static fn(string $s): int => (int) explode('/', $s)[0], $summary)) === REPETITIONS * count($summary);
echo json_encode(['mode' => $mode, 'boundary' => $boundary, 'summary' => $summary, 'holds' => $all], JSON_UNESCAPED_SLASHES), "\n";
exit($all ? 0 : 1);
