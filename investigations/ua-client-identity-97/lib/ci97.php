<?php

declare(strict_types=1);

// #97 Host-managed client identity — isolated evidence harness. EVIDENCE ONLY: not part of the package runtime.
//
// Every arm runs the repository's UNPATCHED src/ at the pinned baseline (sha-256 asserted below). The only thing that varies is the
// DeviceIdentityResolverInterface implementation, i.e. the public extension point RateLimiterBuilder::withDeviceIdentityResolver():
//   S0  : the shipped DeviceIdentityResolver
//   CIR : candidate "Client-Instance Resolver" — when clientFingerprint is non-empty, the user agent is left out of the fingerprint
//         material; otherwise it is exactly S0 (this file, class Ci97ClientInstanceResolver)
//   NUA : control "No-UA" — as CIR, and null fingerprint when no clientFingerprint and no sessionDeviceId (Q4 only, not a candidate)
//
// Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + reference stores + controlled clock) and the merged historical streams
// (PR #103 fixtures/streams.php, PR #105 design D1) through the PR #105 loader chain. Nothing in those files is modified.
require_once dirname(__DIR__, 2) . '/workstream-b-anti-evasion/design/lib/dsg.php';

use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;

const CI97_BASELINE_SHA = '9e95f2fcca689440e9b0afc50cd9ef4158c096b3';
const CI97_RUNTIME_SHA256 = [
    EvaluationPipeline::class => '402139452040cb319208ca7a441b2f70210bf1acee4bd4a0ad666e8101ef6eab',
    DeviceIdentityResolver::class => 'ee654fcfa5d069cef5cfa37950812747aab3801e8f35a430fa132d4dca3c0e6c',
    EphemeralBucket::class => '00531031ebb4e864b5361a0b31332388db56c0d9e5e3c2752cf9756ec8febd8a',
    LoginProtectionPolicy::class => '6559a2b694679488093103b0a8eecf4d901cbbb98612fe1662b80b76ccc38c1f',
    ApiHeavyProtectionPolicy::class => '5d8ed852ed06eea8b5079f201d2eba56b833e629d368c4df3b45ee1a92b509c1',
];
/** Candidate / arm selectors of earlier studies. This study never patches src/; running under one is a provenance error. */
const CI97_FOREIGN_LABELS = ['WSB_CANDIDATE', 'OQC_ARM', 'GNH_ARM', 'OAAA_ARM', 'LAB_ROOT'];

/**
 * Candidate CIR. The ONLY behavioural difference from the shipped resolver: with a non-empty clientFingerprint, the UA slot of the
 * fingerprint material is a constant, so the material is "v2|other/0|{normalizedClientFp}|{sessionDeviceId}" instead of
 * "v2|{normalizedUa}|...". Canonicalisation, session id, confidence, trust flags, previous-generation hash, exceptions and the
 * returned normalizedUa (hence K2 = IP + normalized UA) are the shipped implementation's, obtained by delegation.
 *
 * Note for any later implementation: a production change would use its own domain tag (e.g. "v3|...") instead of the constant UA
 * slot; this harness keeps the shipped code path to guarantee that no other byte of the derivation differs.
 */
class Ci97ClientInstanceResolver extends DeviceIdentityResolver
{
    public const UA_BLIND = '';

    public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
    {
        if ($context->clientFingerprint === null || $context->clientFingerprint === []) {
            return parent::resolve($context);
        }

        return $this->uaBlind($context);
    }

    protected function uaBlind(RateLimitContextDTO $c): DeviceIdentityDTO
    {
        $d = parent::resolve(new RateLimitContextDTO(
            $c->ip,
            self::UA_BLIND,
            $c->accountId,
            $c->clientFingerprint,
            $c->sessionDeviceId,
            $c->isSessionTrusted,
            $c->headers,
            $c->isDevicePreviouslyVerifiedForAccount,
            $c->correlationId,
        ));

        return new DeviceIdentityDTO(
            $d->fingerprintHash,
            $d->confidence,
            $d->isTrustedSession,
            $d->churnDetected,
            self::normalizeUserAgent($c->ua),
            $d->isDevicePreviouslyVerifiedForAccount,
            $d->previousFingerprintHash,
        );
    }
}

