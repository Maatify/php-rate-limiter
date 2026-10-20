<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\CgnModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CGN-B, the admission-layer MODEL of G4 v2 (LT-23 condition 4: a `MODEL`, reported as in the archive, not a gate), through the
 * production `AdmissionRuntime` (`CgnModel`). The reference is the archived FULL2 row of the same N, λ and sprayer
 * (`g4/integrated_v2/expected/v2_cgn.G4.jsonl`); equal field for field = reproduced, otherwise a measured difference.
 */
#[Group('archive')]
#[Group('lt23')]
final class AdmissionArchiveCgnModelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '1G');
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function cells(): iterable
    {
        foreach ([500, 5000] as $n) {
            foreach ([1, 4] as $lambda) {
                foreach ([false, true] as $spray) {
                    yield "N $n, lambda $lambda" . ($spray ? ', sprayer' : '') => [$n, $lambda, $spray];
                }
            }
        }
    }

    #[DataProvider('cells')]
    public function testTheCgnModelReproducesTheArchivedFull2Row(int $n, int $lambda, bool $spray): void
    {
        $archived = null;
        foreach (ArchiveData::expected('g4/integrated_v2/expected/v2_cgn.G4.jsonl') as $row) {
            if ($row['arm'] === 'FULL2' && $row['n'] === $n && $row['lambda'] === $lambda && $row['sprayer'] === $spray) {
                $archived = $row;
            }
        }
        self::assertNotNull($archived);

        // Compared in the archive's own encoding (JSON): the archive writes a zero share 0.0 as `0`.
        self::assertSame(
            json_encode(['days' => $archived['days'], 'sprayAdmitted' => $archived['sprayAdmitted'], 'boundAtEnd' => $archived['boundAtEnd']]),
            json_encode(CgnModel::run($n, $lambda, $spray)),
        );
    }
}
