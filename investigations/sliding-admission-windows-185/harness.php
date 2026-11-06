<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\AdmissionLaneDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ReplaySession;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamReplay;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$host = getenv('REDIS_INTEGRATION_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_INTEGRATION_PORT') ?: 6379);

echo "=======================================================================\n";
echo "Sliding Admission Windows Measurement Harness (#185, DEC-033)\n";
echo "Redis: {$host}:{$port}\n";
echo "=======================================================================\n\n";

$executor = new RespRedisCommandExecutor($host, $port);
$namespace = 'meas_sliding:';

// -----------------------------------------------------------------------------
// 1. NM-10 Parity: RedisAdmissionOperations vs AdmissionInMemoryReferenceStore
// -----------------------------------------------------------------------------
echo "[1] Measuring NM-10 Store Parity (Redis vs In-Memory Reference)...\n";

$parityMatches = 0;
$parityTotal = 0;

$sampleStreams = ['BRD|rotate|fresh', 'X2|none|const', 'L1|none|fresh', 'ABA_B|none|fresh'];
$streams = ArchiveData::get()->streams();
foreach ($streams as $s) {
    $key = $s['stream'] . '|' . $s['strategy'] . '|' . $s['ids'];
    if (!in_array($key, $sampleStreams, true)) {
        continue;
    }
    $executor->execute(['FLUSHDB']);
    $redisStore = new RedisFullCapabilityStore($executor, $namespace);
    $redisLab = new EngineLab(StreamReplay::fullBudgets(), admissionInner: $redisStore);
    $redisResult = StreamReplay::run($s['rows'], 'pre+out', $redisLab, 1.0);

    $memLab = new EngineLab(StreamReplay::fullBudgets());
    $memResult = StreamReplay::run($s['rows'], 'pre+out', $memLab, 1.0);

    $parityTotal++;
    if ($redisResult === $memResult) {
        $parityMatches++;
        echo "  Stream {$key}: 100% PARITY (decisions, reach, causes match exactly)\n";
    } else {
        echo "  Stream {$key}: MISMATCH\n";
    }
}

$parityPct = ($parityMatches / $parityTotal) * 100.0;
echo "  Evaluated Streams: {$parityTotal}\n";
echo "  Parity Matches:    {$parityMatches}\n";
echo "  NM-10 Parity:      {$parityPct}%\n\n";

// -----------------------------------------------------------------------------
// 2. G7 PX4 Replay Measurements (TPAIRT, TMS, TMSd)
// -----------------------------------------------------------------------------
echo "[2] Measuring G7 PX4 Archive Replay Metrics...\n";

function replayAllArm(string $arm): array
{
    $budgets = $arm === 'NOUA2' ? StreamReplay::fullBudgets() : EngineLab::budgets();
    $out = [];
    foreach (ArchiveData::get()->g7Streams() as $tuple) {
        $lab = new EngineLab($budgets);
        $s = new ReplaySession($lab, 'pre+out');
        $s->replay($tuple['rows']);
        $r = $s->result();
        $out[$tuple['stream'] . '|' . $tuple['strategy'] . '|' . $tuple['ids']] = $r;
    }
    return $out;
}

function bestMetric(array $rows, string $stream): array
{
    $reach = 0;
    $legit = 0;
    foreach ($rows as $key => $r) {
        [$s] = explode('|', $key);
        if ($s === $stream) {
            $reach = max($reach, ($r['m']['reachForeign'] ?? 0) + ($r['m']['reachProof'] ?? 0));
            $legit = max($legit, $r['m']['legitDenied'] ?? 0);
        }
    }
    return ['reach' => $reach, 'legit' => $legit];
}

$pxRows = replayAllArm('PX4');
$refRows = replayAllArm('NOUA2');

$bestTpairtPx = bestMetric($pxRows, 'TPAIRT');
$bestTpairtRef = bestMetric($refRows, 'TPAIRT');
$bestTmsPx = bestMetric($pxRows, 'TMS');
$bestTmsdPx = bestMetric($pxRows, 'TMSd');

echo "  TPAIRT (PX4) co-located office cost: {$bestTpairtPx['legit']} / 61 (expected ~21, actual {$bestTpairtPx['legit']})\n";
echo "  TPAIRT (PX4) attacker reach:         {$bestTpairtPx['reach']} / 120 (reference: {$bestTpairtRef['reach']})\n";
echo "  TMS (PX4) attacker reach:            {$bestTmsPx['reach']} / 61 (bound: <= 29)\n";
echo "  TMSd (PX4) attacker reach:           {$bestTmsdPx['reach']} / 200 (bound: <= 40)\n\n";

