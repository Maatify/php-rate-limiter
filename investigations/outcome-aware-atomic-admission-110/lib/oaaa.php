<?php

declare(strict_types=1);

// #110 Outcome-Aware Atomic Admission (OAAA) study harness. EVIDENCE ONLY: not part of the package runtime.
//
// Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + REAL DeviceIdentityResolver + reference stores + controlled clock), the
// PR #104 / #105 / #108 / #111 / #113 / #115 streams (through gnhStreams()) and the PR #108 K1Store signal attribution. The runtime under test
// is selected by LAB_ROOT + env OAAA_ARM:
//   S0    the repository's UNPATCHED src/ (pipeline sha-256 asserted; the experimental interface must NOT exist) — OAAA_ARM unset/empty
//   OAAA  the isolated patched tree (build_candidate.sh): S0 rules unchanged + an atomic, credit-gated, outcome-linked admission
//         reservation over the correlation candidates (EvaluationPipeline + new OutcomeAdmissionStoreInterface + Redis Lua)
// On OAAA the correlation store is OaaaStore (lib/oaaa_store.php, the reference implementation of the experimental capability); on S0 it is
// the plain PR #108 K1Store in arm 'A'. Every protection decision is the real code; the stores only observe.
require_once dirname(__DIR__, 2) . '/guarded-network-headroom-110/lib/gnh.php';
require_once __DIR__ . '/cells.php';

use Maatify\RateLimiter\Service\EvaluationPipeline;

const OAAA_BASELINE_SHA = '72f6dea8ab0729e63492b622e682e334c527c966';
const OAAA_BASE_PIPELINE_SHA256 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
const OAAA_BASE_REDIS_SHA256 = '520574d4b0758470c0fc36ec304001825cf20a183e28bf6a999bcd41d54297a4';
const OAAA_PATCHED_PIPELINE_SHA256 = '2af6f5599c809a829c0a7c792e35e49dc9e777482f2560250fa60f59067ffa20';
const OAAA_PATCHED_REDIS_SHA256 = 'c57320d945cfca96bc17b702fd67c613a347bf0d764e4d4d091951c3c4aaee90';
const OAAA_INTERFACE_SHA256 = '8db98be51202774d48f540e577b8619895bf6046f45ae3b8e00d8f16058942e2';
const OAAA_INTERFACE = 'Maatify\\RateLimiter\\Repository\\OutcomeAdmissionStoreInterface';

function oaaaArm(): string
{
    $a = getenv('OAAA_ARM');

    return $a === false || $a === '' ? 'S0' : $a;
}

/** Runtime-evidence level of the code path that actually executed (never merged across levels). */
function oaaaLevel(): string
{
    return oaaaArm() === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME';
}

/**
 * Proves WHICH runtime was loaded. S0 = the repository's own unpatched pipeline and Redis adapter (pinned hashes) with no experimental
 * interface; OAAA = the pinned patched pipeline, Redis adapter and interface living OUTSIDE the repository's src/. A foreign study's arm
 * variable must be absent. Any mismatch aborts with exit 4.
 *
 * @return array<string, mixed>
 */
