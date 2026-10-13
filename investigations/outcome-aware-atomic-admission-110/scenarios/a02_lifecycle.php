<?php

declare(strict_types=1);

// A02 — the reservation lifecycle, probe by probe, on the loaded arm (reference stores, controlled clock, Login unless stated).
// Every probe emits its decision string ("a" allowed, "B" denied; one letter per command) on BOTH arms so report.php can compare the arms;
// the OAAA arm additionally reads the reference store's tiers / tuples and asserts the contract of ARCHITECTURE.md §2.
//   L1  pure attacker (no Host-verified success in the scope): OAAA grants nothing (must equal S0)
//   L2  credit gating: 1 then 2 distinct successful accounts lend exactly 1 then 2 units
//   L3  pre-only reservations are never returned; credits expire with their window
//   L4  successes return their unit: 50 credited legitimate attempts in a saturated scope, units stay 0
//   L5  same-tuple refund: MEDIUM typo + success returns both units; LOW returns only the success's own unit
//   L6  cross-account substitution: a success on an own account never returns a unit taken for a victim
//   L7  replayed / duplicate success: returns at most 2 recorded failures of the same tuple, never more, never negative
//   L8  duplicate failure report: no second unit
//   L9  out-of-order outcomes (LOW tuple): both orders give the same totals
//   L10 late completion (tuple expired): nothing is returned
//   L11 completion after an epoch rollover: never credited into the new epoch
//   L12 day tier: at most 6 units per scope per day although every epoch has room
//   L13 global tier: at most 12 units per 600 s over 7 credited scopes
//   L14 own-account credit cap: 5 own accounts still lend at most 2 units per epoch
//   L15 trusted-session advisory spray (must equal S0)
//   L16 a persisted S0 block is never bypassed (must equal S0 while it is active)
//   L17 API_OVERUSE: no OAAA store call, decisions equal S0
//   L18 Login without account: no OAAA store call, decisions equal S0
//   L19 OAAA tree with a store WITHOUT the capability: decisions equal S0, no exception
//   L20 a granted reservation whose request another protection denies (§5.2) is cancelled at once
//   L21 all-or-nothing: K1 has credit, K2 has none -> refused and K1 keeps 0 units (contrast #115 G06 C4)
//   L22 pre+out pairing: churn fires on both commands, exactly one unit is spent
//   L23 record-only failure: takes a unit (unpaired), refused once the scope is spent
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

emit('A02.provenance', oaaaProvenance());
$arm = oaaaArm();
$cand = $arm === 'OAAA';

$ctx = static fn(string $ip, ?string $acct, ?string $fp = null, int $ua = 126, bool $trusted = false): RateLimitContextDTO => new RateLimitContextDTO($ip, k1Ua($ua - 100), $acct, $fp === null ? null : fp($fp), $trusted ? 'sess-' . $acct : null, $trusted);
/** one command, returns 'a' (ALLOW) or 'B' */
$cmd = static fn(Lab $lab, RateLimitContextDTO $c, string $what): string => $lab->run($c, $what)['d'] === 'A' ? 'a' : 'B';
/** Host-realistic attempt: checkOnly, then the outcome only if allowed. Returns 2 letters ("aa", "aB", "B-"). */
$attempt = static function (Lab $lab, RateLimitContextDTO $c, string $outcome) use ($cmd): string {
    $p = $cmd($lab, $c, 'check');
    if ($p === 'B') {
        return 'B-';
    }

    return $p . $cmd($lab, $c, $outcome);
};
$k = static function (Lab $lab, RateLimitContextDTO $c): array {
    $keys = $lab->keys($c);
    $churn = hash_hmac('sha256', $lab->policy->getName() . ':rate_limiter:correlation:churn:v1:prod:scope:' . $keys['K2'], $lab->secret);

    return [
        'spray' => 'oaaa:v1:e:spray:credential_spray:' . $keys['K1'],
        'sprayDay' => 'oaaa:v1:d:spray:credential_spray:' . $keys['K1'],
        'churn' => 'oaaa:v1:e:churn:' . $churn,
        'globalE' => 'oaaa:v1:g:e:' . hash('sha256', $lab->policy->getName() . ':prod'),
        'globalD' => 'oaaa:v1:g:d:' . hash('sha256', $lab->policy->getName() . ':prod'),
    ];
};
/** units in use on a tier (0 on S0 / absent / expired) */
$u = static function (Lab $lab, string $tier): int {
    $s = $lab->corr;
    if (! $s instanceof OaaaStore) {
        return 0;
    }
    $t = $s->tiers[$tier] ?? null;

    return $t === null || $t['exp'] <= $lab->clock->now()->getTimestamp() ? 0 : $t['u'];
};
$log = static fn(Lab $lab): int => $lab->corr instanceof OaaaStore ? count($lab->corr->oaaaLog) : 0;
/** saturate the K1 spray scope of $ip: subjects s0..s4, each a successful pre+out (S0 denies the 5th pre-check; on OAAA the 5th is a credited
 * reservation returned by its success, so the probe starts with 0 units in use) */
