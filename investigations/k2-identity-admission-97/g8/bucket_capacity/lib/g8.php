<?php

declare(strict_types=1);

/*
 * G8 bucket capacity (#97, PLAN.md v1) — the replay library.
 *
 * Production composition only: every request is decided by the 5-b3b `EngineLab` (the real engine, pipeline, resolver and
 * `AdmissionRuntime` over the in-memory reference admission store), replayed by `ReplaySession` and `FixtureReplays`, used unchanged.
 * The value of `C` reaches the runtime only through the `AdmissionBudgetsDTO` built here. Nothing in this file decides a request.
 */

namespace G8;

use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\FixtureReplays;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ReplaySession;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

const ARMS = ['FULL2', 'PX4'];
/** The values of `C` (PLAN.md §2); 1,000,000 is the detector `D-NOTB` only. */
const CS = [2, 3, 4, 1_000_000];
const DETECTOR_C = 1_000_000;
const REFERENCE_C = 4;

/**
 * Production refusal cause => one character of `cz` (PLAN.md §3). `.` = not refused; `?` = any cause outside this map.
 */
const CZ = [
    'source_capacity' => 's',
    'source_failure_cap' => 'f',
    'account_bucket' => 'b',
    'proof_capacity' => 'p',
    'new_device_cap' => 'd',
    'source_proof_failure_cap' => 'x',
    'soft' => 'S',
    'hard' => 'H',
    'penalty' => 'N',
];

/** PLAN.md §2: `FULL2` = the G4 v2 budgets without D8 (`StreamReplay::fullBudgets()`), `PX4` = the DEC-025 budgets; only `C` varies. */
function budgets(string $arm, int $c): AdmissionBudgetsDTO
{
    return match ($arm) {
        'FULL2' => EngineLab::budgets(bucketTakes: $c, crossAccountFailureCap: 1_000_000),
        'PX4' => EngineLab::budgets(bucketTakes: $c),
        default => throw new \LogicException("Unknown arm {$arm}."),
    };
}

function lab(string $arm, int $c): EngineLab
{
    return new EngineLab(budgets($arm, $c));
}

/** @param array{d: string, cause: string} $step */
function causeChar(array $step): string
{
    if ($step['d'] === 'A') {
        return '.';
    }
    if (str_starts_with($step['cause'], 'permit_')) {
        return 'M';
    }

    return CZ[$step['cause']] ?? '?';
}

/**
 * @param array<string, int> $causes production causes
 * @return array<string, int> study codes (`FixtureReplays::causeCode`), sorted
 */
function mapped(array $causes): array
{
    $out = [];
    foreach ($causes as $cause => $n) {
        $code = FixtureReplays::causeCode($cause);
        $out[$code] = ($out[$code] ?? 0) + $n;
    }
    ksort($out);

    return $out;
}

/**
 * One stream tuple in one flow: `ReplaySession::replay()` written out row by row (IN on the whole tuple first, then on every row by
 * `step()`) so that the cause of every request is kept in `cz`; the extra measures are those of `AdmissionArchiveG7Test::replay()`
 * (`refusedPx`, and `g6TmsRow`, g6/budgets/lib/g6.php:345-362).
 *
 * @param array{stream: string, strategy: string, ids: string, rows: list<array<string, mixed>>} $tuple
 * @return array<string, mixed>
 */
function streamRow(array $tuple, string $flow, EngineLab $lab): array
{
    $rows = $tuple['rows'];
    ReplaySession::assertInput($rows);
    $s = new ReplaySession($lab, $flow);
    $cz = '';
    foreach ($rows as $row) {
        if (isset($row['effects'])) {
            $s->event($row['effects'], $row['t']);
            continue;
        }
        $cz .= causeChar($s->step($row));
    }
    $r = $s->result();
    $plain = array_values(array_filter($rows, static fn(array $x): bool => ! isset($x['effects'])));
    $byLane = ['U' => 0, 'P' => 0];
    $first600 = 0;
    $acct = [];
    for ($i = 0, $n = strlen($r['reach']); $i < $n; $i++) {
        if ($r['reach'][$i] !== '1') {
            continue;
        }
        $byLane[$r['lanes'][$i]]++;
        $first600 += $plain[$i]['t'] < 600 ? 1 : 0;
        $a = $plain[$i]['ctx']['accountId'] ?? '';
        $acct[$a] = ($acct[$a] ?? 0) + 1;
    }
    $legit = mapped($r['causes']);
    $attack = mapped($r['attackCauses']);
    unset($s);

    return ['dec' => $r['dec'], 'reach' => $r['reach'], 'kinds' => $r['kinds'], 'lanes' => $r['lanes'], 'cz' => $cz, 'm' => $r['m'],
        'issued' => $r['issued'], 'tainted' => $r['tainted'], 'legitDeniedByCause' => $legit, 'attackDeniedByCause' => $attack,
        'refusedPx' => ($legit['cap:pfail_src'] ?? 0) + ($attack['cap:pfail_src'] ?? 0),
        'reachU' => $byLane['U'], 'reachP' => $byLane['P'], 'reachFirst600' => $first600,
        'reachPerAccountMax' => $acct === [] ? 0 : max($acct), 'accountsReached' => count($acct)];
}

