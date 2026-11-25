<?php

declare(strict_types=1);

// Isolated three-arm comparison. Never run beside the test suites. No production database may be used.
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;

$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
[$script, $host, $port, $arm, $source, $repeat] = $argv;
if ($source !== '-') {
    // Replace only package src classmap entries with the complete exact pinned source tree; never mutate the checkout.
    $map = [];
    foreach ($loader->getClassMap() as $class => $path) {
        if (str_starts_with($class, 'Maatify\\RateLimiter\\') && !str_starts_with($class, 'Maatify\\RateLimiter\\Tests\\')) {
            $map[$class] = $source . '/src/' . str_replace('\\', '/', substr($class, strlen('Maatify\\RateLimiter\\'))) . '.php';
        }
    }
    $loader->addClassMap($map);
    $loader->addPsr4('Maatify\\RateLimiter\\', $source . '/src', true);
}
$loaded = (new ReflectionClass(RateLimiterBuilder::class))->getFileName();
$expected = $source === '-' ? realpath(dirname(__DIR__, 2) . '/src/Builder/RateLimiterBuilder.php') : realpath($source . '/src/Builder/RateLimiterBuilder.php');
if ($loaded !== $expected) {
    throw new RuntimeException('Wrong source arm.');
}
$resp = new RespRedisCommandExecutor($host, (int) $port, 'eval');
$resp->execute(['FLUSHDB']);
$now = 1793793600; // Identical caller-time input in every arm, repeat and integer width.
$clock = new FixedClock('@' . $now);
$commits = 0;
$conflicts = 0;
$forceConflict = false;
$root = null;
$wire = new CallableRedisCommandExecutor(function (array $command) use ($resp, &$commits, &$conflicts, &$forceConflict): mixed {
    if (OverlayWire::isCommit($command)) {
        $commits++;
        if ($forceConflict) {
            $forceConflict = false;
            // Change one live score after its snapshot, outside the request overlay.
            foreach (OverlayWire::keys($command) as $key) {
                if (str_contains($key, ':score:')) {
                    $resp->execute(['HINCRBY', $key, 'value', 1]);
                    break;
                }
            }
        }
        $reply = $resp->execute($command);
        if ($reply === 0) {
            $conflicts++;
        }
        return $reply;
    }
    return $resp->execute($command);
});
$count = 100;
$warmup = 15;
$build = function (RedisFullCapabilityStore $store, bool $rotation = false, bool $admission = false) use ($arm, $clock): RateLimiterBuilder {
    $config = new RateLimiterConfig('key-current', 'fp-current', 'bench', $rotation ? 'key-previous' : null, $rotation ? 'fp-previous' : null, bindingKeySecret: 'binding');
    $b = RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())->withClock($clock)
        ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('simple', 100000, 600));
    // The non-account control scores K1 only; it still traverses the complete public graph.
    $b->withPolicy(new class implements \Maatify\RateLimiter\Config\BlockPolicyInterface {
        public function getName(): string
        {
            return 'k1-control';
        }
        public function getFailureMode(): string
        {
            return 'FAIL_CLOSED';
        }
        public function getBudgetConfig(): ?\Maatify\RateLimiter\DTO\BudgetConfigDTO
        {
            return null;
        }
        public function getScoreThresholds(): \Maatify\RateLimiter\DTO\PolicyThresholdsDTO
        {
            return new \Maatify\RateLimiter\DTO\PolicyThresholdsDTO(k1: new \Maatify\RateLimiter\DTO\ScoreThresholdsDTO(1000000, 2000000, 3000000));
        }
        public function getScoreDeltas(): \Maatify\RateLimiter\DTO\ScoreDeltasDTO
        {
            return new \Maatify\RateLimiter\DTO\ScoreDeltasDTO(access: 1);
        }
    });
    if ($admission) {
        $b->withPolicy(new LoginProtectionAdmissionPolicy());
    }
    if ($arm === 'base-e1') {
        $b->withKeyScopeEncoding(\Maatify\RateLimiter\Enum\KeyScopeEncodingEnum::E1);
    }
    return $b;
};
$context = static fn(int $i, ?string $credential = null): RateLimitContextDTO => new RateLimitContextDTO('198.18.' . intdiv($i, 250) . '.' . ($i % 250 + 1), 'Mozilla/5.0 bench', 'account-' . $i, ['device' => 'd-' . $i], clientInstanceId: 'instance-' . $i, admissionCredential: $credential);
$measure = function (string $scenario, Closure $run) use ($count, $warmup, $arm, $repeat, $resp, &$commits, &$conflicts): void {
    for ($i = 0; $i < $warmup; $i++) {
        $run($i);
    }
    $times = [];
    $outcomes = [];
    $c0 = $resp->commands;
    $b0 = $resp->bytesSent;
    $b1 = $resp->bytesReceived;
    $s0 = $commits;
    $f0 = $conflicts;
    $usage0 = getrusage();
    memory_reset_peak_usage();
    for ($i = $warmup; $i < $warmup + $count; $i++) {
        $t = hrtime(true);
        $result = $run($i);
        $times[] = (hrtime(true) - $t) / 1000;
        $key = is_object($result) && property_exists($result, 'decision') ? $result->decision : (is_bool($result) ? ($result ? 'true' : 'false') : get_debug_type($result));
        if ($scenario === 'claim' && $result !== true) {
            throw new RuntimeException('Claim fixture did not exercise a successful claim.');
        }
        if ($scenario === 'k1-only' && (!is_object($result) || $result->decision !== 'ALLOW')) {
            throw new RuntimeException('The K1-only control was contaminated by a collective correlation signal.');
        }
        $outcomes[$key] = ($outcomes[$key] ?? 0) + 1;
    }
    $usage = getrusage();
    $total = array_sum($times);
    $raw = $times;
    sort($times);
    $cpu = (($usage['ru_utime.tv_sec'] - $usage0['ru_utime.tv_sec']) + ($usage['ru_stime.tv_sec'] - $usage0['ru_stime.tv_sec'])) * 1000000 + ($usage['ru_utime.tv_usec'] - $usage0['ru_utime.tv_usec']) + ($usage['ru_stime.tv_usec'] - $usage0['ru_stime.tv_usec']);
    echo json_encode(['arm' => $arm,'repeat' => (int) $repeat,'scenario' => $scenario,'php' => PHP_VERSION,'intSize' => PHP_INT_SIZE,'n' => $count,'warmup' => $warmup,'p50us' => $times[49],'p95us' => $times[94],'p99us' => $times[98],'opsPerSecond' => 1000000 * $count / $total,'cpuUs' => $cpu,'peakBytes' => memory_get_peak_usage(true),'commands' => $resp->commands - $c0,'commits' => $commits - $s0,'conflicts' => $conflicts - $f0,'bytesSent' => $resp->bytesSent - $b0,'bytesReceived' => $resp->bytesReceived - $b1,'outcomes' => $outcomes,'latencyUs' => $raw], JSON_THROW_ON_ERROR) . "\n";
};
foreach (['compose-runtime','compose-reader','allow','failure','blocked','rotation','reader','unproven','proven','k1-only','simple','claim','mixed-save','mixed-conflict'] as $scenario) {
    $resp->execute(['FLUSHDB']);
    $clock->setNow(new DateTimeImmutable('@' . $now));
    $root = new RedisFullCapabilityStore($wire, 'bench-' . $scenario, $clock);
    $admission = in_array($scenario, ['unproven','proven'], true);
    $b = $build($root, $scenario === 'rotation', $admission);
    $runtime = $b->build();
    $reader = $b->buildOperationalReader();
    if ($admission) {
        $runtime->openAdmissionFence('login');
    }
    $credentials = [];
    $claims = [];
    $old = null;
    $previous = null;
    if (in_array($scenario, ['blocked','rotation','claim','proven'], true)) {
        $old = $scenario === 'rotation' ? RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('key-previous', 'fp-previous', 'bench'), $root, new RecordingFailureSignalEmitter())->withClock($clock) : null;
        if ($old !== null && $arm === 'base-e1') {
            $old->withKeyScopeEncoding(\Maatify\RateLimiter\Enum\KeyScopeEncodingEnum::E1);
        }
        $previous = $old?->build();
        for ($i = 0; $i < $count + $warmup; $i++) {
            if ($scenario === 'blocked') {
                $key = $arm === 'base-v2' ? (new \Maatify\RateLimiter\Service\V2AccountScopeKeyDeriver('bench'))->derive('k4', 'login_protection', 'account-' . $i, 'key-current') : (new \Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver('bench'))->derive('k4', 'login_protection', 'account-' . $i, 'key-current');
                ($arm === 'base-v2' ? $root : $root->e1Keyspace())->blockAt($key, 2, 600, $now);
            } elseif ($scenario === 'rotation') {
                $previous->limit($context($i), RateLimitCommand::recordFailure('login_protection'));
            } elseif ($scenario === 'proven') {
                $credentials[$i] = (new AdmissionCredentialCodec('binding'))->encode(new AdmissionCredentialDTO('account-' . $i, ['login'], 'instance-' . $i, 'jti-' . $i, $now + 86400, $now, $runtime->bindingEpoch('account-' . $i)));
            } else {
                for ($j = 0;$j < 6;$j++) {
                    $runtime->limit($context($i), RateLimitCommand::recordFailure('login_protection'));
                }
            }
        }
        if ($scenario === 'claim') {
            $wait = $runtime->limit($context(0), RateLimitCommand::checkOnly('login_protection'))->retryAfter;
            $clock->setNow(new DateTimeImmutable('@' . ($now + $wait + 1)));
            for ($i = 0;$i < $count + $warmup;$i++) {
                $claims[$i] = $runtime->limit($context($i), RateLimitCommand::checkOnly('login_protection'))->metadata?->postPunishmentReentry?->id ?? throw new RuntimeException('Missing claim fixture.');
            }
        }
    }
    $measure($scenario, function (int $i) use ($scenario, $build, $root, $runtime, $reader, $context, $credentials, $claims, &$forceConflict): mixed {
        return match ($scenario) {
            'compose-runtime' => $build($root)->build(),
            'compose-reader' => $build($root)->buildOperationalReader(),
            'failure' => $runtime->limit($context($i), RateLimitCommand::recordFailure('login_protection')),
            'reader' => $reader->readScorePolicy($context($i), 'login_protection'),
            'simple' => $runtime->consume('simple', 'subject-' . $i),
            'k1-only' => $runtime->limit(new RateLimitContextDTO('198.18.0.' . ($i + 1), 'Mozilla/5.0 bench', clientFingerprint: ['device' => 'control-' . $i]), RateLimitCommand::recordFailure('k1-control')),
            'claim' => $runtime->claimPostPunishmentReentry($context($i), 'login_protection', $claims[$i]),
            'mixed-save','mixed-conflict' => (function () use ($root, $i, $scenario, &$forceConflict): int {
                $key = 'e1.' . hash('sha256', 'account-' . $i);
                $root->increment('source-' . $i, 600);
                $forceConflict = $scenario === 'mixed-conflict';
                return $root->transactRequest(function () use ($root, $key, $i): int {
                    $root->increment('source-' . $i, 600);
                    return $root->e1Keyspace()->increment($key, 600);
                });
            })(),
            default => $runtime->limit($context($i, $credentials[$i] ?? null), RateLimitCommand::checkOnly('login_protection')),
        };
    });
    unset($runtime,$reader,$b,$root,$old,$previous);
    gc_collect_cycles();
}
echo json_encode(['source' => basename(dirname($expected, 2)), 'pipelineSha256' => hash_file('sha256', (new ReflectionClass(\Maatify\RateLimiter\Service\EvaluationPipeline::class))->getFileName()), 'builderSha256' => hash_file('sha256', $expected),'arm' => $arm,'php' => PHP_VERSION,'intSize' => PHP_INT_SIZE,'redis' => $resp->execute(['INFO','server'])], JSON_THROW_ON_ERROR) . "\n";
