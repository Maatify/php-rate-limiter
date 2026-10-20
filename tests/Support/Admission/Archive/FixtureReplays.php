<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * @phpstan-import-type DumpRow from ArchiveData
 * @phpstan-import-type Step from ReplaySession
 *
 * The fixture procedures of LT-23 (ruled) through the REAL engine. Each method is the port of one archived scenario block (cited);
 * its rows come from `fixtures.jsonl` (archive-dump.php), and the shape it returns is the archived `fx` row of the same fixture,
 * so the test can compare it field for field with the archive. Capacity causes are mapped to the study codes (`causeCode()`);
 * a denial the engine gives no capacity cause for keeps the engine's cause.
 */
final class FixtureReplays
{
    /** Production refusal cause => the study code of the archive. `proof_capacity` names any of the four proof counters. */
    public const CAUSES = [
        'source_capacity' => 'cap:src',
        'source_failure_cap' => 'cap:fail_src',
        'account_bucket' => 'cap:acct_tb',
        'new_device_cap' => 'cap:p_device',
        'source_proof_failure_cap' => 'cap:pfail_src',
        'proof_capacity' => 'cap:proof',
    ];

    /**
     * @param \Closure(): EngineLab $labFor
     * @param (\Closure(Step, ReplaySession): void)|null $observer called after every admitted attempt of every session
     * @param float $dropShare the NM-10 drop share of every session (§4 rule), 0 for the fixture gates
     */
    public function __construct(private readonly \Closure $labFor, private readonly ?\Closure $observer = null, private readonly float $dropShare = 0.0) {}

    public static function causeCode(string $cause): string
    {
        return self::CAUSES[$cause] ?? $cause;
    }

    /**
     * Study codes of the archive folded the way the engine reports them: the four proof counters are one cause. An archived empty
     * map is the JSON list `[]`.
     *
     * @param array<array-key, mixed> $byCause
     * @return array<string, int>
     */
    public static function archiveCauses(array $byCause): array
    {
        $out = [];
        foreach ($byCause as $code => $n) {
            if (! is_string($code) || ! is_int($n)) {
                throw new \UnexpectedValueException('A cause count is not a string => int pair.');
            }
            $code = in_array($code, ['cap:pacct', 'cap:pacct_day', 'cap:pproof', 'cap:pproof_day'], true) ? 'cap:proof' : $code;
            $out[$code] = ($out[$code] ?? 0) + $n;
        }
        ksort($out);

        return $out;
    }

    /**
     * Capacity causes only (`cap:*`), the comparable part: the engine's penalty causes are not the study's `pen:*` labels.
     *
     * @param array<string, int> $byCause
     * @return array{cap: array<string, int>, other: int}
     */
    public static function split(array $byCause): array
    {
        $cap = [];
        $other = 0;
        foreach ($byCause as $code => $n) {
            if (str_starts_with($code, 'cap:')) {
                $cap[$code] = $n;
            } else {
                $other += $n;
            }
        }
        ksort($cap);

        return ['cap' => $cap, 'other' => $other];
    }

    /**
     * @param array<string, int> $causes
     * @return array<string, int>
     */
    public static function mapped(array $causes): array
    {
        $out = [];
        foreach ($causes as $cause => $n) {
            $code = self::causeCode($cause);
            $out[$code] = ($out[$code] ?? 0) + $n;
        }
        ksort($out);

        return $out;
    }

    /** @param (\Closure(Step, ReplaySession): void)|null $observer */
    private function session(bool $binding = true, bool $renew = true, bool $passwordBinding = true, ?\Closure $observer = null): ReplaySession
    {
        return new ReplaySession(($this->labFor)(), 'pre+out', $this->dropShare, 1, 0.0, $renew, $passwordBinding, $observer ?? $this->observer, $binding);
    }

    /** @return list<DumpRow> */
    private static function rows(string $fixture, string $part = 'rows'): array
    {
        return ArchiveData::get()->fixtureRows($fixture, $part);
    }

    /**
     * @param DumpRow $template
     * @return DumpRow
     */
    private static function at(array $template, int $t): array
    {
        $template['t'] = $t;

        return $template;
    }

    /**
     * g4Row() (g4/integrated/lib/g4.php:310-315) of a whole-row fixture: HAR-B, HAR-N, CLEAR.
     *
     * @return array<string, mixed>
     */
    public function rowFixture(string $name, ?ReplaySession $session = null): array
    {
        $s = ($session ?? $this->session())->replay(self::rows($name));
        $r = $s->result();

        return ['m' => $r['m'], 'legitDeniedByCause' => self::mapped($r['causes']), 'attackDeniedByCause' => self::mapped($r['attackCauses']),
            'dec' => $r['dec'], 'reach' => $r['reach'], 'kinds' => $r['kinds'], 'lanes' => $r['lanes'], 'issued' => $r['issued'], 'tainted' => $r['tainted']];
    }