/**
 * Every stream tuple of the dump (`streams`: the 22 G3 streams; `g7`: N12T, TPAIRT, TMS, TMSd, TMX), flows `pre+out` and `pre`.
 *
 * @return \Generator<int, array<string, mixed>>
 */
function streamRows(string $arm, int $c): \Generator
{
    $data = ArchiveData::get();
    foreach (['streams' => $data->streams(), 'g7' => $data->g7Streams()] as $set => $tuples) {
        foreach ($tuples as $t) {
            foreach (['pre+out', 'pre'] as $flow) {
                yield ['s' => 'st', 'C' => $c, 'arm' => $arm, 'set' => $set, 'stream' => $t['stream'], 'strategy' => $t['strategy'],
                    'ids' => $t['ids'], 'flow' => $flow] + streamRow($t, $flow, lab($arm, $c));
                gc_collect_cycles();
            }
        }
    }
}

/**
 * LOST / TOKEN detail (PLAN.md §3, F-G5-04): the `FixtureReplays` session (its defaults) over `LOST:i` / `TOKEN:i`.
 *
 * @return array<int, array<string, mixed>>
 */
function lostDetail(string $name, string $arm, int $c): array
{
    $out = [];
    foreach ([600, 300, 60] as $iv) {
        $rows = ArchiveData::get()->fixtureRows($name . ':' . $iv);
        ReplaySession::assertInput($rows);
        $s = new ReplaySession(lab($arm, $c), 'pre+out', 0.0, 1, 0.0, true, true, null, true);
        $first = null;
        $refused = [600 => 0, 1800 => 0, 86400 => 0];
        foreach ($rows as $row) {
            if (isset($row['effects'])) {
                $s->event($row['effects'], $row['t']);
                continue;
            }
            $step = $s->step($row);
            if ($step['d'] === 'A') {
                continue;
            }
            $first ??= $row['t'];
            foreach (array_keys($refused) as $limit) {
                $refused[$limit] += $row['t'] < $limit ? 1 : 0;
            }
        }
        $r = $s->result();
        $out[$iv] = ['requests' => strlen($r['dec']), 'reach' => substr_count($r['reach'], '1'), 'pLane' => substr_count($r['lanes'], 'P'),
            'firstRefusalT' => $first, 'refusedFirst600' => $refused[600], 'refusedFirst1800' => $refused[1800], 'refused24h' => $refused[86400],
            'causes' => mapped($r['causes'])];
        unset($s);
        gc_collect_cycles();
    }

    return $out;
}

/**
 * Every `FixtureReplays` procedure (PLAN.md §3), unchanged, then the LOST / TOKEN detail.
 *
 * @return \Generator<int, array<string, mixed>>
 */
function fixtureRows(string $arm, int $c): \Generator
{
    $r = new FixtureReplays(static fn(): EngineLab => lab($arm, $c));
    $head = static fn(string $fixture): array => ['s' => 'fx', 'C' => $c, 'arm' => $arm, 'fixture' => $fixture];
    foreach (['HAR-B', 'HAR-N', 'CLEAR'] as $f) {
        yield $head($f) + $r->rowFixture($f);
    }
    yield $head('BRU24') + $r->bru(false);
    yield $head('BRU24-HV') + $r->bru(true);
    yield $head('REC') + $r->rec();
    yield $head('CROSS') + $r->cross();
    yield $head('E2') + $r->e2();
    yield $head('K5U') + $r->k5u();
    yield $head('BOOT') + $r->boot('BOOT');
    yield $head('BOOT-X') + $r->boot('BOOT-X');
    yield $head('STEAL') + $r->steal();
    yield $head('UAX-NP') + $r->uaxNp();
    yield $head('FORGE') + $r->forge();
    yield $head('MINT') + $r->mint();
    yield $head('LOST') + $r->lostToken('LOST');
    yield $head('TOKEN') + $r->lostToken('TOKEN');
    yield $head('UAX-ASM') + $r->uaxAsm();
    yield $head('MIXPRES-ASM') + $r->mixpres();
    foreach (['LOST', 'TOKEN'] as $name) {
        yield ['s' => 'lost', 'C' => $c, 'arm' => $arm, 'fixture' => $name, 'byInterval' => lostDetail($name, $arm, $c)];
    }
}

/** @param array<string, mixed> $row */
function emit($handle, array $row): void
{
    fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
}