// -----------------------------------------------------------------------------
// 3. DEC-025 Budgets & Sliding Durations
// -----------------------------------------------------------------------------
echo "[3] Verifying DEC-025 Budgets & Sliding Windows...\n";
$budgets = AdmissionBudgetsDTO::packageDefaults();
echo "  sourceCapacity:            {$budgets->sourceCapacity} (W = {$budgets->windowSeconds}s)\n";
echo "  crossAccountFailureCap:    {$budgets->crossAccountFailureCap} (W = {$budgets->windowSeconds}s)\n";
echo "  proofAccountWindow:        {$budgets->proofAccountWindow} (W = {$budgets->windowSeconds}s)\n";
echo "  proofProofWindow:          {$budgets->proofProofWindow} (W = {$budgets->windowSeconds}s)\n";
echo "  proofAccountDay:           {$budgets->proofAccountDay} (Day = 86400s)\n";
echo "  proofProofDay:             {$budgets->proofProofDay} (Day = 86400s)\n\n";

// -----------------------------------------------------------------------------
// 4. Runtime Boundary Burst Enforcement
// -----------------------------------------------------------------------------
echo "[4] Measuring Runtime Boundary Bursts Across Boundaries...\n";

function initRuntime(FixedClock $clock): AdmissionRuntime
{
    global $executor, $namespace;
    $executor->execute(['FLUSHDB']);
    $store = new RedisFullCapabilityStore($executor, $namespace);
    $codec = new AdmissionCredentialCodec('secret-burst-binding');
    return new AdmissionRuntime(
        $store,
        $codec,
        AdmissionBudgetsDTO::packageDefaults(),
        $clock,
        'secret-burst-key',
        'burst-env',
    );
}

function allowedResult(): RateLimitResultDTO
{
    return new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, null, null, 'NORMAL');
}

function emptyDevice(): DeviceIdentityDTO
{
    return new DeviceIdentityDTO(null, 'LOW', false);
}

function context(string $ip, ?string $account = null): RateLimitContextDTO
{
    return new RateLimitContextDTO(ip: $ip, ua: 'burst-agent', accountId: $account);
}

function provenLane(string $subject, string $jti, int $now): AdmissionLaneDTO
{
    $claims = new AdmissionCredentialDTO(
        subject: $subject,
        budgetGroups: ['login'],
        instance: 'inst-burst',
        jti: $jti,
        expiresAt: $now + 86400,
        boundAt: $now,
        epoch: 0,
    );

    return new AdmissionLaneDTO(AdmissionLaneEnum::P, $claims);
}

function setTime(FixedClock $clock, int $t): void
{
    $clock->setNow(new \DateTimeImmutable('@' . $t));
}

// 4.1 ufail
$clock = new FixedClock('@590');
$rt = initRuntime($clock);
$ufailAdmitted = 0;
for ($i = 1; $i <= 4; $i++) {
    $res = $rt->reserve('login_policy', 'login', context('203.0.113.10', "user-u-$i"), emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), allowedResult());
    if ($res->isAllowed()) {
        $ufailAdmitted++;
        $token = (string) $res->metadata?->permit;
        $rt->applyOutcome('login_policy', 'login', context('203.0.113.10', "user-u-$i"), $token, false);
    }
}
setTime($clock, 604);
$res5 = $rt->reserve('login_policy', 'login', context('203.0.113.10', 'user-u-5'), emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), allowedResult());
if ($res5->isAllowed()) {
    $ufailAdmitted++;
}
echo "  ufail boundary burst (cap 4):  admitted = {$ufailAdmitted} (5th blocked: " . (!$res5->isAllowed() ? 'YES' : 'NO') . ", cause: {$res5->metadata?->cause})\n";

// 4.2 pfail
$clock = new FixedClock('@590');
$rt = initRuntime($clock);
$pfailAdmitted = 0;
for ($i = 1; $i <= 4; $i++) {
    $lane = provenLane("user-p-$i", "jti-p-$i", 590);
    $res = $rt->reserve('login_policy', 'login', context('203.0.113.20', "user-p-$i"), emptyDevice(), $lane, allowedResult());
    if ($res->isAllowed()) {
        $pfailAdmitted++;
        $token = (string) $res->metadata?->permit;
        $rt->applyOutcome('login_policy', 'login', context('203.0.113.20', "user-p-$i"), $token, false);
    }
}
setTime($clock, 604);
$lane5 = provenLane('user-p-5', 'jti-p-5', 604);
$res5 = $rt->reserve('login_policy', 'login', context('203.0.113.20', 'user-p-5'), emptyDevice(), $lane5, allowedResult());
if ($res5->isAllowed()) {
    $pfailAdmitted++;
}
echo "  pfail boundary burst (cap 4):  admitted = {$pfailAdmitted} (5th blocked: " . (!$res5->isAllowed() ? 'YES' : 'NO') . ", cause: {$res5->metadata?->cause})\n";

// 4.3 src
$clock = new FixedClock('@590');
$rt = initRuntime($clock);
$srcAdmitted = 0;
for ($i = 1; $i <= 24; $i++) {
    $res = $rt->reserve('login_policy', 'login', context('203.0.113.30'), emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), allowedResult());
    if ($res->isAllowed()) {
        $srcAdmitted++;
    }
}
setTime($clock, 604);
$res25 = $rt->reserve('login_policy', 'login', context('203.0.113.30'), emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), allowedResult());
if ($res25->isAllowed()) {
    $srcAdmitted++;
}
echo "  src boundary burst (cap 24):   admitted = {$srcAdmitted} (25th blocked: " . (!$res25->isAllowed() ? 'YES' : 'NO') . ", cause: {$res25->metadata?->cause})\n";