/** Control NUA (Q4): UA removed from the fingerprint everywhere; no client fingerprint and no session id => null fingerprint. */
final class Ci97NoUaControlResolver extends Ci97ClientInstanceResolver
{
    public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
    {
        $noFp = $context->clientFingerprint === null || $context->clientFingerprint === [];
        $noSession = $context->sessionDeviceId === null || $context->sessionDeviceId === '';
        if ($noFp && $noSession) {
            return new DeviceIdentityDTO(
                null,
                'LOW',
                false,
                false,
                self::normalizeUserAgent($context->ua),
                $context->isDevicePreviouslyVerifiedForAccount,
                null,
            );
        }

        return $this->uaBlind($context);
    }
}

/**
 * POST-HOC PROBE CIRL (added after the pre-registered run; NOT a pre-registered arm and NOT eligible for the verdict): the CIR
 * derivation, but an untrusted instance id no longer lifts confidence to MEDIUM — the request stays LOW, as if no client fingerprint
 * had been sent. It isolates one root cause of the pre-registered failures (the MEDIUM dilution path). It deliberately departs from
 * DEVICE_FINGERPRINT.md §4.2 ("Passive + Client = MEDIUM") and is therefore a contract question, not a drop-in candidate.
 */
final class Ci97LowConfidenceProbeResolver extends Ci97ClientInstanceResolver
{
    public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
    {
        $d = parent::resolve($context);
        if ($d->confidence !== 'MEDIUM') {
            return $d;
        }

        return new DeviceIdentityDTO(
            $d->fingerprintHash,
            'LOW',
            $d->isTrustedSession,
            $d->churnDetected,
            $d->normalizedUa,
            $d->isDevicePreviouslyVerifiedForAccount,
            $d->previousFingerprintHash,
        );
    }
}

/** Counterfactual store: neutralises ONLY the K2 churn distinct set (600 s / cap 3). Attribution only, never a candidate. */
final class Ci97ChurnOffStore extends CountingCorrelationStore
{
    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        if ($ttlSeconds === 600 && $maxDistinct === 3) {
            $this->distinctCalls++;

            return new BoundedDistinctResultDTO(1, true);
        }

        return parent::addDistinctBounded($key, $item, $ttlSeconds, $maxDistinct);
    }
}

/**
 * Proves WHICH runtime ran: the repository's own unpatched files, byte-identical to the pinned hashes. Exit 4 otherwise.
 *
 * @return array<string, mixed>
 */
function ci97Provenance(): array
{
    foreach (CI97_FOREIGN_LABELS as $env) {
        $v = getenv($env);
        if ($v !== false && $v !== '') {
            fwrite(STDERR, "ci97 provenance violation: $env is set ($v); this study runs only the unpatched repository src/\n");
            exit(4);
        }
    }
    $repo = realpath(dirname(__DIR__, 3));
    $hashes = [];
    foreach (CI97_RUNTIME_SHA256 as $class => $pinned) {
        $file = (string) (new \ReflectionClass($class))->getFileName();
        $sha = hash_file('sha256', $file);
        $inRepo = $repo !== false && str_starts_with((string) realpath($file), $repo . '/src/');
        if ($sha !== $pinned || ! $inRepo) {
            fwrite(STDERR, "ci97 provenance violation: $class is not the repository's pinned baseline ($sha, $file)\n");
            exit(4);
        }
        $hashes[substr($class, strrpos($class, '\\') + 1)] = $sha;
    }

    return ['level' => 'UNPATCHED_PIPELINE_RESOLVER_VARIANTS', 'baseSha' => CI97_BASELINE_SHA, 'sha256' => $hashes, 'srcPatched' => false];
}

/** Runtime resolver of an arm: S0, CIR or NUA. */
function ci97Runtime(string $arm): string
{
    return match ($arm) {
        'S0N', 'S0I' => 'S0',
        'CIRI', 'CIRN', 'CIRI-CHOFF' => 'CIR',
        'NUAN', 'NUAI' => 'NUA',
        'CIRLI' => 'CIRL',
    };
}

/** Whether legitimate clients carry their host-issued instance id in the arm. */
function ci97LegitSendsId(string $arm): bool
{
    return in_array($arm, ['S0I', 'CIRI', 'NUAI', 'CIRI-CHOFF', 'CIRLI'], true);
}

