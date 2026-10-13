<?php

declare(strict_types=1);

// #110 plan 1 — OQC (Outcome-Qualified Correlation) candidate measurement harness. EVIDENCE ONLY: not part of the package runtime.
//
// Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + REAL DeviceIdentityResolver + reference stores + controlled clock), the
// PR #104 wsb streams, the PR #105 D streams and the PR #108 K1 harness (K1Store signal attribution, k1Replay / k1Aggregate, K1 streams).
// The pipeline under test is selected by LAB_ROOT + env OQC_ARM:
//   S0           the repository's UNPATCHED src/ (sha-256 asserted) — OQC_ARM must be unset/empty
//   OQC          the isolated patched tree (build_candidate.sh): outcome-qualified K1 spray / K2 churn
//   OQCW         OQC + K1 WATCH counts new members only (arm 1b of #110)
//   OQCE         OQC + re-issue escalation L2 -> L3 -> L4 on live scopes (arm 1d of #110)
// The injected correlation store is always the PR #108 K1Store in arm 'A' (no TEST-ONLY controls): every protection is the real code.
require_once dirname(__DIR__, 2) . '/k1-credential-spray-nat/lib/k1.php';
require_once __DIR__ . '/cells.php';

use Maatify\RateLimiter\Service\EvaluationPipeline;

const OQC_BASELINE_SHA = '63fbb4abf0a6d7899d063d1fbd5d6fc7a1920ba1';
const OQC_BASE_PIPELINE_SHA256 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
const OQC_PATCHED_PIPELINE_SHA256 = '189f6e41020f983cc7e58531e3248b49fe84b8d39f08c3e283950c4d442f776f';
const OQC_ARM_TITLE = [
    'S0' => 'S0 as shipped (unpatched)',
    'OQC' => 'OQC: K1/K2 evidence = reported failures where outcomes are reported',
    'OQCW' => 'OQC + K1 WATCH on new member only (1b)',
    'OQCE' => 'OQC + re-issue escalation L2/L3/L4 on live scopes (1d)',
];

function oqcArm(): string
{
    $a = getenv('OQC_ARM');

    return $a === false || $a === '' ? 'S0' : $a;
}

/** Runtime-evidence level of the code path that actually executed (never merged across levels). */
function oqcLevel(): string
{
    return oqcArm() === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME';
}

/**
 * Proves WHICH EvaluationPipeline was loaded. S0 must be the repository's own unpatched file (pinned hash, OQC_ARM empty); a candidate
 * arm must be the patched copy (pinned hash) living OUTSIDE the repository's src/. Any mismatch aborts with exit 4.
 *
 * @return array<string, mixed>
 */
