<?php

declare(strict_types=1);

// #110 trust family (plans 2 / 3 / 2+3) — the R23-01 counter-case measured on S0 ONLY. EVIDENCE ONLY: not part of the package runtime.
//
// Question: in the shipped runtime (S0), what bounds an attacker who holds VALID trusted sessions for several accounts behind one shared
// IP + User-Agent, and how does S0 tell that attacker apart from the benign trusted office N12T (#108)? There is NO candidate, NO patch and
// NO arm here: every scenario loads the repository's unpatched EvaluationPipeline (sha-256 asserted; a candidate label is refused, exit 4).
// Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + REAL DeviceIdentityResolver + reference stores + controlled clock) and the
// PR #108 K1 harness (K1Store signal attribution in arm 'A' = no controls, k1Replay / k1Aggregate, the N12T / N12M / N12 / SP5 streams).
require_once dirname(__DIR__, 2) . '/k1-credential-spray-nat/lib/k1.php';

use Maatify\RateLimiter\Service\EvaluationPipeline;

const TMS_BASELINE_SHA = 'ce89cb26241a660d58e9d896ee2f9ae56f0239c8';
const TMS_PIPELINE_SHA256 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
const TMS_ACCOUNTS = 12;

/**
 * Proves WHICH pipeline ran: the repository's own, unpatched EvaluationPipeline (byte-identical to the pinned hash), loaded from the
 * repository tree. LAB_ROOT must be absent or the repository itself; WSB_CANDIDATE / OQC_ARM must be absent. Any mismatch aborts with exit 4.
 *
 * @return array<string, mixed>
 */