    /**
     * BRU24 / BRU24-HV (g5v2_fixtures.php:38-45): admissions, the independent recount of bucket takes, P lane, causes.
     *
     * @return array<string, mixed>
     */
    public function bru(bool $hv): array
    {
        $s = $this->session()->replay(ArchiveData::get()->bru($hv));
        $takes = count(array_filter($s->lab->admissionStore->admitted, static function ($request): bool {
            foreach ($request->scopes as $scope) {
                if ($scope->kind === AdmissionScopeKindEnum::BUCKET) {
                    return true;
                }
            }

            return false;
        }));
        $r = $s->result();

        return ['attackAdmitted' => substr_count($r['reach'], '1'), 'tbTakesRecount' => $takes, 'pLane' => substr_count($r['lanes'], 'P'),
            'attackDeniedByCause' => self::mapped($r['attackCauses'])];
    }

    /**
     * REC (g5v2_fixtures.php:46-61): after BRU to 1,800 s, the owner retries every 60 s from 1,785 + delta until admitted.
     *
     * @return array{waitFromArrival: array<int, ?int>}
     */
    public function rec(): array
    {
        $waits = [];
        $owner = ArchiveData::get()->fixtureRow('REC', 'owner');
        foreach ([0, 60, 300, 900, 1800, 3600] as $delta) {
            $s = $this->session()->replay(self::rows('REC'));
            $wait = null;
            for ($t = 1785 + $delta; $t <= 1785 + $delta + 7200; $t += 60) {
                if ($s->step(self::at($owner, $t))['d'] === 'A') {
                    $wait = $t - 1785 - $delta;
                    break;
                }
            }
            $waits[$delta] = $wait;
        }

        return ['waitFromArrival' => $waits];
    }

    /**
     * CROSS (g5v2_fixtures.php:62-74): one attacker token, 200 victim accounts.
     *
     * @return array{victimReach: int, victimPAdmitted: int}
     */
    public function cross(): array
    {
        $r = $this->session()->replay(self::rows('CROSS'))->result();
        $p = 0;
        for ($i = 0, $n = strlen($r['lanes']); $i < $n; $i++) {
            $p += $r['lanes'][$i] === 'P' && $r['reach'][$i] === '1' ? 1 : 0;
        }

        return ['victimReach' => substr_count($r['reach'], '1'), 'victimPAdmitted' => $p];
    }

    /**
     * The owner rows of a replay (g4v2Owner / g5Owner, g5fx.php:78-97): decisions, lanes and times of kind `O`.
     *
     * @param list<DumpRow> $rows
     * @return array{0: string, 1: string, 2: list<int>}
     */
    private static function owner(array $rows, ReplaySession $s): array
    {
        $dec = $lanes = '';
        $ts = [];
        $j = 0;
        foreach ($rows as $row) {
            if (isset($row['effects'])) {
                continue;
            }
            if (($row['kind'] ?? '') === 'O') {
                $dec .= $s->dec[$j];
                $lanes .= $s->lanes[$j];
                $ts[] = $row['t'];
            }
            $j++;
        }

        return [$dec, $lanes, $ts];
    }

    /**
     * E2 (g5v2_fixtures.php:86-103): the owner with and without the population of attackers on the same account.
     *
     * @return array<string, mixed>
     */
    public function e2(bool $renew = true): array
    {
        $out = [];
        foreach (['pop', 'ctl'] as $k) {
            $rows = self::rows('E2:' . $k);
            $s = $this->session(renew: $renew)->replay($rows);
            [$dec, $lanes] = self::owner($rows, $s);
            $out[$k] = ['ownerDec' => $dec, 'ownerLanes' => $lanes, 'ownerDeniedByCause' => self::mapped($s->result()['causes'])];
        }

        return $out + ['identical' => $out['pop']['ownerDec'] === $out['ctl']['ownerDec'] && $out['pop']['ownerLanes'] === $out['ctl']['ownerLanes']];
    }

