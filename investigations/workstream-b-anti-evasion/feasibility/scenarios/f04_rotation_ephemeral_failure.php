<?php

declare(strict_types=1);

// F04 — current/previous rotation continuity, ephemeral overflow, backend-failure propagation and custom-store capability on the loaded runtime.
// The window set and the qualification marker are the only new state of PSC: they must follow the same Current/Previous rules as every other
// correlation state and must fail like it (never ALLOW, never a silent skip). IPv6 macro scopes are measured in F01 (Y9a / Y9b).
require dirname(__DIR__) . '/lib/fsb.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;

emit('F04.provenance', fsbProvenance());
$isS0 = wsbVariant() === 'S0';
$isPsc = in_array(wsbVariant(), ['PSC', 'PSCA'], true);
$fpC = static fn(string $d): array => fp($d);

// R1 — three failing members, the pair (A,B) observed under the OLD secret, C after the rotation (new secret + previous = old).
$old = new Lab('login', null, false, 'old-secret', null, false);
$res = [];
foreach (['A', 'B'] as $i => $cl) {
    $old->at($i);
    $res[] = $old->attempt($old->ctx(NAT, CHROME, null, $fpC($cl)), 'out', 'fail')['steps'];
}
$new = new Lab('login', 'old-secret', false, 'new-secret', $old, false);
$new->at(2);
$res[] = $new->attempt($new->ctx(NAT, CHROME, null, $fpC('C')), 'out', 'fail')['steps'];
$rotBlocked = str_contains($res[2], 'H2');
emit('F04.R1.rotation-failing-pair-then-third', ['variant' => wsbVariant(), 'steps' => $res, 'thirdBlocked' => $rotBlocked]);
WsbAsserts::check('F04', 'R1: the failing pair split across a key rotation is still enforced at the third failing member (window-set continuity, no Cartesian pairs)', $rotBlocked, ['steps' => $res]);

// R2 — no corroboration anywhere across the rotation: A and B succeed (old), C succeeds (new). S0 enforces from history.
$old = new Lab('login', null, false, 'old-secret', null, false);
foreach (['A', 'B'] as $i => $cl) {
    $old->at($i);
    $old->attempt($old->ctx(NAT, CHROME, null, $fpC($cl)), 'out', 'ok');
}
$new = new Lab('login', 'old-secret', false, 'new-secret', $old, false);
$new->at(2);
$step = $new->attempt($new->ctx(NAT, CHROME, null, $fpC('C')), 'out', 'ok')['steps'];
$blocked = str_contains($step, 'H2');
emit('F04.R2.rotation-no-evidence', ['variant' => wsbVariant(), 'step' => $step, 'thirdBlocked' => $blocked]);
if ($isPsc) {
    WsbAsserts::check('F04', 'R2: three SUCCESSFUL members across a rotation are not enforced (no corroborating failures in either generation)', ! $blocked, ['step' => $step]);
}

// R3 — only ONE corroborating failure across the rotation: A fails (old), B succeeds (old), C fails (new): window count 2 < cap.
$old = new Lab('login', null, false, 'old-secret', null, false);
$old->at(0);
$old->attempt($old->ctx(NAT, CHROME, null, $fpC('A')), 'out', 'fail');
$old->at(1);
$old->attempt($old->ctx(NAT, CHROME, null, $fpC('B')), 'out', 'ok');
$new = new Lab('login', 'old-secret', false, 'new-secret', $old, false);
$new->at(2);
$step = $new->attempt($new->ctx(NAT, CHROME, null, $fpC('C')), 'out', 'fail')['steps'];
emit('F04.R3.rotation-two-failures-one-success', ['variant' => wsbVariant(), 'step' => $step, 'thirdBlocked' => str_contains($step, 'H2')]);

