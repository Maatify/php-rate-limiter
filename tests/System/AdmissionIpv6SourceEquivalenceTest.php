<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamReplay;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type DumpRow from ArchiveData
 *
 * Stage 5-c, LT-26 (5c/PLAN.md §7.6.3): the source of the admission layer is one IPv4 address or one IPv6 `/64`. The archived
 * G4 v2 `BRD` and `X2` tuples (`pre+out`, the `FULL2` lab of RP-G4) replayed with their dumped sources and with each distinct
 * source mapped to its own `/64` inside `2001:db8:aaaa::/48` give the same rows. This proves the `/64` equivalence only;
 * the IPv6 `/48` hierarchy stays `UNPROVEN` (security feasibility §5 row 6). Detector: a runtime whose source is the `/48`.
 */
#[Group('archive')]
#[Group('lt-26')]
final class AdmissionIpv6SourceEquivalenceTest extends TestCase
{
    private const FIELDS = ['dec', 'reach', 'kinds', 'lanes', 'm'];

    /** @return list<array{name: string, rows: list<DumpRow>}> */
    private static function tuples(): array
    {
        $out = [];
        foreach (ArchiveData::get()->streams() as $t) {
            if (in_array($t['stream'], ['BRD', 'X2'], true)) {
                $out[] = ['name' => $t['stream'] . '|' . $t['strategy'] . '|' . $t['ids'], 'rows' => $t['rows']];
            }
        }
        self::assertNotEmpty($out);

        return $out;
    }

    /**
     * Every distinct dumped source (an IPv4 address for `BRD`, a study label `atk1` … for `X2`), in order of first appearance,
     * to `2001:db8:aaaa:{n}::1`, `n` its index in hexadecimal: injective, one `/64` each, all inside one `/48`.
     *
     * @param list<DumpRow> $rows
     * @return list<DumpRow>
     */
    private static function toIpv6(array $rows): array
    {
        $map = [];
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['ctx'])) {
                $ip = $row['ctx']['ip'];
                $map[$ip] ??= sprintf('2001:db8:aaaa:%x::1', count($map));
                $row['ctx']['ip'] = $map[$ip];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<DumpRow> $rows
     * @param class-string|null $runtimeClass
     * @return array<string, mixed>
     */
    private static function replay(array $rows, ?string $runtimeClass = null): array
    {
        $lab = $runtimeClass === null
            ? new EngineLab(StreamReplay::fullBudgets())
            : new EngineLab(StreamReplay::fullBudgets(), runtimeClass: $runtimeClass);

        return array_intersect_key(StreamReplay::run($rows, 'pre+out', $lab), array_flip(self::FIELDS));
    }

    public function testBrdAndX2GiveTheSameRowsFromIpv6Slash64SourcesAsFromIpv4Sources(): void
    {
        $diff = [];
        foreach (self::tuples() as $t) {
            if (self::replay($t['rows']) !== self::replay(self::toIpv6($t['rows']))) {
                $diff[] = $t['name'];
            }
        }

        self::assertSame([], $diff);
    }

    public function testDetectorASourceOnTheSlash48ChangesAtLeastOneRow(): void
    {
        $mutant = ServiceMutant::load('AdmissionRuntime', [], [
            ['$source = SourcePrefix::of($context->ip);', '$source = SourcePrefix::of($context->ip, 48);'],
        ]);
        $differs = 0;
        foreach (self::tuples() as $t) {
            $differs += self::replay($t['rows']) !== self::replay(self::toIpv6($t['rows']), $mutant) ? 1 : 0;
        }

        self::assertGreaterThan(0, $differs, 'DETECTOR: one /48 for every source must change a row');
    }
}
