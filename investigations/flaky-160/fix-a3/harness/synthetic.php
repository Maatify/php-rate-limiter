<?php
// PROVENANCE: copy of investigations/flaky-160/phase3-k4-hand/harness/synthetic.php at 5202bf3, sha256 bf5f0187ac05639fde514f15606103764c5937cfd133986fa2be540e743ef904, ADAPTED: adds the synthetic cases of the a3 classes, V-DECISION and the --filter verdict; the difference is recorded in PROVENANCE-synthetic.php.diff. Delete this line and reverse that diff to get the original byte for byte.

declare(strict_types=1);

// #160 phase 3 detector D3 (PLAN.md §6): synthetic rows through the report's own
// classification functions (k_lib.php); prints one JSON line per case and exits 1
// if any case is not classified as expected.

namespace Flaky160D;

use function Flaky160B\poissonBand;

require __DIR__ . '/k_lib.php';

$x = static fn(int $phase): int => 1_793_000_000_000 + $phase + 1; // phase(x) = $phase
$pair = static fn(array $over): array => $over + ['error' => null, 'read_null' => false, 'applied' => false, 'x' => $x(3), 'held' => null, 'e_r' => 1_793_000_001];
$hand = static fn(array $over): array => $over + ['error' => null, 'read_null' => false, 'winners' => 1, 'x' => $x(3),
    'exits' => array_fill(0, 6, 0), 'workers' => array_fill(0, 6, ['error' => null])];