function ci97Resolver(string $runtime, string $secret, ?string $previousFpSecret): DeviceIdentityResolver
{
    $current = new FingerprintHasher($secret);
    $previous = $previousFpSecret === null ? null : new FingerprintHasher($previousFpSecret);

    return match ($runtime) {
        'S0' => new DeviceIdentityResolver($current, $previous),
        'CIR' => new Ci97ClientInstanceResolver($current, $previous),
        'NUA' => new Ci97NoUaControlResolver($current, $previous),
        'CIRL' => new Ci97LowConfidenceProbeResolver($current, $previous),
    };
}

/**
 * A Lab running the unpatched pipeline with the arm's resolver.
 *
 * @param 'login'|'api'|'api_custom' $policy
 */
function ci97Lab(string $arm, string $policy, ?string $previousFpSecret = null, ?Lab $shareStateWith = null, bool $corrDisabled = false): Lab
{
    $lab = new Lab($policy, null, $corrDisabled, Lab::SECRET, $shareStateWith);
    if ($arm === 'CIRI-CHOFF') {
        $lab->corr = new Ci97ChurnOffStore($lab->clock);
        $lab->pipeline = new EvaluationPipeline(
            $lab->store,
            $lab->corr,
            new BudgetTracker($lab->store, $lab->clock),
            new AntiEquilibriumGate($lab->corr),
            new DecayCalculator($lab->clock),
            new EphemeralBucket($lab->corr),
            Lab::SECRET,
            'prod',
            $lab->clock,
            null,
        );
    }
    $lab->resolver = ci97Resolver(ci97Runtime($arm), Lab::SECRET, $previousFpSecret);

    return $lab;
}

// ---- user agents ----------------------------------------------------------------------------------------------------------------------

const CI97_UA = [
    'chrome126' => CHROME,
    'chrome127' => CHROME_B,
    'firefox130' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
    'firefox131' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
    'android' => 'okhttp/4.12.0',
    'ios' => 'AcmeBank/7.3.1 CFNetwork/1494.0.7 Darwin/23.4.0',
    'custom' => 'AcmeDesktop/5.2 (Windows; x64)',
];

/** Fresh browser major per request (attacker strategy uarot): chrome/{200+n}. */
function ci97RotUa(int $n): string
{
    return sprintf('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.0.0 Safari/537.36', 200 + $n);
}

// ---- streams --------------------------------------------------------------------------------------------------------------------------

/**
 * Abstract streams. Event: t, ip, ua (label), who (actor), acct, outcome, kind legit|attack|owner, plus optional sid / trusted / known.
 * Historical streams are converted 1:1 from the merged definitions (only the field naming changes; `who` = the historical fingerprint
 * label when present, else the account, else the IP).
 *
 * @return array<string, array{cls: string, shapes: list<string>, events: list<array<string, mixed>>}>
 */
