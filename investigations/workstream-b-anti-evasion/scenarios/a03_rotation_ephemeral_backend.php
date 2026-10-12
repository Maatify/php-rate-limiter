<?php

declare(strict_types=1);

// A03 — current/previous rotation continuity, ephemeral overflow, backend failure propagation, on the loaded runtime.
require dirname(__DIR__) . '/lib/wsb.php';

use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;

emit('A03.provenance', wsbProvenance());

// R1 — rotation: attacker fails with fingerprints A,B (and a third C after the rotation) on ONE K2.
//   none      : no rotation (control)
//   rotated   : A,B observed under the OLD secret; C observed after rotation (new secret + previous = old; fingerprints re-hash too)
$fpC = static fn(string $d): array => fp($d);
$lab = new Lab('login');
$res = [];
foreach (['A', 'B', 'C'] as $i => $cl) {
    $lab->at($i);
    $res[] = $lab->attempt($lab->ctx(NAT, CHROME, null, $fpC($cl)), 'out', 'fail')['steps'];
}
emit('A03.R1.rotation', ['variant' => wsbVariant(), 'shape' => 'none', 'steps' => $res, 'thirdBlocked' => str_contains($res[2], 'H2')]);
WsbAsserts::check('A03', 'R1: control without rotation blocks at the third failing member only if the variant accepts 3 failing members as evidence', str_contains($res[2], 'H2'), ['steps' => $res]);

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
emit('A03.R1.rotation', ['variant' => wsbVariant(), 'shape' => 'rotated(old A,B -> new C)', 'steps' => $res, 'thirdBlocked' => $rotBlocked]);
// MUST-PRESERVE (DECISION_MATRIX §5.0: previous state must not become a current-only reset): the pair split across rotation must still block.
WsbAsserts::check('A03', 'R1: current/previous rotation keeps the evidence/count continuous (third member blocked across the rotation)', $rotBlocked, ['steps' => $res]);

// R2 — block continuity: a K2 block issued BEFORE the rotation must still be enforced after it.
$old = new Lab('login', null, false, 'old-secret', null, false);
$old->at(0);
foreach (['A', 'B', 'C'] as $i => $cl) {
    $old->at($i);
    $old->attempt($old->ctx(NAT, CHROME, null, $fpC($cl)), 'out', 'fail');
}
$new = new Lab('login', 'old-secret', false, 'new-secret', $old, false);
$new->at(10);
$r = $new->attempt($new->ctx(NAT, CHROME, null, $fpC('A')), 'pre');
emit('A03.R2.block-continuity', ['variant' => wsbVariant(), 'blockedAfterRotation' => $r['blocked'], 'step' => $r['steps']]);

// E1 — ephemeral overflow: one account that SUCCEEDED from 12 devices (device cap 10 exceeded, K4 clean), then a rotating attacker on a second K2 using that
// account. Correlation churn must still fire (bounded K2 signal) while no per-fingerprint (K3/K5) state is created for overflow.
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
emit('A03.E1.ephemeral-overflow', ['variant' => wsbVariant(), 'attempts' => $res]);

// F1 — backend failure: a correlation store that throws inside the path under test must make process() THROW (never ALLOW,
// never a silent skip). F1a: the shared churn/dilution set write (every variant). F1b: the candidate-only witness write.
final class ThrowingCorrelationStore extends CountingCorrelationStore
{
    public string $mode = 'off';
    public int $thrown = 0;

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        if ($this->mode === 'witness' && $ttlSeconds === 660) { // candidate-only freshness / ratio flag TTL
            $this->thrown++;
            throw new BackendFailureException('simulated backend failure');
        }

        return parent::incrementWatchFlag($key, $ttlSeconds);
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        if ($this->mode === 'distinct') {
            $this->thrown++;
            throw new BackendFailureException('simulated backend failure');
        }

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }
}
foreach (['distinct' => 'F1a.churn-set-write', 'witness' => 'F1b.witness-write'] as $mode => $name) {
    $labT = new Lab('login');
    $throwing = new ThrowingCorrelationStore($labT->clock);
    // Lab is final; its stores / pipeline are public properties, so rebuild the pipeline over the throwing correlation store.
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
    $throwing->mode = $mode;
    $outcome = 'no-exception';
    try {
        $labT->at(1);
        $c = $labT->ctx(NAT, CHROME, null, fp('A'));
        $labT->run($c, 'fail');
    } catch (BackendFailureException) {
        $outcome = 'BackendFailureException';
    }
    $applicable = $mode === 'distinct' || in_array(wsbVariant(), ['PW', 'P2', 'RL'], true);
    emit('A03.' . $name, ['variant' => wsbVariant(), 'applicable' => $applicable, 'result' => $outcome, 'storeThrows' => $throwing->thrown]);
    if ($applicable) {
        WsbAsserts::check('A03', "$name: a backend failure on this path propagates (never ALLOW / never silent skip)", $outcome === 'BackendFailureException', ['result' => $outcome]);
    }
}

WsbAsserts::finish();