$cases = [
    ['pair not applied, phase 3', classifyPair($pair([])), 'not_applied_in_window'],
    ['pair not applied, phase 500', classifyPair($pair(['x' => $x(500)])), 'finding_outside_window'],
    ['pair applied', classifyPair($pair(['applied' => true])), 'applied'],
    ['pair not applied, key gone (E-EXP)', classifyPair($pair(['x' => -2])), 'not_applied_expired'],
    ['pair READ null (E-EXP before READ)', classifyPair($pair(['read_null' => true])), 'read_null'],
    ['pair with an exception', classifyPair($pair(['error' => 'RuntimeException: boom'])), 'invalid'],
    ['pair without an error field', classifyPair(array_diff_key($pair([]), ['error' => 1])), 'invalid'],
    ['forced X not as targeted', classifyPair($pair(['x_target' => $x(500) + 1, 'x' => $x(500)])), 'invalid_x'],
    ['held, W = 3, phase 2', classifyPair($pair(['held' => 1000, 'mutate_end_ms' => 1002, 'x' => $x(2)])), 'not_applied_in_window'],
    ['held, W = 3, phase 5', classifyPair($pair(['held' => 1000, 'mutate_end_ms' => 1002, 'x' => $x(5)])), 'finding_outside_window'],
    ['hand one winner', classifyHand($hand([])), 'one_winner'],
    ['hand two winners', classifyHand($hand(['winners' => 2])), 'finding_two_or_more'],
    ['hand zero winners, phase 3', classifyHand($hand(['winners' => 0])), 'zero_in_window'],
    ['hand zero winners, phase 700', classifyHand($hand(['winners' => 0, 'x' => $x(700)])), 'finding_zero_outside_window'],
    ['hand worker exited 1', classifyHand($hand(['exits' => [0, 0, 1, 0, 0, 0]])), 'invalid'],
    ['hand worker error', classifyHand($hand(['workers' => [['error' => 'x'], ...array_fill(0, 5, ['error' => null])]])), 'invalid'],
    ['forced rho=1 e_R = ceil(X/1000) - 2', forcedOneEr(['x' => $x(0), 'e_r' => ceilSeconds($x(0)) - 2]) ? 'ok' : 'rejected', 'rejected'],
    ['forced rho=1 e_R = ceil(X/1000)', forcedOneEr(['x' => $x(0), 'e_r' => ceilSeconds($x(0))]) ? 'ok' : 'rejected', 'ok'],
    ['vclock differing outcomes', vclockOutcome(['fixed_ms' => 5000, 'error' => null, 'applied' => true, 'e_r' => 7, 'e_m' => 7, 'state' => [5, 5, 7, 1]])
        === vclockOutcome(['fixed_ms' => 5000, 'error' => null, 'applied' => false, 'e_r' => 7, 'e_m' => null, 'state' => null]) ? 'identical' : 'different', 'different'],
    ['fisher 0/100 vs 10/100 < 0.01', fisherTwoSided(0, 100, 10, 90) < 0.01 ? 'refuted' : 'not distinguished', 'refuted'],
    ['fisher 3/1000 vs 4/1000', fisherTwoSided(3, 997, 4, 996) < 0.01 ? 'refuted' : 'not distinguished', 'not distinguished'],
    ['poisson band 9.15 = [4, 16] (phase-1 REPORT §3.2)', json_encode(poissonBand(9.15)), '[4,16]'],
    // fix-a3 (PLAN §4.3, §11): the a3 classes, V-DECISION and the --filter verdict
    ['a3 pair applied, READ expiry written', classifyA3Pair($pair(['applied' => true, 'e_m' => 1_793_000_001, 'e_written' => '1793000001'])), 'applied'],
    ['a3 pair applied, e_M differs', classifyA3Pair($pair(['applied' => true, 'e_m' => 1_793_000_002, 'e_written' => '1793000002'])), 'finding_expiry_differs'],
    ['a3 pair applied, stored expiry differs', classifyA3Pair($pair(['applied' => true, 'e_m' => 1_793_000_001, 'e_written' => '1793000002'])), 'finding_expiry_differs'],
    ['a3 pair not applied, phase 3', classifyA3Pair($pair([])), 'finding_not_applied'],
    ['a3 pair not applied, phase 500', classifyA3Pair($pair(['x' => $x(500)])), 'finding_not_applied'],
    ['a3 pair not applied, key gone (E-EXP)', classifyA3Pair($pair(['x' => -2])), 'not_applied_expired'],
    ['a3 pair READ null', classifyA3Pair($pair(['read_null' => true])), 'read_null'],
    ['a3 pair with an exception', classifyA3Pair($pair(['error' => 'RuntimeException: boom'])), 'invalid'],
    ['a3 hand one winner, READ expiry written', classifyA3Hand($hand(['e_r' => 7, 'e_written' => '7'])), 'one_winner'],
    ['a3 hand one winner, stored expiry differs', classifyA3Hand($hand(['e_r' => 7, 'e_written' => '8'])), 'finding_expiry_differs'],
    ['a3 hand zero winners, phase 3', classifyA3Hand($hand(['winners' => 0])), 'finding_zero_winners'],
    ['a3 hand two winners', classifyA3Hand($hand(['winners' => 2])), 'finding_two_or_more'],
    ['decision identical plus S1 passed', decisionVerdict(['T::a' => 'passed', 'T::b' => 'skipped'], ['T::a' => 'passed', 'T::b' => 'skipped', 'T::s with data set "x"' => 'passed'], ['s'])['holds'] ? 'holds' : 'not', 'holds'],
    ['decision one outcome differs', decisionVerdict(['T::a' => 'passed'], ['T::a' => 'failure', 'T::s' => 'passed'], ['s'])['holds'] ? 'holds' : 'not', 'not'],
    ['decision extra test not S1', decisionVerdict(['T::a' => 'passed'], ['T::a' => 'passed', 'T::s' => 'passed', 'T::z' => 'passed'], ['s'])['holds'] ? 'holds' : 'not', 'not'],
    ['decision S1 missing', decisionVerdict(['T::a' => 'passed'], ['T::a' => 'passed'], ['s'])['holds'] ? 'holds' : 'not', 'not'],
    ['decision S1 failed', decisionVerdict(['T::a' => 'passed'], ['T::a' => 'passed', 'T::s' => 'failure'], ['s'])['holds'] ? 'holds' : 'not', 'not'],
    ['decision test missing at head', decisionVerdict(['T::a' => 'passed', 'T::b' => 'passed'], ['T::a' => 'passed', 'T::s' => 'passed'], ['s'])['holds'] ? 'holds' : 'not', 'not'],
    ['decision empty main', decisionVerdict([], ['T::s' => 'passed'], ['s'])['holds'] ? 'holds' : 'not', 'not'],
    ['ufilter 3 passed', json_encode(ufilterVerdict(['tests' => 3, 'failures' => 0, 'errors' => 0, 'skipped' => 0], 0, 3)), '{"valid":true,"passed":true}'],
    ['ufilter 3 with a failure', json_encode(ufilterVerdict(['tests' => 3, 'failures' => 1, 'errors' => 0, 'skipped' => 0], 1, 3)), '{"valid":true,"passed":false}'],
    ['ufilter 0 tests (filter matched nothing)', json_encode(ufilterVerdict(['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0], 0, 3)), '{"valid":false,"passed":false}'],
    ['ufilter 3 skipped (no Redis)', json_encode(ufilterVerdict(['tests' => 3, 'failures' => 0, 'errors' => 0, 'skipped' => 3], 0, 3)), '{"valid":false,"passed":false}'],
    ['ufilter no junit', json_encode(ufilterVerdict(null, 2, 3)), '{"valid":false,"passed":false}'],
];
$failed = 0;
foreach ($cases as [$name, $observed, $expected]) {
    $holds = $observed === $expected;
    $failed += (int) ! $holds;
    echo json_encode(['case' => $name, 'expected' => $expected, 'observed' => $observed, 'holds' => $holds], JSON_UNESCAPED_SLASHES), "\n";
}
exit($failed === 0 ? 0 : 1);
