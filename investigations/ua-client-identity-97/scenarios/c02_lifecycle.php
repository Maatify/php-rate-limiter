<?php

declare(strict_types=1);

// C02 — lifecycle cells of VALIDATION_PLAN.md §3 (ROT, MIX, ROLL, MAL) and the official-composition check (BLD), plus one
// clearly-labelled POST-HOC IPv6 cell (V6D1W, see VALIDATION_PLAN.md §7). Deterministic; one JSON line per row.
require_once dirname(__DIR__) . '/lib/ci97.php';

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;

$prov = ci97Provenance();
emit('c02', ['row' => 'provenance'] + $prov);
$streams = ci97Streams();

$cell = static function (string $row, string $stream, string $arm, string $strategy, string $flow, array $res, array $extra = []) use ($streams): array {
    return ['row' => $row, 'stream' => $stream, 'cls' => $streams[$stream]['cls'] ?? 'benign', 'arm' => $arm, 'strategy' => $strategy, 'flow' => $flow]
        + $extra + ci97Metrics($res['check'], $res['record'], $res['kinds'], $res['who'], $flow)
        + ['check' => $res['check'], 'record' => $res['record'], 'kinds' => $res['kinds'], 'who' => $res['who']];
};

// ---- ROT: fingerprint-secret rotation (current + previous hasher) must not change decisions on fresh state ------------------------
foreach ([['D1', '-'], ['X2', 'static']] as [$stream, $strategy]) {
    foreach (['S0I', 'CIRI'] as $arm) {
        $events = ci97Materialise($streams[$stream]['events'], $arm, $strategy);
        foreach ([false, true] as $rotation) {
            $lab = ci97Lab($arm, 'login', $rotation ? 'previous-fp-secret' : null);
            $res = ci97Replay(static fn(int $i): Lab => $lab, $events, 'login', 'pre+out');
            emit('c02', $cell('rot', $stream, $arm, $strategy, 'pre+out', $res, ['rotation' => $rotation]));
        }
    }
}

// ---- MIX: mixed-version fleet — every request is served by an S0I or a CIRI worker (seeded draw), one shared store ---------------
foreach (['L1', 'KD', 'D1'] as $stream) {
    $events = ci97Materialise($streams[$stream]['events'], 'CIRI', '-');
    $old = ci97Lab('S0I', 'login');
    $new = ci97Lab('CIRI', 'login', null, $old);
    $draw = new Lcg(9797);
    $workers = [];
    foreach ($events as $i => $unused) {
        $workers[$i] = $draw->pct() <= 50 ? 'O' : 'N';
    }
    $res = ci97Replay(static fn(int $i): Lab => $workers[$i] === 'O' ? $old : $new, $events, 'login', 'pre+out');
    emit('c02', $cell('mix', $stream, 'S0I+CIRI', '-', 'pre+out', $res, ['workers' => implode('', $workers)]));
}

// ---- ROLL: deployment switch S0I -> CIRI halfway through the stream, one shared store --------------------------------------------
foreach ([['BRU', 'static'], ['BRU', 'none'], ['KD', '-'], ['UAC', '-']] as [$stream, $strategy]) {
    $events = ci97Materialise($streams[$stream]['events'], 'CIRI', $strategy);
    $old = ci97Lab('S0I', 'login');
    $new = ci97Lab('CIRI', 'login', null, $old);
    $half = intdiv(count($events), 2);
    $res = ci97Replay(static fn(int $i): Lab => $i < $half ? $old : $new, $events, 'login', 'pre+out');
    emit('c02', $cell('roll', $stream, 'S0I>CIRI', $strategy, 'pre+out', $res, ['switchAt' => $half]));
}

// ---- MAL: resolver outcome per identifier input (shipped S0 vs candidate CIR) ----------------------------------------------------
$deep = 'x';
for ($i = 0; $i < 600; $i++) {
    $deep = [$deep];
}
$inputs = [
    'missing' => null,
    'empty_array' => [],
    'empty_string_id' => ['ciid' => ''],
    'null_id' => ['ciid' => null],
    'valid_id' => ['ciid' => ci97Id('mal')],
    'oversized_1MiB' => ['ciid' => str_repeat('a', 1048576)],
    'deep_600' => ['ciid' => $deep],
    'non_serialisable' => ['ciid' => new \stdClass()],
];
$base = ci97Materialise([['t' => 0, 'ip' => '198.51.100.9', 'ua' => 'chrome126', 'who' => 'mal', 'acct' => 'acct-mal', 'outcome' => 'ok', 'kind' => 'legit']], 'S0N', '-')[0];
foreach (['S0', 'CIR'] as $runtime) {
    $resolver = ci97Resolver($runtime, Lab::SECRET, null);
    $noId = $resolver->resolve(ci97Ctx($base, 'login'));
    foreach ($inputs as $label => $fp) {
        try {
            $d = $resolver->resolve($fp === null ? ci97Ctx($base, 'login') : ci97Ctx($base, 'login', $fp));
            $out = ['outcome' => 'resolved', 'confidence' => $d->confidence, 'sameAsNoId' => $d->fingerprintHash === $noId->fingerprintHash, 'hashLength' => strlen((string) $d->fingerprintHash)];
        } catch (\Throwable $e) {
            $out = ['outcome' => 'exception', 'exception' => substr(get_class($e), strrpos(get_class($e), '\\') + 1)];
        }
        emit('c02', ['row' => 'mal', 'runtime' => $runtime, 'input' => $label] + $out);
    }
}