$saturate = static function (Lab $lab, string $ip, string $tag, int $successes = 5) use ($ctx, $attempt): string {
    $seq = '';
    for ($i = 0; $i < 5; $i++) {
        $seq .= $attempt($lab, $ctx($ip, "{$tag}s$i"), $i < $successes ? 'ok' : 'fail');
    }

    return $seq;
};

// L1 — pure attacker: 20 failing victims on a fresh IP, no success ever.
$lab = oaaaLab();
$seq = '';
for ($i = 0; $i < 20; $i++) {
    $lab->at($i * 3);
    $seq .= $attempt($lab, $ctx('10.2.1.1', "l1v$i"), 'fail');
}
emit('A02.L1.pure-attacker', ['arm' => $arm, 'seq' => $seq, 'granted' => $lab->corr instanceof OaaaStore ? count(array_filter($lab->corr->oaaaLog, static fn(array $r): bool => $r['op'] === 'reserve' && $r['granted'])) : 0]);
OaaaAsserts::check('A02', 'L1 a pure attacker (no verified success in its scope) is admitted for the first 4 victims only and nothing is ever granted', $seq === str_repeat('aa', 4) . str_repeat('B-', 16), ['seq' => $seq]);

// L2 — credit gating.
$lab = oaaaLab();
$seq = $attempt($lab, $ctx('10.2.2.1', 'l2own0'), 'ok');           // 1 verified success (credit 1)
for ($i = 0; $i < 4; $i++) {
    $seq .= $attempt($lab, $ctx('10.2.2.1', "l2v$i"), 'fail');      // subjects 2..5: the 5th distinct subject fires
}
$afterOne = $seq;
$seq .= $attempt($lab, $ctx('10.2.2.1', 'l2v4'), 'fail');           // allowance 1 already used -> refused (S0 block 60 s)
$lab->at(61);
$seq .= $attempt($lab, $ctx('10.2.2.1', 'l2own1'), 'ok');           // a 2nd account would succeed, but the scope is exhausted -> refused
$k2 = $k($lab, $ctx('10.2.2.1', 'x'));
emit('A02.L2.credit-gating', ['arm' => $arm, 'seq' => $seq, 'sprayUnits' => $u($lab, $k2['spray'])]);
OaaaAsserts::check('A02', 'L2 ' . ($cand ? 'one verified success lends exactly one unit: the 5th distinct subject is admitted, the 6th and a later credited attempt are refused while the unit is spent' : 'S0: the 5th distinct subject and every later attempt in the 600 s window are denied'), $cand
    ? $afterOne === 'aaaaaaaaaa' && $seq === 'aaaaaaaaaaB-B-' && $u($lab, $k2['spray']) === 1
    : $seq === 'aaaaaaaaB-B-B-', ['seq' => $seq]);