function ci97Streams(): array
{
    $hist = evidenceStreams();
    $byPrefix = static function (string $prefix) use ($hist): array {
        foreach ($hist as $title => $ev) {
            if (str_starts_with($title, $prefix . ' ')) {
                return $ev;
            }
        }
        throw new \RuntimeException("historical stream $prefix not found");
    };
    $conv = static fn(array $ev): array => array_map(static fn(array $e): array => [
        't' => $e['t'], 'ip' => $e['ip'], 'ua' => $e['ua'], 'who' => $e['fp'] ?? $e['acct'] ?? $e['ip'], 'acct' => $e['acct'],
        'outcome' => $e['outcome'], 'kind' => $e['kind'],
    ], $ev);
    $e = static fn(int $t, string $ip, string $ua, string $who, ?string $acct, string $o, string $k, array $x = []): array => [
        't' => $t, 'ip' => $ip, 'ua' => $ua, 'who' => $who, 'acct' => $acct, 'outcome' => $o, 'kind' => $k,
    ] + $x;
    $sort = static function (array $ev): array {
        usort($ev, static fn(array $a, array $b): int => [$a['t'], $a['kind'], $a['who']] <=> [$b['t'], $b['kind'], $b['who']]);

        return $ev;
    };
    $login = ['login'];
    $api = ['api', 'api_custom'];
    $S = [];

    $d1 = $conv(dsgStreams()['D1']['events']);
    $S['D1'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $d1];
    $S['L3'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $conv($byPrefix('L3'))];

    $apps = ['android', 'ios', 'custom'];
    $S['D1A'] = ['cls' => 'benign', 'shapes' => $login, 'events' => array_map(
        static fn(array $x, int $i): array => ['ua' => $apps[$i % 3]] + $x,
        $d1,
        array_keys($d1),
    )];

    $S['L4'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $conv($byPrefix('L4'))];

    $r = new Lcg(9701);
    $ev = [];
    $nets = ['198.51.100.10', '203.0.113.200', '192.0.2.150'];
    for ($t = 0, $n = 0; $t < 3600; $t += 60, $n++) {
        $ev[] = $e($t, $nets[intdiv($n, 4) % 3], 'chrome126', 'mob', 'acct-mob', $r->pct() <= 8 ? 'fail' : 'ok', 'legit');
    }
    $S['RM1'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $ev];

    $ev = [];
    for ($t = 0, $n = 1; $t < 1800; $t += 30, $n++) {
        $ev[] = $e($t, "100.64.7.$n", 'chrome126', 'cgn', 'acct-cgn', 'ok', 'legit');
    }
    $S['RM2'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $ev];

    $S['L1'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $conv($byPrefix('L1'))];

    // OFF12: shape of host-managed-client-identity-110 officeEvents(12, 30, 110) on one NAT and one Firefox major.
    $r = new Lcg(110);
    $ev = [];
    for ($i = 0; $i < 12; $i++) {
        $who = sprintf('u%03d', $i);
        if ($r->pct() <= 10) {
            $ev[] = $e($i * 30, '198.51.100.20', 'firefox130', $who, "acct-$who", 'fail', 'legit');
            $ev[] = $e($i * 30 + 15, '198.51.100.20', 'firefox130', $who, "acct-$who", 'ok', 'legit');
        } else {
            $ev[] = $e($i * 30, '198.51.100.20', 'firefox130', $who, "acct-$who", 'ok', 'legit');
        }
    }
    $S['OFF12'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $sort($ev)];

    // SESS: pre-auth login (id only), then a re-authentication carrying the same id plus a trusted session device id.
    $ev = [];
    for ($c = 0; $c < 4; $c++) {
        $ev[] = $e($c * 450, '198.51.100.30', 'chrome126', 'ses', 'acct-ses', 'ok', 'legit');
        $ev[] = $e($c * 450 + 120, '198.51.100.30', 'chrome126', 'ses', 'acct-ses', 'ok', 'legit', ['sid' => 'sess-ses', 'trusted' => true]);
    }
    $S['SESS'] = ['cls' => 'benign', 'shapes' => $login, 'events' => $ev];

    $ipv6 = static fn(int $n): string => sprintf('2001:db8:%x:%x::1', intdiv($n, 65536), $n % 65536);
    $S['V6D1'] = ['cls' => 'benign', 'shapes' => $login, 'events' => array_map(
        static fn(array $x, int $i): array => ['ip' => $ipv6($i + 1)] + $x,
        $d1,
        array_keys($d1),
    )];
    $S['V6L1'] = ['cls' => 'benign', 'shapes' => $login, 'events' => array_map(
        static fn(array $x): array => ['ip' => '2001:db8:aa:1::' . ($x['who'] === 'fpA' ? '10' : '20')] + $x,
        $S['L1']['events'],
    )];

    $S['X2'] = ['cls' => 'attack', 'shapes' => $login, 'events' => $conv($byPrefix('X2'))];
    $S['X7'] = ['cls' => 'attack', 'shapes' => $login, 'events' => $conv($byPrefix('X7'))];
    $S['X2L'] = ['cls' => 'both', 'shapes' => $login, 'events' => $conv($byPrefix('X2L'))];
    $S['X8'] = ['cls' => 'both', 'shapes' => $login, 'events' => $conv($byPrefix('X8'))];

    $ev = [];
    for ($i = 0; $i < 200; $i++) {
        $ev[] = $e($i * 6, '192.0.2.77', 'firefox130', 'atk', sprintf('acct-v%03d', $i), 'fail', 'attack');
    }
    $S['SP'] = ['cls' => 'attack', 'shapes' => $login, 'events' => $ev];

    $ev = [];
    for ($i = 0; $i < 100; $i++) {
        $ev[] = $e($i * 30, '192.0.2.77', 'firefox130', 'atk', 'acct-victim', 'fail', 'attack');
    }
    $S['BRU'] = ['cls' => 'attack', 'shapes' => $login, 'events' => $ev];

    $ev = [];
    for ($i = 0; $i < 100; $i++) {
        $ev[] = $e($i * 30, sprintf('192.0.%d.%d', 10 + intdiv($i, 250), $i % 250 + 1), 'firefox130', 'atk', 'acct-victim', 'fail', 'attack');
    }
    $S['BRD'] = ['cls' => 'attack', 'shapes' => $login, 'events' => $ev];

    $ev = [];
    for ($t = 0; $t <= 600; $t += 10) {
        $ev[] = $e($t, '198.51.100.77', 'chrome126', 'atk', 'victim', 'fail', 'attack');
    }
    $S['X1'] = ['cls' => 'attack', 'shapes' => $login, 'events' => $ev];

    $ev = [];
    for ($i = 0; $i < 300; $i++) {
        $ev[] = $e($i * 2, '192.0.2.99', 'chrome126', 'atk', sprintf('acct-m%03d', $i), 'fail', 'attack');
    }
    $S['MASS'] = ['cls' => 'attack', 'shapes' => $login, 'events' => $ev];

    // POI: the owner logs in from home every 120 s; an attacker on 8 IPs fails on random accounts every 10 s, presenting the owner's
    // UA (S0N, strategy none) or the owner's id (strategy stolen). The owner is the only legitimate actor.
    $ev = [];
    for ($t = 0; $t < 1800; $t += 120) {
        $ev[] = $e($t, '198.51.100.40', 'firefox130', 'owner', 'acct-owner', 'ok', 'legit');
    }
    for ($t = 5, $n = 0; $t < 1800; $t += 10, $n++) {
        $ev[] = $e($t, '192.0.2.' . (50 + $n % 8), 'firefox130', 'atk', "acct-p$n", 'fail', 'attack');
    }
    $S['POI'] = ['cls' => 'both', 'shapes' => $login, 'events' => $sort($ev)];

    foreach (['KD' => false, 'UAC' => true] as $id => $uaChange) {
        $ev = [];
        for ($i = 0; $i < 12; $i++) {
            $ev[] = $e($i * 60, '198.51.100.20', $uaChange && $i >= 6 ? 'firefox131' : 'firefox130', 'owner', 'acct-owner', 'fail', 'owner', ['known' => true]);
        }
        $S[$id] = ['cls' => 'owner', 'shapes' => $login, 'events' => $ev];
    }

    $S['D1API'] = ['cls' => 'benign', 'shapes' => $api, 'events' => $d1];
    $ev = [];
    for ($t = 0; $t <= 600; $t += 2) {
        $ev[] = $e($t, '198.51.100.88', 'chrome126', 'atk', null, 'ok', 'attack');
    }
    $S['X1API'] = ['cls' => 'attack', 'shapes' => $api, 'events' => $ev];
    $S['X2API'] = ['cls' => 'attack', 'shapes' => $api, 'events' => $S['X2']['events']];

    return $S;
}

