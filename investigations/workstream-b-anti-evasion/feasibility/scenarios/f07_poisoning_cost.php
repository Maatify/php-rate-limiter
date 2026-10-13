<?php

declare(strict_types=1);

// F07 — what does it COST an insider (or a compromised colleague) sharing the NAT to deny the legitimate cohort through correlation?
// One stable legitimate client succeeds every 5 s for 600 s behind a shared NAT+UA (Login WITHOUT account id, checkOnly + outcome, so that the
// account-keyed K1-spray / K4 / K5 protections cannot mask the correlation effect); an insider fails with a FRESH fingerprint at `rate` failing
// requests per 60 s, evenly spaced. S0 needs no failure at all; the corroboration rule needs `cap` distinct failing members inside one fixed 60 s
// window, so the price of poisoning is a rate, measured here. The no-correlation control separates independent effects from correlation.
require dirname(__DIR__) . '/lib/fsb.php';

emit('F07.provenance', fsbProvenance());
foreach ([1, 2, 3, 4, 6, 12] as $rate) {
    $ev = [];
    for ($t = 5; $t <= 600; $t += 5) {
        $ev[] = ['t' => $t, 'ip' => NAT, 'ua' => 'chrome126', 'fp' => 'fpA', 'acct' => 'acctA', 'outcome' => 'ok', 'kind' => 'legit'];
    }
    $gap = 60 / $rate;
    for ($n = 0; $n * $gap <= 600; $n++) {
        $ev[] = ['t' => (int) round(1 + $n * $gap), 'ip' => NAT, 'ua' => 'chrome126', 'fp' => "ins$n", 'acct' => "oins$n", 'outcome' => 'fail', 'kind' => 'attack'];
    }
    usort($ev, static fn(array $a, array $b): int => [$a['t'], $a['kind']] <=> [$b['t'], $b['kind']]);
    $m = fsbMeasure($ev, 'login-anon', 'pre+out');
    emit('F07.poisoning-cost', ['variant' => wsbVariant(), 'insiderFailuresPer60s' => $rate, 'legitAttempts' => $m['legitAttempts'], 'legitDenied' => $m['legitDenied'], 'legitDeniedByCorrelation' => $m['legitDeniedByCorrelation'], 'controlLegitDenied' => $m['controlLegitDenied'], 'insiderFailuresReachedVerification' => $m['attackReachedVerification'], 'insiderAttempts' => $m['attackAttempts']]);
}
WsbAsserts::finish();