// L3 — pre-only reservations are never returned; credits expire.
$lab = oaaaLab();
$sat = $saturate($lab, '10.2.3.1', 'l3');
$k3 = $k($lab, $ctx('10.2.3.1', 'x'));
$seq = $cmd($lab, $ctx('10.2.3.1', 'l3p0'), 'check') . $cmd($lab, $ctx('10.2.3.1', 'l3p1'), 'check') . $cmd($lab, $ctx('10.2.3.1', 'l3p2'), 'check');
$unitsAfter = $u($lab, $k3['spray']);
$lab->at(1300);                                                     // epoch, block and both credit windows over
$late = $cmd($lab, $ctx('10.2.3.1', 'l3p3'), 'check');
emit('A02.L3.pre-only', ['arm' => $arm, 'saturation' => $sat, 'seq' => $seq, 'unitsAfterThreePreChecks' => $unitsAfter, 'after1300' => $late]);
OaaaAsserts::check('A02', 'L3 ' . ($cand ? 'two pre-only reservations are granted and never returned (2 units stay spent), the third is refused' : 'S0: the 5th subject and every later pre-check are denied'), $cand
    ? $sat === 'aaaaaaaaaa' && $seq === 'aaB' && $unitsAfter === 2
    : $sat === 'aaaaaaaaB-' && $seq === 'BBB', ['saturation' => $sat, 'seq' => $seq, 'units' => $unitsAfter, 'late' => $late]);

// L4 — 50 credited legitimate attempts (2 colleagues alternating, success) in the saturated scope: no unit is ever held after an outcome.
$lab = oaaaLab();
$saturate($lab, '10.2.4.1', 'l4');
$k4 = $k($lab, $ctx('10.2.4.1', 'x'));
$seq = '';
$maxUnits = 0;
for ($i = 0; $i < 50; $i++) {
    $lab->at(10 + $i * 5);
    $seq .= $attempt($lab, $ctx('10.2.4.1', 'l4s' . ($i % 2)), 'ok');
    $maxUnits = max($maxUnits, $u($lab, $k4['spray']));
}
emit('A02.L4.success-returns', ['arm' => $arm, 'admitted' => substr_count($seq, 'aa'), 'maxUnitsAfterOutcome' => $maxUnits]);
OaaaAsserts::check('A02', 'L4 ' . ($cand ? 'all 50 credited successful attempts are admitted and every unit is returned (0 units held after each outcome)' : 'S0: none of the 50 attempts is admitted (persisted K1 block / stale set)'), $cand ? $seq === str_repeat('aa', 50) && $maxUnits === 0 : substr_count($seq, 'aa') === 0, ['admitted' => substr_count($seq, 'aa'), 'maxUnits' => $maxUnits]);

// L5 — same-tuple refund: MEDIUM typo then success; LOW typo then success.
$r5 = [];
foreach (['MEDIUM' => 'l5fp', 'LOW' => null] as $conf => $fpv) {
    $lab = oaaaLab();
    $saturate($lab, '10.2.5.1', 'l5');
    $kk = $k($lab, $ctx('10.2.5.1', 'x'));
    $c = $ctx('10.2.5.1', 'l5col', $fpv);
    $seq = $attempt($lab, $c, 'fail');
    $mid = $u($lab, $kk['spray']);
    $seq .= $attempt($lab, $c, 'ok');
    $r5[$conf] = ['seq' => $seq, 'unitsAfterTypo' => $mid, 'unitsAfterSuccess' => $u($lab, $kk['spray'])];
}
emit('A02.L5.same-tuple-refund', ['arm' => $arm] + $r5);
OaaaAsserts::check('A02', 'L5 ' . ($cand ? 'MEDIUM: the typo holds 1 unit and the same tuple\'s success returns both (0); LOW: the success returns only its own unit (1 stays spent)' : 'S0: the typo and the retry are both denied'), $cand
    ? $r5['MEDIUM'] === ['seq' => 'aaaa', 'unitsAfterTypo' => 1, 'unitsAfterSuccess' => 0] && $r5['LOW'] === ['seq' => 'aaaa', 'unitsAfterTypo' => 1, 'unitsAfterSuccess' => 1]
    : $r5['MEDIUM']['seq'] === 'B-B-' && $r5['LOW']['seq'] === 'B-B-', $r5);

// L6 — cross-account substitution.
$lab = oaaaLab();
$saturate($lab, '10.2.6.1', 'l6');
$k6 = $k($lab, $ctx('10.2.6.1', 'x'));
$seq = $attempt($lab, $ctx('10.2.6.1', 'l6victim', 'atkdev'), 'fail');
$seq .= $attempt($lab, $ctx('10.2.6.1', 'l6own', 'atkdev'), 'ok');
emit('A02.L6.cross-account', ['arm' => $arm, 'seq' => $seq, 'unitsAfter' => $u($lab, $k6['spray'])]);
OaaaAsserts::check('A02', 'L6 ' . ($cand ? 'a success on the attacker\'s own account (same device, IP, UA) returns its own unit only: the victim attempt\'s unit stays spent' : 'S0: both attempts denied'), $cand ? $seq === 'aaaa' && $u($lab, $k6['spray']) === 1 : $seq === 'B-B-', ['seq' => $seq, 'units' => $u($lab, $k6['spray'])]);