function tmsProvenance(): array
{
    $file = (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = hash_file('sha256', (string) $file);
    $repoPipeline = realpath(dirname(__DIR__, 3) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repoPipeline !== false && realpath((string) $file) === $repoPipeline;
    foreach (['WSB_CANDIDATE', 'OQC_ARM'] as $env) {
        $v = getenv($env);
        if ($v !== false && $v !== '') {
            fwrite(STDERR, "TMS provenance violation: $env is set ($v); this study has no candidate arm\n");
            exit(4);
        }
    }
    if ($sha !== TMS_PIPELINE_SHA256 || ! $inRepo) {
        fwrite(STDERR, "TMS provenance violation: loaded EvaluationPipeline is not the repository's unpatched baseline ($sha)\n");
        exit(4);
    }

    return ['level' => 'S0_RUNTIME_OBSERVED', 'baseSha' => TMS_BASELINE_SHA, 'pipelineSha256' => $sha, 'patched' => false];
}

/** @return array<string, string> stream id => class (benign | attack | both) */
function tmsStreamClasses(): array
{
    return [
        // references, UNCHANGED from lib/k1.php (#108)
        'N12T' => 'benign', 'N12M' => 'benign', 'N12' => 'benign', 'SP5' => 'attack',
        // this study
        'TMS' => 'attack', 'TMSd' => 'attack', 'TMR' => 'attack', 'TMX' => 'attack', 'TMXb' => 'attack', 'TMU' => 'attack', 'TMSs' => 'attack',
        'TPAIRT' => 'both', 'TPAIRM' => 'both',
    ];
}

/** Streams whose per-request trace is recorded (scenarios/t02_trace.php). */
function tmsTraceStreams(): array
{
    return ['N12T', 'TMS', 'TMSd', 'TMSs', 'TMR', 'TMX', 'TMU'];
}

/**
 * Deterministic streams. Every attacker stream sits on the N12T NAT address and the N12T browser (same K1 and K2 scopes as the office).
 *
 * @return array<string, array{title: string, class: string, events: list<array<string, mixed>>}>
 */
function tmsStreams(): array
{
    $k1 = k1Streams();
    $S = [];
    foreach (['N12T', 'N12M', 'N12', 'SP5'] as $id) {
        $S[$id] = ['title' => $k1[$id]['title'], 'class' => tmsStreamClasses()[$id], 'events' => $k1[$id]['events']];
    }

    // TMS — the R23-01 attacker at the N12T cadence: one request per 40 s on a rotating target, 40 min, 12 targets. The attacker HOLDS a
    // valid trusted session for every target (Host-compliant: the session is bound to the account it targets), one stable client
    // fingerprint per session, and every attempt FAILS. Request for request this is N12T with 'ok' replaced by 'fail' (and who/acct renamed).
    $ev = [];
    for ($t = 0, $i = 0; $t <= 2400; $t += 40, $i++) {
        $u = $i % TMS_ACCOUNTS;
        $ev[] = k1Ev($t, NAT, "stable$u", "vic$u", 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => "sess-vic$u"]);
    }
    $S['TMS'] = ['title' => 'TMS attacker with 12 VALID trusted sessions (one per target account) behind the N12T NAT, N12T cadence (1 / 40 s, 40 min), all attempts fail', 'class' => 'attack', 'events' => $ev];

    // TMSd — the same attacker, dense: 200 attempts at 1 / 3 s cycling the 12 targets (the SP1n cadence).
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $u = $i % TMS_ACCOUNTS;
        $ev[] = k1Ev($i * 3, NAT, "stable$u", "vic$u", 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => "sess-vic$u"]);
    }
    $S['TMSd'] = ['title' => 'TMSd the TMS attacker, dense: 200 attempts at 1 / 3 s cycling the 12 trusted-session targets', 'class' => 'attack', 'events' => $ev];

    // TMSs — the same attacker, dense, but SEQUENTIAL per target: 12 targets, 17 consecutive attempts each (~200), 1 / 3 s.
    $ev = [];
    for ($i = 0; $i < 204; $i++) {
        $u = intdiv($i, 17);
        $ev[] = k1Ev($i * 3, NAT, "stable$u", "vic$u", 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => "sess-vic$u"]);
    }
    $S['TMSs'] = ['title' => 'TMSs the TMS attacker, dense and sequential per target: 17 consecutive attempts on each of the 12 trusted-session targets, 1 / 3 s', 'class' => 'attack', 'events' => $ev];

    // TMR — TMSd with a NEW client fingerprint on every attempt (the session of each target is kept; only the client-side fingerprint rotates).
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $u = $i % TMS_ACCOUNTS;
        $ev[] = k1Ev($i * 3, NAT, "rot$i", "vic$u", 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => "sess-vic$u"]);
    }
    $S['TMR'] = ['title' => 'TMR the TMSd attacker rotating the client fingerprint on every attempt (trusted session per target kept)', 'class' => 'attack', 'events' => $ev];

    // TMX — Host-VIOLATING control: ONE trusted session (bound to vic0 only) presented while targeting all 12 accounts. The contract
    // (DECISION_MATRIX §0) says such a request is not a trusted session for vic1..vic11; the Host that marks it trusted anyway is what SP5
    // measures with 200 fresh victims. Here the 12 targets repeat, so the per-account protections get to accumulate.
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $u = $i % TMS_ACCOUNTS;
        $ev[] = k1Ev($i * 3, NAT, 'stolen', "vic$u", 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => 'sess-vic0']);
    }
    $S['TMX'] = ['title' => 'TMX Host-violating control: ONE trusted session (bound to vic0) presented against all 12 targets, 200 attempts at 1 / 3 s', 'class' => 'attack', 'events' => $ev];

    // TMXb — the TMX Host-violating control, sequential per target and longer: 25 consecutive attempts on each of the 12 targets (300), so the
    // per-account protections that TMX never reaches in 17 attempts (K4 thresholds, the 24 h budget) get their chance.
    $ev = [];
    for ($i = 0; $i < 300; $i++) {
        $u = intdiv($i, 25);
        $ev[] = k1Ev($i * 3, NAT, 'stolen', "vic$u", 'fail', 'attack', ['who' => 'atk', 'trusted' => true, 'sess' => 'sess-vic0']);
    }
    $S['TMXb'] = ['title' => 'TMXb the TMX Host-violating control, sequential: 25 consecutive attempts on each of the 12 targets (300 at 1 / 3 s)', 'class' => 'attack', 'events' => $ev];

    // TMU — untrusted control: TMSd with the same 12 session identifiers but isSessionTrusted = false (same fingerprints, no trust).
    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $u = $i % TMS_ACCOUNTS;
        $ev[] = k1Ev($i * 3, NAT, "stable$u", "vic$u", 'fail', 'attack', ['who' => 'atk', 'trusted' => false, 'sess' => "sess-vic$u"]);
    }
    $S['TMU'] = ['title' => 'TMU untrusted control: TMSd with the same 12 session identifiers but isSessionTrusted = false', 'class' => 'attack', 'events' => $ev];

    // TPAIRT — the identical-signal pair behind one NAT: the N12T trusted office PLUS the TMSd attacker from t = 300 (own 12 target accounts).
    $ev = $S['N12T']['events'];
    foreach ($S['TMSd']['events'] as $e) {
        $e['t'] += 300;
        $ev[] = $e;
    }
    $S['TPAIRT'] = ['title' => 'TPAIRT N12T trusted office + the TMSd attacker (12 other accounts, own trusted sessions) behind the same NAT from t=300', 'class' => 'both', 'events' => k1Sort($ev)];

    // TPAIRM — half-trusted office (N12M) PLUS the TMSd attacker from t = 300.
    $ev = $S['N12M']['events'];
    foreach ($S['TMSd']['events'] as $e) {
        $e['t'] += 300;
        $ev[] = $e;
    }
    $S['TPAIRM'] = ['title' => 'TPAIRM N12M half-trusted office + the TMSd attacker behind the same NAT from t=300', 'class' => 'both', 'events' => k1Sort($ev)];

    return $S;
}