// R4 — block continuity: a K2 block issued BEFORE the rotation is still enforced after it.
$old = new Lab('login', null, false, 'old-secret', null, false);
foreach (['A', 'B', 'C'] as $i => $cl) {
    $old->at($i);
    $old->attempt($old->ctx(NAT, CHROME, null, $fpC($cl)), 'out', 'fail');
}
$new = new Lab('login', 'old-secret', false, 'new-secret', $old, false);
$new->at(10);
$r = $new->attempt($new->ctx(NAT, CHROME, null, $fpC('A')), 'pre');
emit('F04.R4.block-continuity', ['variant' => wsbVariant(), 'blockedAfterRotation' => $r['blocked'], 'step' => $r['steps']]);
WsbAsserts::check('F04', 'R4: a persisted K2 block survives the rotation', $r['blocked'] === true, ['step' => $r['steps']]);

// E1 — ephemeral overflow: one account that SUCCEEDED from 12 devices (device cap 10 exceeded), then a rotating attacker on a second K2 using that
// account. The bounded K2 churn signal must still fire; no per-fingerprint K3/K5 state is created for overflow.
$lab = new Lab('login');
for ($i = 1; $i <= 12; $i++) {
    $lab->at($i * 20);
    $lab->attempt($lab->ctx("198.51.$i.10", CHROME, 'victimY', fp("dev$i")), 'out', 'ok');
}
$res = [];
for ($j = 1; $j <= 4; $j++) {
    $lab->at(260 + $j * 5);
    $c = $lab->ctx('198.51.99.10', CHROME, 'victimY', fp("eph$j"));
    $r = $lab->attempt($c, 'out', 'fail');
    $res[] = $r['steps'] . ' ' . json_encode($lab->blocks($c));
}
emit('F04.E1.ephemeral-overflow', ['variant' => wsbVariant(), 'attempts' => $res]);
WsbAsserts::check('F04', 'E1: ephemeral overflow requests still feed the bounded K2 churn signal and are enforced at the third failing member', str_contains($res[2], 'H2'), ['attempts' => $res]);

// F1 — backend failure: a correlation store that throws inside a path under test must make process() THROW (never ALLOW, never a silent skip).
final class FsbThrowingCorrelationStore extends CountingCorrelationStore
{
    public string $mode = 'off';
    public bool $armed = false;
    public int $thrown = 0;

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        if ($this->mode === 'marker-write' && $ttlSeconds === 60) { // the qualification marker is the only 60 s WATCH state of PSC
            $this->thrown++;
            throw new BackendFailureException('simulated backend failure');
        }

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }

    public function getWatchFlag(string $key): int
    {
        if ($this->mode === 'marker-read' && $this->armed) {
            $this->thrown++;
            throw new BackendFailureException('simulated backend failure');
        }

        return parent::getWatchFlag($key);
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        if ($this->mode === 'distinct' || ($this->mode === 'window-write' && $ttlSeconds === 60)) { // the 60 s distinct set is the PSC window
            $this->thrown++;
            throw new BackendFailureException('simulated backend failure');
        }

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }
}
foreach (['distinct' => 'F1a.churn-set-write', 'window-write' => 'F1b.window-set-write', 'marker-write' => 'F1c.marker-write', 'marker-read' => 'F1d.marker-read'] as $mode => $name) {
    $labT = new Lab('login');
    $throwing = new FsbThrowingCorrelationStore($labT->clock);
    $labT->corr = $throwing;
    $labT->pipeline = new \Maatify\RateLimiter\Service\EvaluationPipeline(
        $labT->store,
        $throwing,
        new \Maatify\RateLimiter\Service\BudgetTracker($labT->store, $labT->clock),
        new \Maatify\RateLimiter\Service\AntiEquilibriumGate($throwing),
        new \Maatify\RateLimiter\Service\DecayCalculator($labT->clock),
        new \Maatify\RateLimiter\Service\EphemeralBucket($throwing),
        Lab::SECRET,
        'prod',
        $labT->clock,
    );
    $outcome = 'no-exception';
    try {
        if ($mode === 'marker-read') {
            // two failing members first (disarmed), then arm the marker-read failure for the third (trigger + marker present)
            foreach (['A', 'B'] as $i => $cl) {
                $labT->at($i);
                $labT->run($labT->ctx(NAT, CHROME, null, fp($cl)), 'fail');
            }
            $throwing->armed = true;
            $throwing->mode = $mode;
            $labT->at(2);
            $labT->run($labT->ctx(NAT, CHROME, null, fp('C')), 'fail');
        } elseif ($mode === 'marker-write') {
            foreach (['A', 'B'] as $i => $cl) {
                $labT->at($i);
                $labT->run($labT->ctx(NAT, CHROME, null, fp($cl)), 'fail');
            }
            $throwing->mode = $mode;
            $labT->at(2);
            $labT->run($labT->ctx(NAT, CHROME, null, fp('C')), 'fail'); // third member reaches the window cap -> marker write throws
        } else {
            $throwing->mode = $mode;
            $labT->at(1);
            $labT->run($labT->ctx(NAT, CHROME, null, fp('A')), 'fail');
        }
    } catch (BackendFailureException) {
        $outcome = 'BackendFailureException';
    }
    $applicable = $mode === 'distinct' || $isPsc;
    emit('F04.' . $name, ['variant' => wsbVariant(), 'applicable' => $applicable, 'result' => $outcome, 'storeThrows' => $throwing->thrown]);
    if ($applicable) {
        WsbAsserts::check('F04', "$name: a backend failure on this path propagates (never ALLOW / never silent skip)", $outcome === 'BackendFailureException', ['result' => $outcome]);
    }
}

