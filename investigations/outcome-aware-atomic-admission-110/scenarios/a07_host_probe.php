<?php

declare(strict_types=1);

// A07 — Host integration probe (real Redis, PUBLIC API only): the limiter is built with the public RateLimiterBuilder over the official
// RedisFullCapabilityStore (on OAAA: its isolated patched copy) and used through RateLimiterInterface::limit() by a framework-free Native PHP
// Host adapter that verifies passwords ITSELF (a password table stands in for the Host's verifier; the library never authenticates):
//   checkOnly -> (denied: 429) -> Host verifies -> recordSuccess | recordFailure      ("pre+out", the documented flow)
//   checkOnly -> (denied: 429) -> Host verifies                                       ("pre" only: no outcome reported)
// The SAME adapter code runs on both arms: OAAA needs no new Host call, field or metadata.
//   H1  office NAT (8 colleagues, 2 typos) + a sprayer behind the same NAT (20 victims), pre+out
//   H2  the same traffic, pre-only Host
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

emit('A07.provenance', oaaaProvenance());
$arm = oaaaArm();
$cand = $arm === 'OAAA';
$ex = wsbRedisConnectVerified(true);

/** The Host adapter (what a consumer writes). Returns 'denied' | 'success' | 'failure' and counts library calls. */
final class HostLogin
{
    public int $libraryCalls = 0;

    /** @param array<string, string> $passwords */
    public function __construct(private readonly RateLimiterInterface $limiter, private readonly array $passwords, private readonly bool $reportOutcome) {}

    public function attempt(RateLimitContextDTO $context, string $password): string
    {
        $this->libraryCalls++;
        $pre = $this->limiter->limit($context, RateLimitCommand::checkOnly('login_protection'));
        if ($pre->isBlocked()) {
            return 'denied';                                        // HTTP 429 + Retry-After: $pre->retryAfter
        }
        $ok = isset($this->passwords[(string) $context->accountId]) && hash_equals($this->passwords[(string) $context->accountId], $password);
        if ($this->reportOutcome) {
            $this->libraryCalls++;
            $this->limiter->limit($context, $ok ? RateLimitCommand::recordSuccess('login_protection') : RateLimitCommand::recordFailure('login_protection'));
        }

        return $ok ? 'success' : 'failure';
    }
}

$passwords = [];
for ($i = 0; $i < 8; $i++) {
    $passwords["office$i"] = "pw-office-$i";
}
for ($i = 0; $i < 20; $i++) {
    $passwords["victim$i"] = "pw-victim-$i";
}
// Deterministic traffic: 40 colleague logins (2 typos) interleaved with 20 spray attempts from t (request index) 10 on, one NAT, one browser.
$traffic = [];
for ($i = 0; $i < 40; $i++) {
    $u = $i % 8;
    $traffic[] = ['kind' => 'legit', 'acct' => "office$u", 'fp' => "office-device-$u", 'password' => in_array($i, [13, 27], true) ? 'typo' : "pw-office-$u"];
    if ($i >= 10 && $i < 30) {
        $traffic[] = ['kind' => 'attack', 'acct' => 'victim' . ($i - 10), 'fp' => null, 'password' => 'guess'];
    }
}
$probe = static function (string $ns, bool $reportOutcome) use ($ex, $passwords, $traffic): array {
    $store = new RedisFullCapabilityStore($ex, $ns);
    $limiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('host-key-secret', 'host-fingerprint-secret', 'production'), $store, new RecordingFailureSignalEmitter())
        ->withClock(wsbRedisClock($ex))
        ->build();
    $host = new HostLogin($limiter, $passwords, $reportOutcome);
    $r = ['legitDenied' => 0, 'legitSucceeded' => 0, 'attackVerified' => 0, 'attackDenied' => 0];
    foreach ($traffic as $t) {
        $res = $host->attempt(new RateLimitContextDTO('203.0.113.77', CHROME, $t['acct'], $t['fp'] === null ? null : fp($t['fp'])), $t['password']);
        if ($t['kind'] === 'legit') {
            $r['legitDenied'] += $res === 'denied' ? 1 : 0;
            $r['legitSucceeded'] += $res === 'success' ? 1 : 0;
        } else {
            $r['attackVerified'] += $res === 'denied' ? 0 : 1;
            $r['attackDenied'] += $res === 'denied' ? 1 : 0;
        }
    }
    $oaaaKeys = 0;
    foreach (array_map('strval', (array) $ex->execute(['KEYS', 'maatify:rate-limiter:v1:' . hash('sha256', $ns) . ':oaaa:*'])) as $k) {
        $oaaaKeys++;
    }

    return $r + ['libraryCalls' => $host->libraryCalls, 'attempts' => count($traffic), 'oaaaKeys' => $oaaaKeys];
};
$h1 = $probe('oaaa:host:pre-out', true);
$h2 = $probe('oaaa:host:pre', false);
emit('A07.H1.host-pre-out', ['arm' => $arm] + $h1);
emit('A07.H2.host-pre-only', ['arm' => $arm] + $h2);
OaaaAsserts::check('A07', 'H0 the Host adapter makes exactly the documented calls on this arm (checkOnly per attempt, plus one outcome call per admitted attempt in pre+out) and is identical code on both arms', $h1['libraryCalls'] === $h1['attempts'] + $h1['legitSucceeded'] + ($h1['attempts'] - $h1['legitDenied'] - $h1['attackDenied'] - $h1['legitSucceeded']) && $h2['libraryCalls'] === $h2['attempts'], ['h1' => $h1['libraryCalls'], 'h2' => $h2['libraryCalls']]);
OaaaAsserts::check('A07', 'H1 ' . ($cand ? 'pre+out through the public API: OAAA keys are written, the office is denied less than on S0 would be (S0 reference in the S0 row) and the co-located sprayer reaches the Host verifier at most 2 times more than its 5th-subject S0 baseline (2 units, one epoch)' : 'S0 reference: no OAAA key is written'), $cand
    ? $h1['oaaaKeys'] > 0 && $h1['attackVerified'] <= 6
    : $h1['oaaaKeys'] === 0, $h1);
OaaaAsserts::check('A07', 'H2 ' . ($cand ? 'a pre-only Host (no outcome) earns no credit: no reservation can be granted' : 'S0 reference: pre-only Host'), $cand ? $h2['attackVerified'] <= 4 : $h2['oaaaKeys'] === 0, $h2);
OaaaAsserts::finish();