function oqcProvenance(): array
{
    $file = (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = hash_file('sha256', (string) $file);
    $repoPipeline = realpath(dirname(__DIR__, 3) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath((string) $file) === $repoPipeline;
    $arm = oqcArm();
    if (! in_array($arm, OQC_ARMS_LIST, true)) {
        fwrite(STDERR, "OQC provenance violation: unknown arm '$arm'\n");
        exit(4);
    }
    if ($arm === 'S0') {
        if ($sha !== OQC_BASE_PIPELINE_SHA256 || ! $inRepo || getenv('WSB_CANDIDATE') !== false && getenv('WSB_CANDIDATE') !== '') {
            fwrite(STDERR, "S0 provenance violation: loaded EvaluationPipeline is not the repository's unpatched baseline ($sha)\n");
            exit(4);
        }
    } elseif ($sha !== OQC_PATCHED_PIPELINE_SHA256 || $inRepo) {
        fwrite(STDERR, "candidate provenance violation: loaded pipeline is not the pinned patched candidate or is the repository's own src file ($sha)\n");
        exit(4);
    }

    return ['arm' => $arm, 'level' => oqcLevel(), 'baseSha' => OQC_BASELINE_SHA, 'pipelineSha256' => $sha, 'patched' => $sha !== OQC_BASE_PIPELINE_SHA256];
}

/** Lab on the loaded runtime with the #108 K1Store (arm 'A' = no controls, full signal attribution). */
function oqcLab(string $policy = 'login', ?string $previousSecret = null, string $secret = Lab::SECRET, ?Lab $share = null, ?bool $fingerprintRotation = null): Lab
{
    return k1Lab('A', $policy, $previousSecret, $secret, $share, $fingerprintRotation);
}

/**
 * Events as the k1 replay expects them for the given shape (api shapes carry no account id; outcomes are shaped by wsbShapeOutcome).
 *
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function oqcShapeEvents(array $events, string $shape): array
{
    if (! str_starts_with($shape, 'api')) {
        return $events;
    }

    return array_map(static function (array $e): array {
        $e['acct'] = null;

        return $e;
    }, $events);
}

/**
 * One (stream, shape, flow) on the loaded runtime arm → compact aggregates (+ the per-request rows when $withRows).
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function oqcMeasure(array $events, string $shape, string $flow, bool $withRows = false): array
{
    $lab = oqcLab(wsbLabPolicy($shape));
    $rows = k1Replay($lab, oqcShapeEvents($events, $shape), $flow, $shape);
    $agg = k1Aggregate($rows);
    $agg['sprayObservations'] = array_sum(array_column($rows, 'sprayOps'));
    $agg['sprayThresholdRequests'] = count(array_filter($rows, static fn(array $r): bool => $r['sprayMet']));
    $corr = 0;
    foreach ($agg['blockIssuancesByScope'] as $scope => $n) {
        if ($scope === 'K1' || $scope === 'K2') {
            $corr += $n;
        }
    }
    $agg['correlationIssuances'] = $corr;
    $agg['liveFlags'] = wsbStoreStats($lab)['flags'];
    if ($withRows) {
        $agg['rows'] = $rows;
    }

    return $agg;
}

/**
 * Every stream of the #110 matrix: the #108 K1 streams, the #104 anti-evasion streams, the #105 D streams and the #110 streams below.
 *
 * @return array<string, array{title: string, class: string, events: list<array<string, mixed>>}>
 */
function oqcStreams(): array
{
    $S = k1Streams();
    foreach (wsbStreams() as $id => $s) {
        $S[$id] = ['title' => $s['title'], 'class' => wsbClass($id), 'events' => $s['events']];
    }
    foreach (['D1', 'D5', 'D6', 'D7'] as $id) {
        $s = dsgStreams()[$id];
        $S[$id] = ['title' => $s['title'], 'class' => oqcStreamClasses()[$id], 'events' => $s['events']];
    }

    // ABA_B — two legitimate colleagues behind one NAT (same UA) alternating one SUCCESSFUL login every 10 s for 20 minutes
    // (#100: in S0 the K2 churn WATCH blocks them from the third request on, and the stale 600 s set re-blocks after every expiry).
    $ev = [];
    for ($i = 0; $i < 120; $i++) {
        $ev[] = k1Ev($i * 10, NAT, $i % 2 ? 'B' : 'A', 'col' . ($i % 2), 'ok', 'legit', ['who' => 'col' . ($i % 2)]);
    }
    $S['ABA_B'] = ['title' => 'ABA_B two colleagues (fingerprints A/B) alternating successful logins every 10 s, 20 min', 'class' => 'benign', 'events' => $ev];
    // ABA_B1 — the same colleagues; colleague B mistypes its own password once at t=70 and once at t=640 (the cost of ONE failure
    // in an outcome-live scope).
    $ev = [];
    for ($i = 0; $i < 120; $i++) {
        $t = $i * 10;
        $ev[] = k1Ev($t, NAT, $i % 2 ? 'B' : 'A', 'col' . ($i % 2), in_array($t, [70, 640], true) ? 'fail' : 'ok', 'legit', ['who' => 'col' . ($i % 2)]);
    }
    $S['ABA_B1'] = ['title' => 'ABA_B1 ABA_B with two typos by colleague B (t=70, t=640)', 'class' => 'benign', 'events' => $ev];
    // ABA_A — the #100 Lead control: an attacker with TWO alternating fingerprints on one K2, a new victim account per attempt, failing
    // every 10 s for 20 minutes (120 attempts). S0 published/attested: 3/120 reach verification.
    $ev = [];
    for ($i = 0; $i < 120; $i++) {
        $ev[] = k1Ev($i * 10, '198.51.100.77', $i % 2 ? 'B' : 'A', "abav$i", 'fail', 'attack');
    }
    $S['ABA_A'] = ['title' => 'ABA_A attacker: two alternating fingerprints on one K2, new victim per attempt, 1 / 10 s, 120 attempts', 'class' => 'attack', 'events' => $ev];
    // K1R4 — the "4 subjects + repeated requests" K1 case: 4 distinct victims, then the same 4 victims retried for 10 minutes, 1 / 3 s,
    // all failing, no fingerprint (K1 WATCH path; §5.1 says 5 distinct, the implementation counts requests at 4).
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.78', null, 'r4v' . ($i % 4), 'fail', 'attack');
    }
    $S['K1R4'] = ['title' => 'K1R4 attacker: 4 distinct victims retried for 10 min (1 / 3 s, 200 attempts, no fingerprint)', 'class' => 'attack', 'events' => $ev];
    // SP5c — a compromised / forged TRUSTED session device sprays with a FRESH fingerprint per request (K1 advisory for it; K2 churn is
    // the only correlation left): the "trusted-session attacker" cell asked for in #110.
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = k1Ev($i * 3, '198.51.100.79', "stolen$i", "tcv$i", 'fail', 'attack', ['trusted' => true, 'sess' => 'sess-stolen']);
    }
    $S['SP5c'] = ['title' => 'SP5c spray from a trusted session device with a fresh fingerprint per request, 200 victims, 1 / 3 s', 'class' => 'attack', 'events' => $ev];
    // GAP — liveness/WATCH gap (#110 G5): colleagues A and B each mistype once at t=0/10 (churn count 2 -> WATCH 1), succeed until
    // t=300, are silent for 700 s (marker 600 s and set 600 s expire, WATCH 1800 s does not), then come back at t=1000.
    $ev = [k1Ev(0, NAT, 'A', 'col0', 'fail', 'legit', ['who' => 'col0']), k1Ev(10, NAT, 'B', 'col1', 'fail', 'legit', ['who' => 'col1'])];
    for ($t = 20; $t <= 300; $t += 10) {
        $ev[] = k1Ev($t, NAT, ($t / 10) % 2 ? 'B' : 'A', 'col' . (($t / 10) % 2), 'ok', 'legit', ['who' => 'col' . (($t / 10) % 2)]);
    }
    for ($t = 1000; $t <= 1200; $t += 10) {
        $ev[] = k1Ev($t, NAT, ($t / 10) % 2 ? 'B' : 'A', 'col' . (($t / 10) % 2), 'ok', 'legit', ['who' => 'col' . (($t / 10) % 2)]);
    }
    $S['GAP'] = ['title' => 'GAP two colleagues: one typo each at t=0/10, successes until t=300, silence 700 s, back at t=1000', 'class' => 'benign', 'events' => $ev];
    // OTPB — benign OTP office: 6 colleagues behind one NAT, each with a stable fingerprint, one OTP verification per 30 s for 30 min,
    // 10 % mistyped codes (the OTP policy has DISTRIBUTED_ACCOUNT, not CREDENTIAL_SPRAY).
    $r = new Lcg(1106);
    $ev = [];
    for ($t = 0, $i = 0; $t <= 1800; $t += 30, $i++) {
        $u = $i % 6;
        $ev[] = k1Ev($t, NAT, "otp$u", "oc$u", $r->pct() <= 10 ? 'fail' : 'ok', 'legit', ['who' => "oc$u"]);
    }
    $S['OTPB'] = ['title' => 'OTPB OTP office: 6 colleagues, stable fingerprints, 1 verification / 30 s, 10% mistyped codes, 30 min', 'class' => 'benign', 'events' => $ev];

    return $S;
}

/**
 * Assertion helper. S0 assertions are baseline invariants (hard-fail, exit 1). On a candidate arm a failed assertion is a FINDING
 * (recorded with ok:false, diffed by run.sh, exit 0). OQC_STRICT=1 (self-tests) makes every failed assertion fatal.
 */
final class OqcAsserts
{
    public static int $failed = 0;

    /** @param array<string, mixed> $data */
    public static function check(string $scenario, string $name, bool $ok, array $data = []): void
    {
        if (! $ok) {
            self::$failed++;
        }
        emit($scenario, ['arm' => oqcArm(), 'assert' => $name, 'ok' => $ok] + $data);
    }

    public static function finish(): void
    {
        $strict = getenv('OQC_STRICT') === '1' || oqcArm() === 'S0';
        exit($strict && self::$failed > 0 ? 1 : 0);
    }
}

/** "t:flag#count" encoding of a probe sequence. */
function oqcSteps(array $rows): array
{
    return array_map(static fn(array $r): string => $r['t'] . ':' . ($r['blocked'] ? 'B' : 'a'), $rows);
}