const CI97_SIGMA_N = ['none', 'uarot'];
const CI97_SIGMA_I = ['none', 'uarot', 'static', 'rotate', 'static_uarot'];

/** Attacker strategies evaluated for a stream in an arm (benign / owner streams: '-'). */
function ci97Strategies(string $stream, string $cls, string $arm): array
{
    if ($cls === 'benign' || $cls === 'owner') {
        return ['-'];
    }
    $sigma = ci97LegitSendsId($arm) ? CI97_SIGMA_I : CI97_SIGMA_N;
    if ($stream === 'POI' && ci97LegitSendsId($arm)) {
        $sigma[] = 'stolen';
    }

    return $sigma;
}

/** Host-issued instance id of a legitimate actor (opaque, random-looking, deterministic). */
function ci97Id(string $who): string
{
    return 'ciid-' . substr(hash('sha256', "ci97|$who"), 0, 32);
}

/**
 * Materialise one abstract stream for an arm and an attacker strategy: which id / UA every request carries.
 *
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function ci97Materialise(array $events, string $arm, string $strategy): array
{
    $legitId = ci97LegitSendsId($arm);
    $out = [];
    $n = 0;
    foreach ($events as $ev) {
        $ua = CI97_UA[$ev['ua']] ?? throw new \RuntimeException("unknown UA label {$ev['ua']}");
        $id = null;
        if ($ev['kind'] === 'attack') {
            $rotUa = in_array($strategy, ['uarot', 'static_uarot'], true);
            $ua = $rotUa ? ci97RotUa($n) : $ua;
            $id = match ($strategy) {
                'none', 'uarot' => null,
                'static', 'static_uarot' => ci97Id('attacker-static'),
                'rotate' => ci97Id("attacker-$n"),
                'stolen' => ci97Id('owner'),
            };
            $n++;
        } elseif ($legitId) {
            $id = ci97Id((string) $ev['who']);
        }
        $out[] = $ev + ['uaFull' => $ua, 'id' => $id];
    }

    return $out;
}

function ci97Ctx(array $ev, string $shape, ?array $idOverride = null): RateLimitContextDTO
{
    $acct = str_starts_with($shape, 'api') ? null : $ev['acct'];
    $fp = $idOverride ?? ($ev['id'] === null ? null : ['ciid' => $ev['id']]);

    return new RateLimitContextDTO(
        $ev['ip'],
        $ev['uaFull'],
        $acct,
        $fp,
        $ev['sid'] ?? null,
        $ev['trusted'] ?? false,
        [],
        $ev['known'] ?? false,
        null,
    );
}

/** Persisted L2+ hard blocks active now on the request's canonical keys (attribution of a denial). */
function ci97BlockedKeys(Lab $lab, RateLimitContextDTO $c): string
{
    $now = $lab->clock->now()->getTimestamp();
    $out = [];
    foreach ($lab->keys($c) as $name => $key) {
        $b = $key === null ? null : $lab->store->checkBlock($key);
        if ($b !== null && $b->level >= 2 && $b->expiresAt > $now) {
            $out[] = $name;
        }
    }

    return $out === [] ? '-' : implode('+', $out);
}

