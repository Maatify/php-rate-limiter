<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Tests\Support\Admission\FamilyFixtures;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate RP-G5, pipeline part (5b3a/PLAN.md section 5; contract section 8.2).
 *
 * Every NOUA2 row of the archived G5 v2 pipeline-level run is reproduced by the real pipeline, field for field, on the
 * proven and on the unproven lane. The expected rows are read from `g5/identity_layer_v2/expected/` at test time.
 * Parts of RP-G5 that need a reservation (`UAX-NP`, `D-IDMINT`, `BRU24`, `FORGE`, `MINT`, `STEAL`, `NP-IN`) are 5-b3b.
 */
#[Group('rp-g5')]
final class FamilyRpG5Test extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function rows(): iterable
    {
        foreach (['P', 'U'] as $lane) {
            foreach (FamilyFixtures::FIXTURES as $fixture) {
                yield "{$lane} {$fixture}" => [$lane, $fixture];
            }
        }
    }

    private static function lane(string $name): AdmissionLaneEnum
    {
        return AdmissionLaneEnum::from($name);
    }

    #[DataProvider('rows')]
    public function testTheArchivedNoua2RowIsReproducedFieldForField(string $lane, string $fixture): void
    {
        $archive = FamilyFixtures::archive('g5v2_pipeline.G5V2.jsonl');
        self::assertCount(28, $archive);

        self::assertSame(
            $archive["{$lane}|{$fixture}"],
            FamilyFixtures::normalize(FamilyFixtures::run($fixture, self::lane($lane))),
        );
    }

    public function testStableAttemptReachOnTheProvenLaneEqualsTheS0ValuesOfTheArchive(): void
    {
        // G3 KDP item 1: 45 / 44 / 45 on P (S0 and the study's reference give the same numbers).
        $reach = [];
        foreach (['STABLE-600', 'STABLE-300', 'STABLE-60'] as $fixture) {
            $reach[$fixture] = FamilyFixtures::run($fixture, AdmissionLaneEnum::P)['reach'];
        }

        self::assertSame(['STABLE-600' => 45, 'STABLE-300' => 44, 'STABLE-60' => 45], $reach);
    }

    #[DataProvider('lanes')]
    public function testABrowserUpdateIsTheSameDeviceForK5(string $lane): void
    {
        // G1 UAX items 1-4: UAC12 equals KDL, one K5 key, one allowance counter, UAX24 reach equals its control.
        $kdl = FamilyFixtures::run('KDL', self::lane($lane));
        $uac = FamilyFixtures::run('UAC12', self::lane($lane));
        $uax = FamilyFixtures::run('UAX24', self::lane($lane));

        self::assertSame($kdl['dec'], $uac['dec']);
        self::assertTrue($uac['k5SameKey']);
        foreach (['k5key', 'allowKey'] as $field) {
            self::assertSame(self::part($uac, 'before')[$field], self::part($uac, 'after')[$field]);
        }
        self::assertSame(self::part($kdl, 'after')['allowance'], self::part($uac, 'after')['allowance']);
        self::assertSame($uax['ctl'], $uax['flip']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function part(array $row, string $name): array
    {
        $part = $row[$name] ?? null;
        self::assertIsArray($part);

        return FamilyFixtures::normalize($part);
    }

    /** @return iterable<string, array{0: string}> */
    public static function lanes(): iterable
    {
        yield 'P' => ['P'];
        yield 'U' => ['U'];
    }

    public function testRowsWithoutAnIdEqualTheNoIdReferenceOfTheArchive(): void
    {
        // G5 ABS: the no-id path is today's behaviour; NOID-UAC12 differs from KDL as DEC-021 ships it.
        foreach (['P', 'U'] as $lane) {
            $uac = FamilyFixtures::run('NOID-UAC12', self::lane($lane));
            $kdl = FamilyFixtures::run('NOID-KDL', self::lane($lane));

            self::assertNotSame($kdl['dec'], $uac['dec']);
            self::assertFalse($uac['k5SameKey']);
        }
    }

    public function testNoAllowanceDetectorFiresAndReproducesTheArchivedAblation(): void
    {
        // Detector: without the known-device allowance STABLE reach on P is 128 / 83 / 76 (archived D-NOALW).
        $mutant = PipelineMutant::pipeline(exact: [[
            '&& ($allowanceLimit = $policy->getKnownDeviceAccountAllowance()) !== null) {',
            '&& ($allowanceLimit = null) !== null) {',
        ]]);
        $archive = FamilyFixtures::archive('g5v2_pipeline.G5V2-NOALW.jsonl');

        $reach = [];
        foreach (['STABLE-600', 'STABLE-300', 'STABLE-60'] as $fixture) {
            $row = FamilyFixtures::normalize(FamilyFixtures::run($fixture, AdmissionLaneEnum::P, $mutant));
            self::assertSame($archive["P|{$fixture}"], $row);
            $reach[] = $row['reach'];
        }

        self::assertSame([128, 83, 76], $reach);
    }

    public function testNoK5DetectorFiresAndReproducesTheArchivedAblation(): void
    {
        // Detector: without K5 scoring every attempt of KDL is admitted (archived D-NOK5).
        $mutant = PipelineMutant::pipeline(exact: [[
            "\$result['k5'] = \$deltasDto->k5_failure;",
            "\$result['k5'] = 0;",
        ]]);
        $archive = FamilyFixtures::archive('g5v2_pipeline.G5V2-NOK5.jsonl');

        foreach (['P', 'U'] as $lane) {
            $row = FamilyFixtures::normalize(FamilyFixtures::run('KDL', self::lane($lane), $mutant));
            self::assertSame($archive["{$lane}|KDL"], $row);
            self::assertSame('AAAAAAAAAAAA', $row['dec']);
        }
    }

    public function testNoOverflowDetectorFiresAndReproducesTheArchivedAblation(): void
    {
        // Detector: without the K4 overflow of an exhausted allowance STABLE reach on P equals the no-allowance one.
        $mutant = PipelineMutant::pipeline(exact: [[
            "                \$deltas['k4'] = \$policy->getScoreDeltas()->k4_failure;\n",
            "",
        ]]);
        $archive = FamilyFixtures::archive('g5v2_pipeline.G5V2-NOOVF.jsonl');

        foreach (['STABLE-600', 'STABLE-300', 'STABLE-60'] as $fixture) {
            $row = FamilyFixtures::normalize(FamilyFixtures::run($fixture, AdmissionLaneEnum::P, $mutant));
            self::assertSame($archive["P|{$fixture}"], $row);
            self::assertGreaterThan(45, $row['reach']);
        }
    }

    public function testNoDeviceWatcherDetectorFiresAndReproducesTheArchivedAblation(): void
    {
        // Detector: without the device Anti-Equilibrium watcher the main device is not escalated (archived D-NOAE).
        $mutant = PipelineMutant::pipeline(exact: [[
            "        // Same eligibility as the account watcher (a budgeted policy), and\n        // only where a K5 level can exist: never for API_OVERUSE.\n",
            "        if (true) {\n            return null;\n        }\n",
        ]]);
        $archive = FamilyFixtures::archive('g5v2_pipeline.G5V2-NOAE.jsonl');
        $reference = FamilyFixtures::archive('g5v2_pipeline.G5V2.jsonl');

        foreach (['P', 'U'] as $lane) {
            $row = FamilyFixtures::normalize(FamilyFixtures::run('AE', self::lane($lane), $mutant));
            self::assertSame($archive["{$lane}|AE"], $row);
            self::assertNotSame($reference["{$lane}|AE"], $row, 'The ablation must change the row (U: the decision string, P: the second device).');
        }
    }

    public function testK5OnTheFingerprintFailsTheBrowserUpdateGate(): void
    {
        // Detector (material): with the K5 material taken from the fingerprint, UAC12 is no longer KDL.
        $mutant = PipelineMutant::pipeline(methodBodies: [
            'k5Material' => 'return $device->fingerprintHash;',
            'previousK5Material' => 'return $device->previousFingerprintHash ?? $device->fingerprintHash;',
        ]);
        $archive = FamilyFixtures::archive('g5v2_pipeline.G5V2.jsonl');

        foreach (['P', 'U'] as $lane) {
            $kdl = FamilyFixtures::run('KDL', self::lane($lane), $mutant);
            $uac = FamilyFixtures::run('UAC12', self::lane($lane), $mutant);

            self::assertNotSame($kdl['dec'], $uac['dec']);
            self::assertSame($archive["{$lane}|NOID-UAC12"]['dec'], $uac['dec']);
        }
    }
}
