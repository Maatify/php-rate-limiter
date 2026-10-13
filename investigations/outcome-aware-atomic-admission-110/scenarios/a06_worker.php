<?php

declare(strict_types=1);

// Child process of a06_concurrency.php: ONE worker on real Redis. Waits for Redis time $startAt (all workers of a round are released at the
// same Redis instant), runs its command list, then INCRs the round's completion counter. Same ownership checks as the parent except
// emptiness. Never flushes; loopback only. LAB_ROOT / OAAA_ARM select the runtime like every scenario (provenance enforced).
//   php a06_worker.php <namespace> <secret> <previousSecret|-> <json [[ip, fp|null, acct, uaIdx, cmd], ...]> <startAt> <counterKey>
// Prints one JSON line {"steps": ["check:A", ...]}.
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;

[, $ns, $secret, $previous, $json, $startAt, $counterKey] = $argv + array_fill(0, 7, null);
oaaaProvenance();
$ex = wsbRedisConnectVerified(false);
$clock = wsbRedisClock($ex);
$store = new RedisFullCapabilityStore($ex, (string) $ns);
$prev = $previous === '-' ? null : (string) $previous;
$pipeline = new EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), (string) $secret, 'prod', $clock, $prev);
$resolver = new DeviceIdentityResolver(new FingerprintHasher((string) $secret), $prev === null ? null : new FingerprintHasher($prev));
$policy = new LoginProtectionPolicy();
$commands = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
while ((int) $ex->execute(['TIME'])[0] < (int) $startAt) {
    usleep(500);
}
$steps = [];
foreach ($commands as [$ip, $fp, $acct, $ua, $cmd]) {
    $c = new RateLimitContextDTO((string) $ip, k1Ua((int) $ua), (string) $acct, $fp === null ? null : fp((string) $fp));
    $command = match ($cmd) {
        'check' => RateLimitCommand::checkOnly('login_protection'),
        'ok' => RateLimitCommand::recordSuccess('login_protection'),
        'fail' => RateLimitCommand::recordFailure('login_protection'),
    };
    $r = $pipeline->process($policy, $c, $command, $resolver->resolve($c));
    $steps[] = $cmd . ':' . ($r->decision === 'ALLOW' ? 'A' : 'B');
    if ($cmd === 'check' && $r->decision !== 'ALLOW') {
        break; // Host-realistic: no outcome after a denied pre-check
    }
}
$ex->execute(['INCR', (string) $counterKey]);
$ex->execute(['EXPIRE', (string) $counterKey, '120']);
echo json_encode(['steps' => $steps], JSON_THROW_ON_ERROR), "\n";