    /**
     * K5U (g5v2_fixtures.php:104-117): the token reaches its absolute limit at 600 s; U failures at 630-870 s or not.
     *
     * @return array<string, mixed>
     */
    public function k5u(): array
    {
        $out = [];
        foreach (['fail', 'ctl'] as $k) {
            $rows = self::rows('K5U:' . $k);
            $s = $this->session()->replay($rows);
            [$dec, $lanes, $ts] = self::owner($rows, $s);
            $from = (int) array_search(960, $ts, true);
            $out[$k] = ['ownerDec' => $dec, 'ownerLanes' => $lanes, 'from960Dec' => substr($dec, $from), 'from960Lanes' => substr($lanes, $from),
                'ownerDeniedByCause' => self::mapped($s->result()['causes'])];
        }

        return $out + ['identicalFrom960' => $out['fail']['from960Dec'] === $out['ctl']['from960Dec'] && $out['fail']['from960Lanes'] === $out['ctl']['from960Lanes']];
    }

    /**
     * BOOT / BOOT-X (g5v2_fixtures.php:118-151): the arm against a Host that never binds (the study's `R-NO-BIND`, Host mode
     * `off`), per day, the bound refusals and the detector count (day 0, bound here, refused without binding).
     *
     * @return array<string, mixed>
     */
    public function boot(string $name): array
    {
        $rows = self::rows($name);
        $full = $this->session()->replay($rows);
        $noBind = $this->session(binding: false)->replay($rows);
        $plain = array_values(array_filter($rows, static fn(array $r): bool => ! isset($r['effects'])));
        $perDay = [];
        $boundRefused = 0;
        $detector = 0;
        $attackReach = 0;
        foreach ($plain as $i => $row) {
            if (($row['kind'] ?? '') === 'X') {
                $attackReach += $full->reach[$i] === '1' ? 1 : 0;
                continue;
            }
            $d = intdiv($row['t'], 86400);
            foreach (['FULL2' => $full, 'NO-BIND' => $noBind] as $key => $s) {
                $perDay[$key][$d]['n'] = ($perDay[$key][$d]['n'] ?? 0) + 1;
                $perDay[$key][$d]['refused'] = ($perDay[$key][$d]['refused'] ?? 0) + ($s->dec[$i] !== 'A' ? 1 : 0);
            }
            $boundRefused += $full->lanes[$i] === 'P' && $full->dec[$i] !== 'A' ? 1 : 0;
            $detector += $d === 0 && $full->lanes[$i] === 'P' && $noBind->dec[$i] !== 'A' ? 1 : 0;
        }

        return ['perDay' => $perDay, 'fullBoundRefused' => $boundRefused, 'noBindRefusedWhereFullBound' => $detector,
            'fullIssued' => $full->host->issued, 'fullCauses' => self::mapped($full->result()['causes']),
            'noBindCauses' => self::mapped($noBind->result()['causes']), 'fullAttackReach' => $attackReach];
    }

    /**
     * UAX-NP (g5v2_fixtures.php:153-161): twelve logins, one id and token, a new browser major each time.
     *
     * @return array<string, mixed>
     */
    public function uaxNp(): array
    {
        $r = $this->session()->replay(self::rows('UAX-NP'))->result();

        return ['lanes' => $r['lanes'], 'dec' => $r['dec'], 'refused' => strlen($r['dec']) - substr_count($r['dec'], 'A'), 'causes' => self::mapped($r['causes'])];
    }

    /**
     * FORGE (g5v2_fixtures.php:163-179): the owner's token presented with a fresh id per attempt.
     *
     * @return array<string, int>
     */
    public function forge(): array
    {
        $r = $this->session()->replay(self::rows('FORGE'))->result();
        $p = $u = 0;
        for ($i = 0, $n = strlen($r['lanes']); $i < $n; $i++) {
            $p += $r['lanes'][$i] === 'P' && $r['reach'][$i] === '1' ? 1 : 0;
            $u += $r['lanes'][$i] === 'U' && $r['reach'][$i] === '1' ? 1 : 0;
        }

        return ['attempts' => strlen($r['lanes']), 'thiefPAdmitted' => $p, 'thiefUReach' => $u];
    }

    /**
     * MINT (g5v2_fixtures.php:181-188): a Host-known device, no token, a fresh id per attempt.
     *
     * @return array<string, int>
     */
    public function mint(): array
    {
        $r = $this->session()->replay(self::rows('MINT'))->result();

        return ['attempts' => strlen($r['lanes']), 'reach' => substr_count($r['reach'], '1'), 'pLane' => substr_count($r['lanes'], 'P')];
    }

    /**
     * LOST / TOKEN (g5v2_fixtures.php:190-203).
     *
     * @return array{byInterval: array<int, array{reach: int, pLane: int}>}
     */
    public function lostToken(string $name): array
    {
        $out = [];
        foreach ([600, 300, 60] as $iv) {
            $r = $this->session()->replay(self::rows($name . ':' . $iv))->result();
            $out[$iv] = ['reach' => substr_count($r['reach'], '1'), 'pLane' => substr_count($r['lanes'], 'P')];
        }

        return ['byInterval' => $out];
    }