// C1 — custom-store capability. PSC must run on exactly the capabilities the baseline already requires of a store for a non-distributed
// policy (API Heavy: BoundedCorrelationRotationStoreInterface + CorrelationRotationStoreInterface), without the snapshot capability and
// without any new method. The wrapper exposes ONLY those interfaces and delegates to the reference store.
final class FsbMinimalCustomStore implements BoundedCorrelationRotationStoreInterface, CorrelationRotationStoreInterface
{
    public function __construct(private readonly CountingCorrelationStore $inner)
    {
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return $this->inner->addDistinct($key, $item, $ttlSeconds);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return $this->inner->incrementWatchFlag($key, $ttlSeconds);
    }

    public function getWatchFlag(string $key): int
    {
        return $this->inner->getWatchFlag($key);
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        return $this->inner->addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }

    public function addDistinctAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds): int
    {
        return $this->inner->addDistinctAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds);
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        return $this->inner->addDistinctBoundedAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        return $this->inner->incrementWatchFlagAcrossRotation($currentKey, $previousKey, $ttlSeconds);
    }
}
$labC = new Lab('api');
$minimal = new FsbMinimalCustomStore($labC->corr);
$capabilityNames = array_map(static fn(string $i): string => (new \ReflectionClass($i))->getShortName(), array_keys(class_implements($minimal)));
sort($capabilityNames);
$apiPipeline = new \Maatify\RateLimiter\Service\EvaluationPipeline(
    $labC->store,
    $minimal,
    new \Maatify\RateLimiter\Service\BudgetTracker($labC->store, $labC->clock),
    new \Maatify\RateLimiter\Service\AntiEquilibriumGate($minimal),
    new \Maatify\RateLimiter\Service\DecayCalculator($labC->clock),
    new \Maatify\RateLimiter\Service\EphemeralBucket($minimal),
    Lab::SECRET,
    'prod',
    $labC->clock,
);
$policy = new ApiHeavyProtectionPolicy();
$steps = [];
$exception = null;
try {
    foreach (['A', 'B', 'C'] as $i => $cl) {
        $labC->at($i);
        $c = $labC->ctx(NAT, CHROME, null, fp($cl));
        $r = $apiPipeline->process($policy, $c, RateLimitCommand::recordSuccess($policy->getName()), $labC->resolver->resolve($c));
        $steps[] = $r->decision === 'ALLOW' ? 'A' : 'H' . $r->blockLevel;
    }
} catch (\Throwable $t) {
    $exception = $t::class . ': ' . $t->getMessage();
}
emit('F04.C1.custom-store-minimal-capabilities', ['variant' => wsbVariant(), 'storeInterfaces' => $capabilityNames, 'steps' => $steps, 'exception' => $exception, 'snapshotCapabilityPresent' => $minimal instanceof \Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotStoreInterface]);
WsbAsserts::check('F04', 'C1: the variant runs on a custom store that exposes ONLY the baseline non-distributed capabilities (no snapshot, no new method) without error', $exception === null && count($steps) === 3, ['exception' => $exception]);
WsbAsserts::finish();