// 4.4 pacct
$clock = new FixedClock('@590');
$rt = initRuntime($clock);
$pacctAdmitted = 0;
for ($i = 1; $i <= 10; $i++) {
    $lane = provenLane('acct-pacct-test', "jti-pacct-$i", 590);
    $res = $rt->reserve('login_policy', 'login', context("203.0.113.$i", 'acct-pacct-test'), emptyDevice(), $lane, allowedResult());
    if ($res->isAllowed()) {
        $pacctAdmitted++;
    }
}
setTime($clock, 604);
$lane11 = provenLane('acct-pacct-test', 'jti-pacct-11', 604);
$res11 = $rt->reserve('login_policy', 'login', context('203.0.113.11', 'acct-pacct-test'), emptyDevice(), $lane11, allowedResult());
if ($res11->isAllowed()) {
    $pacctAdmitted++;
}
echo "  pacct boundary burst (cap 10): admitted = {$pacctAdmitted} (11th blocked: " . (!$res11->isAllowed() ? 'YES' : 'NO') . ", cause: {$res11->metadata?->cause})\n";

// 4.5 pproof
$clock = new FixedClock('@590');
$rt = initRuntime($clock);
$pproofAdmitted = 0;
for ($i = 1; $i <= 6; $i++) {
    $lane = provenLane("acct-pproof-$i", 'fixed-jti-pproof', 590);
    $res = $rt->reserve('login_policy', 'login', context("203.0.113.$i", "acct-pproof-$i"), emptyDevice(), $lane, allowedResult());
    if ($res->isAllowed()) {
        $pproofAdmitted++;
    }
}
setTime($clock, 604);
$lane7 = provenLane('acct-pproof-7', 'fixed-jti-pproof', 604);
$res7 = $rt->reserve('login_policy', 'login', context('203.0.113.7', 'acct-pproof-7'), emptyDevice(), $lane7, allowedResult());
if ($res7->isAllowed()) {
    $pproofAdmitted++;
}
echo "  pproof boundary burst (cap 6): admitted = {$pproofAdmitted} (7th blocked: " . (!$res7->isAllowed() ? 'YES' : 'NO') . ", cause: {$res7->metadata?->cause})\n";

// 4.6 pacctd
$clock = new FixedClock('@82790');
$rt = initRuntime($clock);
$pacctdAdmitted = 0;
for ($batch = 0; $batch < 6; $batch++) {
    $t = 82790 + ($batch * 600);
    setTime($clock, $t);
    for ($i = 1; $i <= 10; $i++) {
        $idx = ($batch * 10) + $i;
        $lane = provenLane('acct-pacctd-test', "jti-pacctd-$idx", $t);
        $res = $rt->reserve('login_policy', 'login', context("203.0.113.$idx", 'acct-pacctd-test'), emptyDevice(), $lane, allowedResult());
        if ($res->isAllowed()) {
            $pacctdAdmitted++;
        }
    }
}
setTime($clock, 86410);
$lane61 = provenLane('acct-pacctd-test', 'jti-pacctd-61', 86410);
$res61 = $rt->reserve('login_policy', 'login', context('203.0.113.61', 'acct-pacctd-test'), emptyDevice(), $lane61, allowedResult());
if ($res61->isAllowed()) {
    $pacctdAdmitted++;
}
echo "  pacctd day burst (cap 60):     admitted = {$pacctdAdmitted} (61st blocked: " . (!$res61->isAllowed() ? 'YES' : 'NO') . ", cause: {$res61->metadata?->cause})\n";

// 4.7 pproofd
$clock = new FixedClock('@83390');
$rt = initRuntime($clock);
$pproofdAdmitted = 0;
for ($batch = 0; $batch < 5; $batch++) {
    $t = 83390 + ($batch * 600);
    setTime($clock, $t);
    for ($i = 1; $i <= 6; $i++) {
        $idx = ($batch * 6) + $i;
        $lane = provenLane("acct-pproofd-$idx", 'fixed-jti-pproofd', $t);
        $res = $rt->reserve('login_policy', 'login', context("203.0.113.$idx", "acct-pproofd-$idx"), emptyDevice(), $lane, allowedResult());
        if ($res->isAllowed()) {
            $pproofdAdmitted++;
        }
    }
}
setTime($clock, 86410);
$lane31 = provenLane('acct-pproofd-31', 'fixed-jti-pproofd', 86410);
$res31 = $rt->reserve('login_policy', 'login', context('203.0.113.31', 'acct-pproofd-31'), emptyDevice(), $lane31, allowedResult());
if ($res31->isAllowed()) {
    $pproofdAdmitted++;
}
echo "  pproofd day burst (cap 30):    admitted = {$pproofdAdmitted} (31st blocked: " . (!$res31->isAllowed() ? 'YES' : 'NO') . ", cause: {$res31->metadata?->cause})\n";

$executor->execute(['FLUSHDB']);

echo "\n=======================================================================\n";
echo "All measurements completed successfully.\n";
echo "=======================================================================\n";