// L7 — replayed / duplicate success on the same tuple (Host bug). The probe device uses its own UA (own K2) so only the K1 spray scope is
// involved. Note: an outcome command that meets a PERSISTED block returns before OAAA (S0 ordering), so a replay is resolved only when no
// block is active — which is the case constructed here.
$lab = oaaaLab();
$saturate($lab, '10.2.7.1', 'l7');
$k7 = $k($lab, $ctx('10.2.7.1', 'x'));
$c = $ctx('10.2.7.1', 'l7col', 'l7fp', 127);
$units = [];
$seq = $attempt($lab, $c, 'fail');
$units[] = $u($lab, $k7['spray']);
$seq .= $attempt($lab, $c, 'ok');
$units[] = $u($lab, $k7['spray']);
$seq .= $cmd($lab, $c, 'ok');                                         // replayed success: nothing unresolved, no failure recorded
$units[] = $u($lab, $k7['spray']);
$lab->at(30);
$seq .= $attempt($lab, $c, 'fail');
$units[] = $u($lab, $k7['spray']);
$seq .= $cmd($lab, $c, 'ok');                                         // replayed success: returns the 1 recorded failure of the same tuple
$units[] = $u($lab, $k7['spray']);
$seq .= $cmd($lab, $c, 'ok');                                         // again: nothing left
$units[] = $u($lab, $k7['spray']);
emit('A02.L7.replayed-success', ['arm' => $arm, 'seq' => $seq, 'unitsAfterEachStep' => $units]);
OaaaAsserts::check('A02', 'L7 ' . ($cand ? 'a replayed success returns nothing when nothing of its tuple is outstanding, returns at most the recorded failures of the SAME tuple (here 1), and a further replay returns nothing; units are never negative' : 'S0: every attempt denied'), $cand
    ? $units === [1, 0, 0, 1, 0, 0]
    : str_starts_with($seq, 'B-'), ['seq' => $seq, 'units' => $units]);

// L8 — duplicate failure report (Host bug).
$lab = oaaaLab();
$saturate($lab, '10.2.8.1', 'l8');
$k8 = $k($lab, $ctx('10.2.8.1', 'x'));
$c = $ctx('10.2.8.1', 'l8v', 'l8fp');
$seq = $attempt($lab, $c, 'fail') . $cmd($lab, $c, 'fail');
emit('A02.L8.duplicate-failure', ['arm' => $arm, 'seq' => $seq, 'unitsAfter' => $u($lab, $k8['spray'])]);
OaaaAsserts::check('A02', 'L8 ' . ($cand ? 'a duplicated failure report spends no second unit' : 'S0: the attempt and the duplicate report are denied'), $cand ? $u($lab, $k8['spray']) === 1 : $seq === 'B-B', ['seq' => $seq, 'units' => $u($lab, $k8['spray'])]);

// L9 — out-of-order outcomes of two concurrent attempts of one LOW tuple.
$r9 = [];
foreach (['success-first' => ['ok', 'fail'], 'failure-first' => ['fail', 'ok']] as $order => $outs) {
    $lab = oaaaLab();
    $saturate($lab, '10.2.9.1', 'l9');
    $kk = $k($lab, $ctx('10.2.9.1', 'x'));
    $c = $ctx('10.2.9.1', 'l9col');
    $pre = $cmd($lab, $c, 'check') . $cmd($lab, $c, 'check');
    $out = $cmd($lab, $c, $outs[0]) . $cmd($lab, $c, $outs[1]);
    $r9[$order] = ['pre' => $pre, 'units' => $u($lab, $kk['spray'])];
}
emit('A02.L9.out-of-order', ['arm' => $arm] + $r9);
OaaaAsserts::check('A02', 'L9 ' . ($cand ? 'two in-flight attempts of one LOW tuple, one success and one failure, leave exactly 1 unit spent in either order' : 'S0: both pre-checks denied'), $cand
    ? $r9['success-first'] === ['pre' => 'aa', 'units' => 1] && $r9['failure-first'] === ['pre' => 'aa', 'units' => 1]
    : $r9['success-first']['pre'] === 'BB', $r9);