const CI97_CONF_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];

function ci97Char(array $r): string
{
    return $r['d'];
}

/**
 * Host-realistic replay of a materialised stream on one Lab (or on alternating Labs for a mixed fleet).
 *
 * $flow: 'pre+out' (checkOnly, record only if allowed) | 'pre' (checkOnly only) | 'out' (record only).
 * $labFor: callable(int $index): Lab — returns the worker that serves request $index (one Lab for a normal cell).
 *
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function ci97Replay(callable $labFor, array $events, string $shape, string $flow): array
{
    $check = '';
    $record = '';
    $kinds = '';
    $who = [];
    $whoIndex = [];
    $firstDenyKeys = [];
    $confMismatch = 0;
    $confEscalation = 0;
    $ownerFirstDenialScores = null;
    $maxOps = 0;
    $fps = [];
    $k3 = [];
    $k5 = [];
    $shipped = null;
    foreach ($events as $i => $ev) {
        /** @var Lab $lab */
        $lab = $labFor($i);
        $lab->at($ev['t']);
        $c = ci97Ctx($ev, $shape);
        $outcome = str_starts_with($shape, 'api') ? 'ok' : $ev['outcome'];

        // G-S4a: the arm's resolver must report the shipped resolver's confidence and trust flags on the same context
        // (confMismatch: any difference; confEscalation: higher confidence or a different trust flag than the shipped resolver).
        $shipped ??= new DeviceIdentityResolver(new FingerprintHasher(Lab::SECRET));
        $mine = $lab->resolver->resolve($c);
        $ref = $shipped->resolve($c);
        $trustDiff = $mine->isTrustedSession !== $ref->isTrustedSession
            || $mine->isDevicePreviouslyVerifiedForAccount !== $ref->isDevicePreviouslyVerifiedForAccount;
        if ($trustDiff || ($mine->fingerprintHash !== null && $mine->confidence !== $ref->confidence)) {
            $confMismatch++;
        }
        if ($trustDiff || CI97_CONF_RANK[$mine->confidence] > CI97_CONF_RANK[$ref->confidence]) {
            $confEscalation++;
        }

        $ch = '-';
        $rc = '-';
        $denied = false;
        if ($flow !== 'out') {
            $r = $lab->run($c, 'check');
            $ch = ci97Char($r);
            $maxOps = max($maxOps, $r['dOps'] + $r['wOps']);
            $denied = $ch !== 'A';
        }
        if ($flow !== 'pre' && ! $denied) {
            $r = $lab->run($c, $outcome);
            $rc = ci97Char($r);
            $maxOps = max($maxOps, $r['dOps'] + $r['wOps']);
            $denied = $rc !== 'A';
        }
        $kind = $ev['kind'] === 'attack' ? 'X' : ($ev['kind'] === 'owner' ? 'O' : 'L');
        $whoKey = (string) $ev['who'];
        if (! isset($whoIndex[$whoKey])) {
            $whoIndex[$whoKey] = count($whoIndex);
        }
        $who[] = $whoIndex[$whoKey];
        if ($denied && $kind !== 'X' && ! isset($firstDenyKeys[$whoKey])) {
            $firstDenyKeys[$whoKey] = ci97BlockedKeys($lab, $c);
            if ($kind === 'O') {
                $s = $lab->scores($c);
                $ownerFirstDenialScores = ['K4' => $s['K4'] ?? 0, 'K5' => $s['K5'] ?? 0];
            }
        }
        $check .= $ch;
        $record .= $rc;
        $kinds .= $kind;
        $keys = $lab->keys($c);
        $fps[(string) $mine->fingerprintHash] = true;
        if ($keys['K3'] !== null) {
            $k3[$keys['K3']] = true;
        }
        if ($keys['K5'] !== null && $mine->fingerprintHash !== null) {
            $k5[$keys['K5']] = true;
        }
    }
    $attr = [];
    foreach ($firstDenyKeys as $keysAt) {
        $attr[$keysAt] = ($attr[$keysAt] ?? 0) + 1;
    }
    ksort($attr);

    return [
        'check' => $check,
        'record' => $record,
        'kinds' => $kinds,
        'who' => $who,
        'firstDenialBlockedKeys' => $attr,
        'confMismatch' => $confMismatch,
        'confEscalation' => $confEscalation,
        'ownerFirstDenialScores' => $ownerFirstDenialScores,
        'maxOpsPerCommand' => $maxOps,
        'distinctFingerprints' => count($fps),
        'distinctK3' => count($k3),
        'distinctK5' => count($k5),
    ];
}

