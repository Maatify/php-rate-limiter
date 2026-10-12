<?php

declare(strict_types=1);

// A06 — indistinguishability analysis. For each pair the attacker stream and the legitimate stream carry the IDENTICAL observable
// tuples the library receives (t, ip, UA, client fingerprint, account id, outcome). The pipeline is a deterministic function of those
// tuples, so no policy built on them can treat the two differently. The rows prove (a) the observable digests are equal and
// (b) the measured decisions are equal when the same events are labelled "legit" vs "attack". What would be needed to separate
// them is NOT available to the library (documented in the README, not asserted here).
require dirname(__DIR__) . '/lib/wsb.php';

emit('A06.provenance', wsbProvenance());
$e = static fn(int $t, string $ip, ?string $fp, ?string $acct, string $o, string $k = 'attack'): array => ['t' => $t, 'ip' => $ip, 'ua' => 'chrome126', 'fp' => $fp, 'acct' => $acct, 'outcome' => $o, 'kind' => $k];

$pairs = [];
// I1 — one NAT+UA: three browsers that clear their storage (fresh fingerprint each visit) mistype, vs an insider rotating fingerprints.
$ev = [];
for ($t = 0, $n = 1; $t <= 300; $t += 30, $n++) {
    $ev[] = $e($t, NAT, "cl$n", "u$n", 'fail');
}
$pairs['I1 cookie-clearing colleagues mistyping vs fingerprint-rotating insider (shared NAT)'] = $ev;
// I2 — mass failure of LOW users (forced password expiry wave) vs distributed LOW attacker: one failure, new IP, new account each.
$ev = [];
for ($t = 0, $n = 1; $t <= 600; $t += 5, $n++) {
    $ev[] = $e($t, "wave$n", null, "w$n", 'fail');
}
$pairs['I2 password-expiry failure wave of LOW users vs distributed LOW attacker'] = $ev;
// I3 — ratio padding: failures interleaved with two successes on ONE account from new IPs; a shared service/family account is a legit match.
$ev = [];
for ($t = 0, $n = 1; $t <= 600; $t += 5, $n++) {
    $ev[] = $e($t, "p$n", null, $n % 3 === 0 ? "v$n" : 'shared', $n % 3 === 0 ? 'fail' : 'ok');
}
$pairs['I3 shared account logging in from many IPs between typos vs success-padding attacker'] = $ev;
// I4 — one fingerprint seen from 6 IPs, all successes: a roaming device (carrier / Wi-Fi hand-over) vs a replayed fingerprint.
$ev = [];
for ($i = 1; $i <= 12; $i++) {
    $ev[] = $e($i * 20, "roam$i", 'devX', 'userR', 'ok');
}
$pairs['I4 roaming device (fingerprint across IPs, successes) vs replayed fingerprint with valid credentials'] = $ev;

foreach ($pairs as $title => $ev) {
    $asLegit = array_map(static fn(array $x): array => ['kind' => 'legit'] + $x, $ev);
    $asAttack = array_map(static fn(array $x): array => ['kind' => 'attack'] + $x, $ev);
    $digest = static fn(array $events): string => hash('sha256', json_encode(array_map(static fn(array $x): array => [$x['t'], $x['ip'], $x['ua'], $x['fp'], $x['acct'], $x['outcome']], $events), JSON_THROW_ON_ERROR));
    $mLegit = wsbMeasure($asLegit, 'login', 'pre+out');
    $mAtk = wsbMeasure($asAttack, 'login', 'pre+out');
    $same = $mLegit['legitDenied'] === $mAtk['attackDenied'] && $mLegit['legitAttempts'] === $mAtk['attackAttempts'] && $mLegit['legitFirstDenyT'] === $mAtk['attackFirstDenyT'];
    emit('A06.pair', ['variant' => wsbVariant(), 'pair' => $title, 'events' => count($ev), 'observableDigestLegit' => $digest($asLegit), 'observableDigestAttack' => $digest($asAttack), 'labelledLegit' => ['denied' => $mLegit['legitDenied'], 'firstDenyT' => $mLegit['legitFirstDenyT']], 'labelledAttack' => ['denied' => $mAtk['attackDenied'], 'firstDenyT' => $mAtk['attackFirstDenyT']], 'identicalTreatment' => $same]);
    WsbAsserts::check('A06', "indistinguishable: $title", $digest($asLegit) === $digest($asAttack) && $same, []);
}
WsbAsserts::finish();