// L10 — late completion: the outcome arrives after the tuple expired.
$lab = oaaaLab();
$saturate($lab, '10.2.10.1', 'l10');
$k10 = $k($lab, $ctx('10.2.10.1', 'x'));
$c = $ctx('10.2.10.1', 'l10col', 'l10fp');
$lab->at(100);
$pre = $cmd($lab, $c, 'check');
$dayBefore = $u($lab, $k10['sprayDay']);
$lab->at(701);
$late = $cmd($lab, $c, 'ok');
emit('A02.L10.late-completion', ['arm' => $arm, 'pre' => $pre, 'dayUnitsBefore' => $dayBefore, 'dayUnitsAfterLateSuccess' => $u($lab, $k10['sprayDay'])]);
OaaaAsserts::check('A02', 'L10 ' . ($cand ? 'a success that arrives after the 600 s tuple expired returns nothing: the day tier keeps the unit' : 'S0: the pre-check is denied'), $cand ? $pre === 'a' && $dayBefore === 1 && $u($lab, $k10['sprayDay']) === 1 : $pre === 'B', ['pre' => $pre, 'before' => $dayBefore, 'after' => $u($lab, $k10['sprayDay'])]);

// L11 — completion after an epoch rollover is never credited into the new epoch.
$lab = oaaaLab();
$saturate($lab, '10.2.11.1', 'l11');
$k11 = $k($lab, $ctx('10.2.11.1', 'x'));
$lab->at(300);
$first = $cmd($lab, $ctx('10.2.11.1', 'l11a', 'fa'), 'check');              // epoch E1 (created at t=0 by the saturation), tuple A created at t=300 (alive until 900)
$lab->at(560);
$keep = $attempt($lab, $ctx('10.2.11.1', 'l11k0'), 'ok') . $attempt($lab, $ctx('10.2.11.1', 'l11k1'), 'ok'); // keep credits alive
$lab->at(606);                                                                // E1 and the 600 s spray set expired at t=600; tuple A still alive
$keep .= $saturate($lab, '10.2.11.1', 'l11r');                                // a new spray window: the 5th subject creates epoch E2 (returned by its success)
$second = $cmd($lab, $ctx('10.2.11.1', 'l11b', 'fb'), 'check');              // 1 unit in E2
$e2Before = $u($lab, $k11['spray']);
$lateA = $cmd($lab, $ctx('10.2.11.1', 'l11a', 'fa'), 'ok');                  // A's success after its epoch rolled over
emit('A02.L11.epoch-rollover', ['arm' => $arm, 'first' => $first, 'second' => $second, 'keepCredits' => $keep, 'e2UnitsBefore' => $e2Before, 'e2UnitsAfterOldSuccess' => $u($lab, $k11['spray'])]);
OaaaAsserts::check('A02', 'L11 ' . ($cand ? 'a success for a reservation taken in an earlier epoch is never credited into the new epoch (E2 keeps its 1 unit)' : 'S0: both pre-checks denied'), $cand ? $first === 'a' && $second === 'a' && $e2Before === 1 && $u($lab, $k11['spray']) === 1 : $first === 'B' && $second === 'B', ['first' => $first, 'second' => $second, 'before' => $e2Before, 'after' => $u($lab, $k11['spray'])]);

// L12 — day tier: one failing attempt per 300 s for 1 h on a credited scope (epoch room every time): only 6 admitted in the day.
$lab = oaaaLab();
$seq = '';
for ($i = 0; $i < 12; $i++) {
    $t = $i * 301;
    $lab->at($t);
    $attempt($lab, $ctx('10.2.12.1', 'l12own' . ($i % 2)), 'ok');          // keep 2 credits alive
    for ($j = 0; $j < 4; $j++) {
        $attempt($lab, $ctx('10.2.12.1', "l12x{$i}_$j", "x$i$j"), 'ok');   // keep the spray set saturated with successes
    }
    $lab->at($t + 70);
    $seq .= $attempt($lab, $ctx('10.2.12.1', "l12v$i", "v$i"), 'fail');
}
$k12 = $k($lab, $ctx('10.2.12.1', 'x'));
emit('A02.L12.day-tier', ['arm' => $arm, 'seq' => $seq, 'admittedFailing' => substr_count($seq, 'aa'), 'dayUnits' => $u($lab, $k12['sprayDay'])]);
OaaaAsserts::check('A02', 'L12 ' . ($cand ? 'the scope day tier admits exactly 6 failing attempts in the day although every epoch still has credit and room' : 'S0 reference sequence recorded'), $cand ? substr_count($seq, 'aa') === 6 && $u($lab, $k12['sprayDay']) === 6 : substr_count($seq, 'aa') === 0, ['seq' => $seq, 'dayUnits' => $u($lab, $k12['sprayDay'])]);