/**
 * Metrics re-derivable from the archived strings (report.php recomputes them with the same function).
 *
 * @param list<int> $who
 * @return array<string, int>
 */
function ci97Metrics(string $check, string $record, string $kinds, array $who, string $flow): array
{
    $n = strlen($kinds);
    $m = ['legitAttempts' => 0, 'legitDenied' => 0, 'legitUsers' => 0, 'legitUsersDenied' => 0, 'attackAttempts' => 0, 'reach' => 0, 'attackDenied' => 0];
    $users = [];
    $usersDenied = [];
    for ($i = 0; $i < $n; $i++) {
        $denied = ($check[$i] !== '-' && $check[$i] !== 'A') || ($record[$i] !== '-' && $record[$i] !== 'A');
        if ($kinds[$i] === 'X') {
            $m['attackAttempts']++;
            if ($flow !== 'out' && $check[$i] === 'A') {
                $m['reach']++;
            }
            if ($denied) {
                $m['attackDenied']++;
            }
            continue;
        }
        $m['legitAttempts']++;
        $users[$who[$i]] = true;
        if ($denied) {
            $m['legitDenied']++;
            $usersDenied[$who[$i]] = true;
        }
    }
    $m['legitUsers'] = count($users);
    $m['legitUsersDenied'] = count($usersDenied);
    if ($flow === 'out') {
        $m['reach'] = $m['attackAttempts'];
    }

    return $m;
}

// ---- C01 matrix definition (shared by scenarios/c01_matrix.php and report.php) -------------------------------------------------------

const C01_ARMS = ['S0N', 'S0I', 'CIRI', 'CIRN'];
const C01_NUA_STREAMS = ['D1', 'L3', 'L1', 'X2', 'X7', 'SP', 'BRU', 'BRD', 'MASS', 'KD'];
const C01_CHOFF_STREAMS = ['D1', 'L1', 'OFF12', 'V6L1', 'SESS', 'X1', 'MASS'];

function c01Arms(string $stream): array
{
    $arms = C01_ARMS;
    if (in_array($stream, C01_NUA_STREAMS, true)) {
        array_push($arms, 'NUAN', 'NUAI');
    }
    if (in_array($stream, C01_CHOFF_STREAMS, true)) {
        $arms[] = 'CIRI-CHOFF';
    }

    return $arms;
}

function c01Flows(string $shape): array
{
    return str_starts_with($shape, 'api') ? ['out', 'pre+out'] : ['pre+out', 'pre', 'out'];
}