    /**
     * UAX-ASM (g5v2_fixtures.php:205-216).
     *
     * @return array<string, mixed>
     */
    public function uaxAsm(): array
    {
        $out = [];
        foreach (['flip', 'ctl'] as $k) {
            $r = $this->session()->replay(self::rows('UAX-ASM:' . $k))->result();
            $out[$k] = ['dec' => $r['dec'], 'lanes' => $r['lanes']];
        }

        return $out + ['identical' => $out['flip'] === $out['ctl']];
    }

    /**
     * MIXPRES-ASM (g5v2_fixtures.php:218-243), without the study-key K5 hashes.
     *
     * @return array<string, mixed>
     */
    public function mixpres(): array
    {
        $out = [];
        foreach (['both', 'ctl'] as $k) {
            $rows = self::rows('MIXPRES-ASM:' . $k);
            $s = $this->session()->replay($rows);
            $aDec = '';
            $j = 0;
            foreach ($rows as $row) {
                if (isset($row['effects'])) {
                    continue;
                }
                $aDec .= ($row['who'] ?? '') === 'a' ? $s->dec[$j] : '';
                $j++;
            }
            $out[$k] = ['aDec' => $aDec, 'allDec' => $s->dec];
        }

        return $out + ['aIdentical' => $out['both']['aDec'] === $out['ctl']['aDec']];
    }

    /**
     * STEAL, thief side, password-change variant (g7/cross_account_p/lib/g7.php:165-224; = g5fx.php:127-158): the thief holds a copy
     * of the owner's jar from 600 s; the owner changes the password from the bound device at 43,200 s and retries every 60 s from
     * 43,260 s until admitted; the thief keeps failing every 15 s.
     *
     * @param (\Closure(Step, ReplaySession): void)|null $observer
     * @return array<string, mixed>
     */
    public function steal(bool $passwordBinding = true, ?\Closure $observer = null): array
    {
        $data = ArchiveData::get();
        $s = $this->session(passwordBinding: $passwordBinding, observer: $observer);
        $win = [];
        $day = 0;
        $ownerDen = 0;
        $pre = $data->fixtureRows('STEAL', 'pre');
        ReplaySession::assertInput($pre);
        foreach ($pre as $row) {
            if (isset($row['effects'])) {
                $s->event($row['effects'], $row['t']);
                continue;
            }
            $step = $s->step($row);
            $holder = $row['holder'] ?? '';
            if ($holder === 'thief' && $step['lane'] === 'P' && $step['reach'] === '1') {
                $win[intdiv($row['t'], 600)] = ($win[intdiv($row['t'], 600)] ?? 0) + 1;
                $day++;
            }
            $ownerDen += $holder === 'owner' && $step['d'] !== 'A' ? 1 : 0;
        }
        $change = $data->fixtureRow('STEAL', 'change');
        $s->event($change['effects'] ?? [], $change['t']);
        $afterP = 0;
        $afterU = 0;
        $ownerWait = null;
        $ownerLane = null;
        $ownerCauses = [];
        $next = 43260;
        $after = $data->fixtureRows('STEAL', 'after');
        $ownerRow = $data->fixtureRow('STEAL', 'owner');
        ReplaySession::assertInput($after);
        foreach ($after as $row) {
            $t = $row['t'];
            $step = $s->step($row);
            if ($step['lane'] === 'P' && $step['reach'] === '1') {
                $afterP++;
                $win[intdiv($t, 600)] = ($win[intdiv($t, 600)] ?? 0) + 1;
            }
            $afterU += $step['lane'] === 'U' && $step['reach'] === '1' ? 1 : 0;
            if ($ownerWait === null && $next === $t) {
                $o = $s->step(self::at($ownerRow, $next));
                if ($o['d'] === 'A') {
                    $ownerWait = $next - 43260;
                    $ownerLane = $o['lane'];
                } else {
                    $code = self::causeCode($o['cause']);
                    $ownerCauses[$code] = ($ownerCauses[$code] ?? 0) + 1;
                    $next += 60;
                }
            }
        }
        ksort($ownerCauses);

        return ['thiefPMaxPerWindow' => $win === [] ? 0 : max($win), 'thiefPBeforeChange' => $day, 'thiefPAfterChange' => $afterP,
            'thiefUAfterChange' => $afterU, 'ownerDeniedBeforeChange' => $ownerDen, 'ownerWaitAfterChange' => $ownerWait,
            'ownerLaneAtAdmission' => $ownerLane, 'ownerDeniedAfterChangeByCause' => $ownerCauses, 'pwBinds' => $s->host->pwBinds];
    }
}