// L13 — global tier: 7 credited scopes, each with its 2 epoch units, 14 failing attempts inside 600 s: at most 12 admitted.
$lab = oaaaLab();
$admitted = 0;
for ($ip = 0; $ip < 7; $ip++) {
    $saturate($lab, "10.2.13.$ip", "l13i$ip");
}
for ($ip = 0; $ip < 7; $ip++) {
    for ($j = 0; $j < 2; $j++) {
        // a distinct MEDIUM device with its own browser major per attempt: its K2 and dilution sets hold one member, so only K1 spray fires
        $admitted += $attempt($lab, $ctx("10.2.13.$ip", "l13v{$ip}_$j", "g{$ip}_$j", 160 + 2 * $ip + $j), 'fail') === 'aa' ? 1 : 0;
    }
}
$k13 = $k($lab, $ctx('10.2.13.0', 'x'));
emit('A02.L13.global-tier', ['arm' => $arm, 'scopes' => 7, 'attempts' => 14, 'admitted' => $admitted, 'globalEpochUnits' => $u($lab, $k13['globalE'])]);
OaaaAsserts::check('A02', 'L13 ' . ($cand ? 'over 7 credited scopes (14 units of scope room) the global tier admits exactly 12 failing attempts in the epoch' : 'S0 reference: 6 of the 14 failing attempts are admitted (deterministic S0 value)'), $cand ? $admitted === 12 && $u($lab, $k13['globalE']) === 12 : $admitted === 6, ['admitted' => $admitted, 'global' => $u($lab, $k13['globalE'])]);

// L14 — own-account credit cap: the attacker logs into 5 own accounts, then fails on fresh victims.
$lab = oaaaLab();
$own = '';
for ($i = 0; $i < 5; $i++) {
    $own .= $attempt($lab, $ctx('10.2.14.1', "l14own$i"), 'ok');
}
$seq = '';
for ($i = 0; $i < 6; $i++) {
    $seq .= $attempt($lab, $ctx('10.2.14.1', "l14v$i"), 'fail');
}
emit('A02.L14.own-credit-cap', ['arm' => $arm, 'own' => $own, 'seq' => $seq, 'admittedFailing' => substr_count($seq, 'aa')]);
OaaaAsserts::check('A02', 'L14 ' . ($cand ? '5 successful own accounts lend at most 2 units: exactly 2 failing victim attempts are admitted beyond S0' : 'S0: the own logins fill the spray set; no victim attempt is admitted'), $cand ? substr_count($seq, 'aa') === 2 : substr_count($seq, 'aa') === 0, ['own' => $own, 'seq' => $seq]);

// L15 — trusted-session advisory spray (SP5-like on a fresh IP) — must equal S0.
$lab = oaaaLab();
$seq = '';
for ($i = 0; $i < 12; $i++) {
    $lab->at($i * 3);
    $seq .= $attempt($lab, $ctx('10.2.15.1', "l15v$i", 'stolen', 126, true), 'fail');
}
$seq .= $attempt($lab, $ctx('10.2.15.1', 'l15untrusted'), 'ok');
emit('A02.L15.trusted-advisory', ['arm' => $arm, 'seq' => $seq, 'oaaaCalls' => $log($lab)]);

// L16 — a persisted S0 block is never bypassed: saturate WITHOUT credit (S0 block persisted), then a credited colleague tries during the block.
$lab = oaaaLab();
$seq = $saturate($lab, '10.2.16.1', 'l16', 0);
$lab->at(20);
$seq .= $attempt($lab, $ctx('10.2.16.1', 'l16s0'), 'ok');
emit('A02.L16.persisted-block', ['arm' => $arm, 'seq' => $seq]);