function oaaaProvenance(): array
{
    $file = (string) (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $redisFile = (string) (new \ReflectionClass(\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class))->getFileName();
    $sha = hash_file('sha256', $file);
    $redisSha = hash_file('sha256', $redisFile);
    $repoPipeline = realpath(dirname(__DIR__, 3) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath($file) === $repoPipeline;
    $arm = oaaaArm();
    foreach (['GNH_ARM', 'OQC_ARM', 'WSB_CANDIDATE'] as $foreign) {
        $v = getenv($foreign);
        if ($v !== false && $v !== '') {
            fwrite(STDERR, "OAAA provenance violation: $foreign is set ($v); this study's arm is OAAA_ARM only\n");
            exit(4);
        }
    }
    if (! in_array($arm, OAAA_ARMS_LIST, true)) {
        fwrite(STDERR, "OAAA provenance violation: unknown arm '$arm'\n");
        exit(4);
    }
    $iface = interface_exists(OAAA_INTERFACE);
    if ($arm === 'S0') {
        if ($sha !== OAAA_BASE_PIPELINE_SHA256 || ! $inRepo || $redisSha !== OAAA_BASE_REDIS_SHA256 || $iface) {
            fwrite(STDERR, "S0 provenance violation: loaded runtime is not the repository's unpatched baseline ($sha / $redisSha / interface " . ($iface ? 'present' : 'absent') . ")\n");
            exit(4);
        }
    } else {
        $ifaceSha = $iface ? hash_file('sha256', (string) (new \ReflectionClass(OAAA_INTERFACE))->getFileName()) : '';
        if ($sha !== OAAA_PATCHED_PIPELINE_SHA256 || $inRepo || $redisSha !== OAAA_PATCHED_REDIS_SHA256 || $ifaceSha !== OAAA_INTERFACE_SHA256) {
            fwrite(STDERR, "candidate provenance violation: loaded runtime is not the pinned patched candidate or is the repository's own src ($sha / $redisSha / $ifaceSha)\n");
            exit(4);
        }
        require_once __DIR__ . '/oaaa_store.php';
    }

    return ['arm' => $arm, 'level' => oaaaLevel(), 'baseSha' => OAAA_BASELINE_SHA, 'pipelineSha256' => $sha, 'redisAdapterSha256' => $redisSha, 'experimentalInterface' => $iface, 'patched' => $sha !== OAAA_BASE_PIPELINE_SHA256];
}

/** The correlation store of the loaded arm (OaaaStore on OAAA, the plain K1Store arm 'A' on S0, or a K1Store on OAAA when $capability = false). */
function oaaaNewStore(\Maatify\SharedCommon\Contracts\ClockInterface $clock, bool $capability = true): K1Store
{
    if (oaaaArm() === 'OAAA' && $capability) {
        require_once __DIR__ . '/oaaa_store.php';

        return new OaaaStore($clock, 'A');
    }

    return new K1Store($clock, 'A');
}

/** Lab (REAL pipeline, REAL resolver, reference stores, controlled clock) for the loaded arm. With $share the stores/clock of an earlier Lab are reused. */
function oaaaLab(string $policy = 'login', ?string $previousSecret = null, string $secret = Lab::SECRET, ?Lab $share = null, ?bool $fingerprintRotation = null, bool $capability = true): Lab
{
    $lab = new Lab($policy === 'login_custom' ? 'login' : $policy, $previousSecret, false, $secret, $share, $fingerprintRotation);
    if ($share === null) {
        $lab->corr = oaaaNewStore($lab->clock, $capability);
    }
    if ($policy === 'login_custom') {
        $lab->policy = new OaaaCustomLoginPolicy();
    }

    return gnhRewire($lab, $secret, $previousSecret);
}

/** DEC-008 custom authentication policy: the Login preset under another name (custom policies with authentication capabilities). */
final class OaaaCustomLoginPolicy extends \Maatify\RateLimiter\Config\LoginProtectionPolicy
{
    public function getName(): string
    {
        return 'custom_login';
    }
}

function oaaaStore(Lab $lab): K1Store
{
    assert($lab->corr instanceof K1Store);

    return $lab->corr;
}

/** OAAA signal of the last request (0 on S0 or without the capability). */
function oaaaSig(K1Store $store, string $name): int
{
    return (int) ($store->sig[$name] ?? 0);
}

/**
 * Host-realistic replay with per-request attribution — the PR #108 / #115 method (persisted blocks active before the request, blocks issued
 * by it from the DEC-003 cycle log, correlation signals observed during it), extended with the OAAA reservation signals of the request
 * (granted / refused reservations, resolved reservations, cancels).
 *
 * flow: pre+out | pre | out   (checkOnly then the outcome only if allowed / checkOnly only / record-only)
 *
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function oaaaReplay(Lab $lab, array $events, string $flow, string $shape): array
{
    $store = oaaaStore($lab);
    $origin = [];
    $rows = [];
    foreach ($events as $ev) {
        $lab->at($ev['t']);
        $c = k1Ctx($ev, $shape);
        $outcome = wsbShapeOutcome($shape, $ev['outcome']);
        $scope = k1Scope($lab, $c);
        $now = $lab->clock->now()->getTimestamp();
        $before = [];
        foreach ($scope as $key => $name) {
            $b = $lab->store->checkBlock($key);
            if ($b !== null && $b->expiresAt > $now) {
                $before[] = $name;
            }
        }
        $cyc0 = count($lab->store->cycleLog);
        $blocked = false;
        $reached = false;
        $retry = null;
        $level = null;
        $decision = 'ALLOW';
        $granted = 0;
        $refused = 0;
        $paired = 0;
        $cancels = 0;
        $sigs = [];
        $collect = static function () use ($store, &$granted, &$refused, &$paired, &$cancels, &$sigs): void {
            $granted += oaaaSig($store, 'oaaaGranted');
            $refused += oaaaSig($store, 'oaaaRefused');
            $paired += oaaaSig($store, 'oaaaPaired');
            $cancels += oaaaSig($store, 'oaaaCancels');
            foreach (['sprayMet' => 'spray', 'churnMet' => 'churn', 'dilMet' => 'dil', 'distMet' => 'dist'] as $f => $label) {
                if ($store->sig[$f]) {
                    $sigs[$label] = true;
                }
            }
        };
        if ($flow !== 'out') {
            $store->resetSignals();
            $r = $lab->run($c, 'check');
            $collect();
            $blocked = $r['d'] !== 'A';
            $reached = ! $blocked;
            if ($blocked) {
                [$retry, $level, $decision] = [$r['retry'], $r['lvl'], $r['d'] === 'H' ? 'HARD_BLOCK' : 'SOFT_BLOCK'];
            }
        }
        if ($flow !== 'pre' && ! $blocked) {
            $store->resetSignals();
            $r = $lab->run($c, $outcome);
            $collect();
            $blocked = $r['d'] !== 'A';
            $reached = true;
            if ($blocked) {
                [$retry, $level, $decision] = [$r['retry'], $r['lvl'], $r['d'] === 'H' ? 'HARD_BLOCK' : 'SOFT_BLOCK'];
            }
        }
        $label = $sigs === [] ? 'score/other' : implode('+', array_keys($sigs));
        $issued = [];
        $issuedKeys = [];
        foreach (array_slice($lab->store->cycleLog, $cyc0) as $cy) {
            $name = $scope[$cy['key']] ?? 'K5*';
            $issued[] = $name;
            $issuedKeys[] = $cy['key'];
            $origin[$cy['key']] = $label;
        }
        $src = null;
        if ($blocked) {
            if ($before !== []) {
                $parts = [];
                foreach ($scope as $key => $name) {
                    if (in_array($name, $before, true)) {
                        $parts[] = $name . '(' . ($origin[$key] ?? '?') . ')';
                    }
                }
                $src = 'persisted:' . implode('+', $parts);
            } elseif ($issued !== []) {
                $src = 'issued:' . implode('+', $issued) . '(' . $label . ')';
            } else {
                $src = 'unpersisted(' . $label . ')';
            }
        }
        $rows[] = [
            't' => $ev['t'],
            'ip' => $ev['ip'],
            'kind' => $ev['kind'],
            'subject' => $ev['corr'] ?? $ev['acct'] ?? null,
            'outcome' => $outcome,
            'blocked' => $blocked,
            'reached' => $reached,
            'retry' => $retry,
            'level' => $level,
            'decision' => $decision,
            'src' => $src,
            'issued' => $issued,
            'issuedKeys' => $issuedKeys,
            'granted' => $granted,
            'refused' => $refused,
            'paired' => $paired,
            'cancels' => $cancels,
        ];
    }

    return $rows;
}

/** Max number of timestamps inside any half-open window [t, t+$w) (sliding). @param list<int> $ts sorted */
function oaaaSlidingMax(array $ts, int $w): int
{
    $best = 0;
    $j = 0;
    $n = count($ts);
    for ($i = 0; $i < $n; $i++) {
        while ($ts[$i] - $ts[$j] >= $w) {
            $j++;
        }
        $best = max($best, $i - $j + 1);
    }

    return $best;
}

/**
 * Aggregates of one replay. 'own' rows (an attacker's successful logins on accounts it owns) are counted separately from legitimate users
 * and from attack attempts. Bit strings carry the request-for-request outcome so report.php can compute exact S0-vs-OAAA deltas.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function oaaaAggregate(array $rows): array
{
    $t0 = $rows === [] ? 0 : min(array_column($rows, 't'));
    $legit = ['n' => 0, 'denied' => 0, 'bits' => ''];
    $atk = ['n' => 0, 'reached' => 0, 'reachedFailing' => 0, 'failing' => 0, 'denied' => 0, 'bits' => ''];
    $own = ['n' => 0, 'denied' => 0];
    $reachedTs = [];
    $by60 = [];
    $byIp600 = [];
    $levels = [];
    $decisions = [];
    $srcLegit = [];
    $srcAtk = [];
    $issuances = 0;
    $reissues = 0;
    $seen = [];
    $maxLevel = 0;
    $maxRetry = 0;
    $granted = 0;
    $attackGranted = 0;
    $refused = 0;
    $paired = 0;
    $cancels = 0;
    $victims = [];
    foreach ($rows as $r) {
        $decisions[$r['decision']] = ($decisions[$r['decision']] ?? 0) + 1;
        if ($r['blocked']) {
            $levels['L' . $r['level']] = ($levels['L' . $r['level']] ?? 0) + 1;
            $maxLevel = max($maxLevel, (int) $r['level']);
            $maxRetry = max($maxRetry, (int) $r['retry']);
        }
        if ($r['kind'] === 'attack') {
            $atk['n']++;
            $atk['failing'] += $r['outcome'] === 'fail' ? 1 : 0;
            $atk['bits'] .= $r['reached'] ? '1' : '0';
            if ($r['reached']) {
                $atk['reached']++;
                $atk['reachedFailing'] += $r['outcome'] === 'fail' ? 1 : 0;
                $victims[(string) $r['subject']] = true;
                $reachedTs[] = (int) $r['t'];
                $b = intdiv($r['t'] - $t0, 60);
                $by60[$b] = ($by60[$b] ?? 0) + 1;
                $w = intdiv($r['t'] - $t0, 600);
                $byIp600[$r['ip']][$w] = ($byIp600[$r['ip']][$w] ?? 0) + 1;
            }
            if ($r['blocked']) {
                $atk['denied']++;
                $srcAtk[(string) $r['src']] = ($srcAtk[(string) $r['src']] ?? 0) + 1;
            }
        } elseif ($r['kind'] === 'own') {
            $own['n']++;
            $own['denied'] += $r['blocked'] ? 1 : 0;
        } else {
            $legit['n']++;
            $legit['bits'] .= $r['blocked'] ? '1' : '0';
            if ($r['blocked']) {
                $legit['denied']++;
                $srcLegit[(string) $r['src']] = ($srcLegit[(string) $r['src']] ?? 0) + 1;
            }
        }
        foreach ($r['issuedKeys'] as $k) {
            $issuances++;
            if (isset($seen[$k])) {
                $reissues++;
            }
            $seen[$k] = true;
        }
        $granted += $r['granted'];
        $attackGranted += $r['kind'] === 'attack' ? $r['granted'] : 0;
        $refused += $r['refused'];
        $paired += $r['paired'];
        $cancels += $r['cancels'];
    }
    sort($reachedTs);
    $fixed = static function (int $w) use ($reachedTs, $t0): int {
        $c = [];
        foreach ($reachedTs as $t) {
            $k = intdiv($t - $t0, $w);
            $c[$k] = ($c[$k] ?? 0) + 1;
        }

        return $c === [] ? 0 : max($c);
    };
    $ipWindows = [];
    foreach ($byIp600 as $ip => $w) {
        ksort($w);
        $ipWindows[(string) $ip] = array_map('intval', $w);
    }
    ksort($ipWindows);
    ksort($by60);
    ksort($decisions);
    ksort($levels);
    ksort($srcLegit);
    ksort($srcAtk);

    return [
        'requests' => count($rows),
        'legitAttempts' => $legit['n'],
        'legitDenied' => $legit['denied'],
        'ownAttempts' => $own['n'],
        'ownDenied' => $own['denied'],
        'attackAttempts' => $atk['n'],
        'attackReachedVerification' => $atk['reached'],
        'attackDenied' => $atk['denied'],
        'attackFailingAttempts' => $atk['failing'],
        'attackFailingReached' => $atk['reachedFailing'],
        'attackDistinctSubjectsVerified' => count($victims),
        'attackReachedMaxPer60' => $fixed(60),
        'attackReachedMaxPer600' => $fixed(600),
        'attackReachedMaxPer1800' => $fixed(1800),
        'attackReachedSlidingMax600' => oaaaSlidingMax($reachedTs, 600),
        'attackReachedSlidingMax1800' => oaaaSlidingMax($reachedTs, 1800),
        'attackScopes' => count($ipWindows),
        'blockIssuances' => $issuances,
        'blockReissues' => $reissues,
        'maxLevel' => $maxLevel,
        'maxRetryAfter' => $maxRetry,
        'reservationsGranted' => $granted,
        'attackReservationsGranted' => $attackGranted,
        'reservationsRefused' => $refused,
        'completionsPaired' => $paired,
        'reservationCancels' => $cancels,
        'decisions' => $decisions,
        'deniedLevels' => $levels,
        'legitDeniedBySource' => $srcLegit,
        'attackDeniedBySource' => $srcAtk,
        'attackReachedBy60' => array_map('intval', $by60),
        'attackReachedByIp600' => $ipWindows,
        'legitDeniedBits' => $legit['bits'],
        'attackReachedBits' => $atk['bits'],
    ];
}

/**
 * One (stream, shape, flow) on the loaded runtime arm → aggregates (+ the per-request rows when $withRows).
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function oaaaMeasure(array $events, string $shape, string $flow, bool $withRows = false, bool $capability = true): array
{
    $lab = oaaaLab($shape === 'login-anon' ? 'login' : wsbLabPolicy($shape), capability: $capability);
    $rows = oaaaReplay($lab, oqcShapeEvents($events, $shape), $flow, $shape === 'login_custom' ? 'login' : $shape);
    $agg = oaaaAggregate($rows);
    if ($withRows) {
        $agg['rows'] = $rows;
    }

    return $agg;
}

/**
 * Every stream of the study: the #115 catalogue (#108, #104, #105, #111, #113 and #115 streams, unchanged) plus the adversarial streams
 * aimed at OAAA itself: an attacker that earns success credits on accounts it owns (OWN*), and a co-located attacker on a credit-rich NAT
 * that targets the colleagues' own accounts with an identical (LOW) tuple (TGT).
 *
 * @return array<string, array{title: string, class: string, events: list<array<string, mixed>>}>
 */
function oaaaStreams(): array
{
    $S = gnhStreams();
    $own = static function (array $attack, string $ip, int $from, int $to, int $every, array $extra = []): array {
        $ev = $attack;
        for ($t = $from, $i = 0; $t <= $to; $t += $every, $i++) {
            $ev[] = k1Ev($t, $ip, $extra['fp'] ?? null, 'own' . ($i % 2), 'ok', 'own', $extra);
        }

        return k1Sort($ev);
    };
    // OWN1 — SP1n plus two accounts the attacker OWNS, logged into successfully from the same IP every 60 s (credit farming).
    $S['OWN1'] = ['title' => 'OWN1 SP1n + the attacker logs into 2 accounts it owns every 60 s from the same IP (success credits)', 'class' => 'attack', 'events' => $own($S['SP1n']['events'], '198.51.100.7', 1, 600, 60)];
    // OWN2 — SP2 (low-and-slow N-1 hover, 3 h) plus own-account successes every 300 s: the long-horizon (day tier) bound.
    $S['OWN2'] = ['title' => 'OWN2 SP2 low-and-slow (3 h) + own-account successes every 300 s from the same IP', 'class' => 'attack', 'events' => $own($S['SP2']['events'], '198.51.100.11', 1, 18 * 601, 300)];
    // OWN6 — SP6 (1 h sustained spray, own UA) plus own-account successes every 60 s.
    $S['OWN6'] = ['title' => 'OWN6 SP6 sustained 1 h spray + own-account successes every 60 s from the same IP and UA', 'class' => 'attack', 'events' => $own($S['SP6']['events'], '198.51.100.17', 1, 3600, 60, ['uaRaw' => k1Ua(90)])];
    // OWN4 — SP4 (8 IPs, 3 h) plus own-account successes from every botnet IP every 120 s: the cross-scope aggregate (global tiers).
    $ev = $S['SP4']['events'];
    for ($ip = 0; $ip < 8; $ip++) {
        $ev = $own($ev, '198.51.100.' . (20 + $ip), 2 + $ip, 18 * 601, 120);
    }
    $S['OWN4'] = ['title' => 'OWN4 SP4 distributed spray (8 IPs, 3 h) + own-account successes from every IP every 120 s (cross-scope aggregate)', 'class' => 'attack', 'events' => $ev];
    // OWNK2 — ABA_A (K2 churn, two alternating fingerprints) plus own-account successes with the SAME two fingerprints every 60 s.
    $ev = $S['ABA_A']['events'];
    for ($t = 5, $i = 0; $t <= 1200; $t += 60, $i++) {
        $ev[] = k1Ev($t, '198.51.100.77', $i % 2 ? 'B' : 'A', 'own' . ($i % 2), 'ok', 'own');
    }
    $S['OWNK2'] = ['title' => 'OWNK2 ABA_A (K2 churn) + own-account successes with the same two fingerprints every 60 s', 'class' => 'attack', 'events' => k1Sort($ev)];
    // TGT — the Nu12 office (stable MEDIUM fingerprints, own browser major each) plus an attacker behind the same NAT that presents the
    // colleague's browser but NO fingerprint (LOW, so a DIFFERENT tuple) and targets the colleagues' own accounts, 1 / 20 s from t=300.
    $ev = $S['Nu12']['events'];
    for ($t = 300, $i = 0; $t <= 2400; $t += 20, $i++) {
        $ev[] = k1Ev($t, NAT, null, 'col' . ($i % 12), 'fail', 'attack', ['uaRaw' => k1Ua($i % 12)]);
    }
    $S['TGT'] = ['title' => 'TGT Nu12 office + a NAT attacker targeting the colleagues\' own accounts with their browser and no fingerprint, 1 / 20 s', 'class' => 'both', 'events' => k1Sort($ev)];
    // TGTM — the same attacker presents the colleague's exact MEDIUM fingerprint too (a stolen fingerprint): an IDENTICAL tuple, so the
    // colleague's next success refunds up to 2 of the attacker's recorded failures (the retroactive-refund path).
    $ev = $S['Nu12']['events'];
    for ($t = 300, $i = 0; $t <= 2400; $t += 20, $i++) {
        $u = $i % 12;
        $ev[] = k1Ev($t, NAT, "stable$u", "col$u", 'fail', 'attack', ['uaRaw' => k1Ua($u)]);
    }
    $S['TGTM'] = ['title' => 'TGTM TGT with each colleague\'s exact client fingerprint (stolen fingerprint, identical MEDIUM tuple)', 'class' => 'both', 'events' => k1Sort($ev)];
    // TGTL — a LOW office (6 colleagues, no client fingerprint, own browser major each, 1 login / 40 s, 8 % typos, 40 min) plus an attacker
    // with the colleague's browser and no fingerprint: an IDENTICAL LOW tuple (LOW tuples get no retroactive refund).
    $r = new Lcg(1110);
    $ev = [];
    for ($t = 0, $i = 0; $t <= 2400; $t += 40, $i++) {
        $u = $i % 6;
        $ev[] = k1Ev($t, NAT, null, "lcol$u", $r->pct() <= 8 ? 'fail' : 'ok', 'legit', ['who' => "lcol$u", 'uaRaw' => k1Ua(20 + $u)]);
    }
    for ($t = 300, $i = 0; $t <= 2400; $t += 20, $i++) {
        $ev[] = k1Ev($t, NAT, null, 'lcol' . ($i % 6), 'fail', 'attack', ['uaRaw' => k1Ua(20 + $i % 6)]);
    }
    $S['TGTL'] = ['title' => 'TGTL LOW office (6 colleagues, no fingerprint) + a NAT attacker with an identical LOW tuple targeting their accounts, 1 / 20 s', 'class' => 'both', 'events' => k1Sort($ev)];

    foreach ($S as $id => $s) {
        if (! isset(oaaaStreamClasses()[$id])) {
            unset($S[$id]);
        } else {
            $S[$id]['class'] = oaaaStreamClasses()[$id];
        }
    }

    return $S;
}

/**
 * Assertion helper. S0 assertions are baseline invariants (hard-fail, exit 1). On the candidate a failed assertion is a FINDING (recorded
 * with ok:false, diffed by run.sh, exit 0). OAAA_STRICT=1 (self-tests) makes every failed assertion fatal.
 */
final class OaaaAsserts
{
    public static int $failed = 0;

    /** @param array<string, mixed> $data */
    public static function check(string $scenario, string $name, bool $ok, array $data = []): void
    {
        if (getenv('OAAA_SELFTEST_BREAK') === '1') {
            $ok = false;
        }
        if (! $ok) {
            self::$failed++;
        }
        emit($scenario, ['arm' => oaaaArm(), 'assert' => $name, 'ok' => $ok] + $data);
    }

    public static function finish(): void
    {
        $strict = getenv('OAAA_STRICT') === '1' || oaaaArm() === 'S0';
        exit($strict && self::$failed > 0 ? 1 : 0);
    }
}