/** @return list<array{0: string, 1: string, 2: string}> [stream, shape, flow] */
function tmsMatrixCells(): array
{
    $cells = [];
    foreach (array_keys(tmsStreamClasses()) as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $cells[] = [$id, 'login', $flow];
        }
    }
    foreach (['N12T', 'TMS', 'TMSd', 'TMSs', 'TMR', 'TMX', 'TMU'] as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $cells[] = [$id, 'otp', $flow];
        }
    }

    return $cells;
}

/** Integer fields every matrix cell must carry (fail-closed validation). */
function tmsCellFields(): array
{
    return ['legitAttempts', 'legitDenied', 'attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackDistinctSubjectsVerified', 'attackReachedFirst600', 'attackReachedPerAccountMax', 'correlationIssuances', 'k2Issuances', 'k5Issuances', 'k4Issuances'];
}

/**
 * One (stream, shape, flow) on S0 → aggregates (+ the per-request rows when $withRows). Same replay and attribution as PR #108 / #111.
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function tmsMeasure(array $events, string $shape, string $flow, bool $withRows = false): array
{
    $lab = k1Lab('A', wsbLabPolicy($shape));
    $rows = k1Replay($lab, $events, $flow, $shape);
    $agg = k1Aggregate($rows);
    $first600 = 0;
    $perAccount = [];
    $firstReach = null;
    $lastReach = null;
    foreach ($rows as $r) {
        if ($r['kind'] !== 'attack' || ! $r['reached']) {
            continue;
        }
        if ($r['t'] < 600) {
            $first600++;
        }
        $perAccount[(string) $r['subject']] = ($perAccount[(string) $r['subject']] ?? 0) + 1;
        $firstReach ??= $r['t'];
        $lastReach = $r['t'];
    }
    ksort($perAccount);
    $agg['attackReachedFirst600'] = $first600;
    $agg['attackReachedPerAccount'] = $perAccount;
    $agg['attackReachedPerAccountMax'] = $perAccount === [] ? 0 : max($perAccount);
    $agg['attackFirstReachT'] = $firstReach;
    $agg['attackLastReachT'] = $lastReach;
    $by = $agg['blockIssuancesByScope'];
    $agg['correlationIssuances'] = ($by['K1'] ?? 0) + ($by['K2'] ?? 0);
    $agg['k2Issuances'] = $by['K2'] ?? 0;
    $agg['k5Issuances'] = ($by['K5'] ?? 0) + ($by['K5*'] ?? 0);
    $agg['k4Issuances'] = $by['K4'] ?? 0;
    if ($withRows) {
        $agg['rows'] = $rows;
    }

    return $agg;
}

/** ASSERT helper: a violated S0 invariant exits non-zero (the study is then not measuring the published runtime). */
final class TmsAsserts
{
    public static int $failed = 0;

    /** @param array<string, mixed> $data */
    public static function check(string $scenario, string $name, bool $ok, array $data = []): void
    {
        if (! $ok) {
            self::$failed++;
        }
        emit($scenario, ['assert' => $name, 'ok' => $ok] + $data);
    }

    public static function finish(): void
    {
        exit(self::$failed > 0 ? 1 : 0);
    }
}