// L17 / L18 — API_OVERUSE and Login without account: no OAAA call at all (sequences compared with S0 in report.php).
$lab = oaaaLab('api');
$seq = '';
for ($i = 0; $i < 30; $i++) {
    $lab->at($i * 2);
    $seq .= $attempt($lab, $ctx('10.2.17.1', null, "api$i"), 'ok');
}
$apiCalls = $log($lab);
emit('A02.L17.api-overuse', ['arm' => $arm, 'seq' => $seq, 'oaaaCalls' => $apiCalls]);
$lab = oaaaLab();
$seq = '';
for ($i = 0; $i < 20; $i++) {
    $lab->at($i * 2);
    $seq .= $attempt($lab, $ctx('10.2.18.1', null, "anon$i"), $i % 3 === 0 ? 'fail' : 'ok');
}
$anonCalls = $log($lab);
emit('A02.L18.login-anon', ['arm' => $arm, 'seq' => $seq, 'oaaaCalls' => $anonCalls]);
OaaaAsserts::check('A02', 'L17/L18 API_OVERUSE and account-less Login make no OAAA store call', $apiCalls === 0 && $anonCalls === 0, ['api' => $apiCalls, 'anon' => $anonCalls]);

// L19 — the OAAA tree over a store WITHOUT the capability (custom store compatibility): the D2 / ABA_B / SP1n / OWN1 probes.
$streams = oaaaStreams();
$r19 = [];
foreach (['D2', 'ABA_B', 'SP1n', 'OWN1'] as $id) {
    $a = oaaaMeasure($streams[$id]['events'], 'login', 'pre+out', false, false);
    $r19[$id] = $a['legitDeniedBits'] . '|' . $a['attackReachedBits'];
}
emit('A02.L19.no-capability', ['arm' => $arm, 'bits' => array_map('md5', $r19)]);

// L20 — cancel: a granted reservation whose request §5.2 distributed-account denies.
$lab = oaaaLab();
$saturate($lab, '10.2.20.1', 'l20');
$k20 = $k($lab, $ctx('10.2.20.1', 'x'));
$seq = '';
foreach (['d0', 'd1', 'd2'] as $i => $dev) {
    $seq .= $attempt($lab, $ctx("10.2.21.$i", 'l20acct', $dev), 'fail');   // the account seen on 3 devices elsewhere
}
$seq .= $attempt($lab, $ctx('10.2.20.1', 'l20acct', 'd3'), 'fail');        // 4th device, in the saturated spray scope
$cancels = $lab->corr instanceof OaaaStore ? count(array_filter($lab->corr->oaaaLog, static fn(array $r): bool => $r['op'] === 'complete' && $r['outcome'] === 'cancel')) : 0;
emit('A02.L20.cancel', ['arm' => $arm, 'seq' => $seq, 'cancels' => $cancels, 'unitsAfter' => $u($lab, $k20['spray'])]);
OaaaAsserts::check('A02', 'L20 ' . ($cand ? 'the 4th-device request is denied by §5.2 although its spray reservation was granted; the reservation is cancelled at once (0 units held)' : 'S0: the 4th-device request is denied'), $cand ? str_ends_with($seq, 'B-') && $cancels === 1 && $u($lab, $k20['spray']) === 0 : str_ends_with($seq, 'B-'), ['seq' => $seq, 'cancels' => $cancels, 'units' => $u($lab, $k20['spray'])]);