// MALD1: a buggy Host sends the same empty id ['ciid' => ''] for every client (D1). Compared with S0N D1 from C01.
foreach (['S0I', 'CIRI'] as $arm) {
    $events = array_map(static fn(array $e): array => ['id' => ''] + $e, ci97Materialise($streams['D1']['events'], 'S0N', '-'));
    $lab = ci97Lab($arm, 'login');
    $res = ci97Replay(static fn(int $i): Lab => $lab, array_map(static fn(array $e): array => $e, $events), 'login', 'pre+out');
    emit('c02', $cell('mald1', 'D1', $arm, '-', 'pre+out', $res, ['id' => 'shared-empty']));
}

// ---- BLD: the same streams through the OFFICIAL composition (RateLimiterBuilder + RateLimiterEngine + full-capability in-memory
// store), with the candidate injected by withDeviceIdentityResolver(). Decision strings must equal the C01 Lab cells. -------------
foreach ([['D1', 'S0N'], ['D1', 'CIRI'], ['L1', 'S0N'], ['L1', 'CIRI'], ['X7', 'CIRI']] as [$stream, $arm]) {
    $strategy = $streams[$stream]['cls'] === 'attack' ? 'static' : '-';
    $events = ci97Materialise($streams[$stream]['events'], $arm, $strategy);
    $clock = new FixedClock('2026-01-01 12:00:00');
    $t0 = $clock->now()->getTimestamp();
    $builder = RateLimiterBuilder::fromFullCapabilityStore(
        new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
        new FullCapabilityInMemoryStore($clock),
        new RecordingFailureSignalEmitter(),
    )->withClock($clock);
    if (ci97Runtime($arm) !== 'S0') {
        $builder->withDeviceIdentityResolver(ci97Resolver(ci97Runtime($arm), 'fingerprint-secret', null));
    }
    $runtime = $builder->build();
    $check = '';
    $record = '';
    $kinds = '';
    $who = [];
    $whoIndex = [];
    $char = static fn(string $d): string => $d === 'ALLOW' ? 'A' : ($d === 'HARD_BLOCK' ? 'H' : 'S');
    foreach ($events as $ev) {
        $clock->setNow((new \DateTimeImmutable('@' . ($t0 + $ev['t'])))->setTimezone($clock->getTimezone()));
        $c = ci97Ctx($ev, 'login');
        $r = $runtime->limit($c, RateLimitCommand::checkOnly('login_protection'));
        $ch = $char($r->decision);
        $rc = '-';
        if ($ch === 'A') {
            $cmd = $ev['outcome'] === 'fail' ? RateLimitCommand::recordFailure('login_protection') : RateLimitCommand::recordSuccess('login_protection');
            $rc = $char($runtime->limit($c, $cmd)->decision);
        }
        $check .= $ch;
        $record .= $rc;
        $kinds .= $ev['kind'] === 'attack' ? 'X' : 'L';
        $whoIndex[(string) $ev['who']] ??= count($whoIndex);
        $who[] = $whoIndex[(string) $ev['who']];
    }
    emit('c02', $cell('bld', $stream, $arm, $strategy, 'pre+out', ['check' => $check, 'record' => $record, 'kinds' => $kinds, 'who' => $who], ['composition' => 'RateLimiterBuilder::fromFullCapabilityStore']));
}

// ---- POST-HOC V6D1W (not pre-registered): D1 with every user in a DISTINCT /32, so the IPv6 macro scopes do not aggregate the
// population the way the pre-registered V6D1 (one /48) does. Disclosure only. -------------------------------------------------------
$v6 = array_map(
    static fn(array $x, int $i): array => ['ip' => sprintf('2a0%x:%x::1', ($i >> 16) & 0xf, $i & 0xffff)] + $x,
    $streams['D1']['events'],
    array_keys($streams['D1']['events']),
);
foreach (['S0N', 'S0I', 'CIRI'] as $arm) {
    $events = ci97Materialise($v6, $arm, '-');
    $lab = ci97Lab($arm, 'login');
    $res = ci97Replay(static fn(int $i): Lab => $lab, $events, 'login', 'pre+out');
    emit('c02', $cell('posthoc_v6d1w', 'D1', $arm, '-', 'pre+out', $res, ['posthoc' => true]));
}