// L21 — all-or-nothing across K1 and K2: two UA-141 devices are seen by pre-only checks (churn count 2, no outcome, no credit on K2(141)),
// then three UA-140 subjects succeed (the spray set reaches 5 with 2 credited accounts), then the probe: UA 141, a 3rd device, a new subject
// -> spray fires (K1 credited, room) AND churn fires (K2(141) has no credit).
$lab = oaaaLab();
$pre = $cmd($lab, $ctx('10.2.22.1', 'l21y0', 'fy0', 141), 'check') . $cmd($lab, $ctx('10.2.22.1', 'l21y1', 'fy1', 141), 'check');
for ($i = 0; $i < 3; $i++) {
    $pre .= $attempt($lab, $ctx('10.2.22.1', "l21s$i", "fx$i", 140), 'ok');
}
$k21 = $k($lab, $ctx('10.2.22.1', 'x', null, 141));
$sprayBefore = $u($lab, $k21['spray']);
$probe = $attempt($lab, $ctx('10.2.22.1', 'l21y2', 'fy2', 141), 'fail');
$refusals = $lab->corr instanceof OaaaStore ? count(array_filter($lab->corr->oaaaLog, static fn(array $r): bool => $r['op'] === 'reserve' && ! $r['granted'])) : 0;
emit('A02.L21.all-or-nothing', ['arm' => $arm, 'pre' => $pre, 'probe' => $probe, 'sprayUnitsBefore' => $sprayBefore, 'sprayUnits' => $u($lab, $k21['spray']), 'churnUnits' => $u($lab, $k21['churn']), 'refusals' => $refusals]);
OaaaAsserts::check('A02', 'L21 ' . ($cand ? 'a request firing K1 spray (credited, room) and K2 churn (no credit) is refused as a whole and takes no unit on K1 either (no over-spend)' : 'S0: the probe is denied'), $cand ? $pre === 'aaaaaaaa' && $probe === 'B-' && $sprayBefore === 0 && $u($lab, $k21['spray']) === 0 && $u($lab, $k21['churn']) === 0 && $refusals === 1 : $probe === 'B-', ['pre' => $pre, 'probe' => $probe, 'sprayBefore' => $sprayBefore, 'spray' => $u($lab, $k21['spray']), 'churn' => $u($lab, $k21['churn']), 'refusals' => $refusals]);

// L22 — pre+out pairing on K2 churn: churn fires on the pre-check AND on the failure command; exactly one unit is spent.
$lab = oaaaLab();
$attempt($lab, $ctx('10.2.23.1', 'l22a', 'ca', 150), 'ok');
$attempt($lab, $ctx('10.2.23.1', 'l22b', 'cb', 150), 'ok');
$k22 = $k($lab, $ctx('10.2.23.1', 'x', null, 150));
$probe = $attempt($lab, $ctx('10.2.23.1', 'l22c', 'cc', 150), 'fail');
$grants = $lab->corr instanceof OaaaStore ? count(array_filter($lab->corr->oaaaLog, static fn(array $r): bool => $r['op'] === 'reserve' && $r['granted'])) : 0;
emit('A02.L22.pairing', ['arm' => $arm, 'probe' => $probe, 'churnUnits' => $u($lab, $k22['churn']), 'grants' => $grants]);
OaaaAsserts::check('A02', 'L22 ' . ($cand ? 'a pre+out failing attempt that fires K2 churn on both commands spends exactly one unit (one grant, paired outcome)' : 'S0: the 3rd fingerprint is denied'), $cand ? $probe === 'aa' && $u($lab, $k22['churn']) === 1 && $grants === 1 : $probe === 'B-', ['probe' => $probe, 'units' => $u($lab, $k22['churn']), 'grants' => $grants]);

// L23 — record-only (no pre-check) failures with churn firing: each takes a unit until the scope is spent, then S0.
$lab = oaaaLab();
$cmd($lab, $ctx('10.2.24.1', 'l23a', 'da', 151), 'ok');
$cmd($lab, $ctx('10.2.24.1', 'l23b', 'db', 151), 'ok');
$k23 = $k($lab, $ctx('10.2.24.1', 'x', null, 151));
$seq = '';
for ($i = 0; $i < 4; $i++) {
    $seq .= $cmd($lab, $ctx('10.2.24.1', "l23v$i", "dv$i", 151), 'fail');
}
emit('A02.L23.record-only', ['arm' => $arm, 'seq' => $seq, 'churnUnits' => $u($lab, $k23['churn'])]);
OaaaAsserts::check('A02', 'L23 ' . ($cand ? 'record-only failures firing churn take one unit each (2) and then receive the S0 block decision' : 'S0: record-only failures from the 3rd fingerprint on receive the block decision'), $cand ? $seq === 'aaBB' && $u($lab, $k23['churn']) === 2 : $seq === 'BBBB', ['seq' => $seq, 'units' => $u($lab, $k23['churn'])]);

OaaaAsserts::finish();
