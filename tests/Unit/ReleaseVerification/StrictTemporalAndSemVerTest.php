<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceFixtures;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\QualificationEvidenceSchema;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseContract;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseRepositoryFixture as Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F94-05 (strict, non-normalising temporal parsing) and F94-06 (one canonical exact SemVer
 * validator shared by RAV, the evidence schema and the Decision version-scope parser).
 */
final class StrictTemporalAndSemVerTest extends TestCase
{
    // ------------------------------------------------------------ F94-05: timestamps

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function timestamps(): iterable
    {
        yield 'valid' => ['2026-12-31T23:59:59Z', true];
        yield 'valid leap day' => ['2028-02-29T00:00:00Z', true];
        yield 'valid midnight' => ['2026-01-01T00:00:00Z', true];
        yield 'february 31' => ['2026-02-31T00:00:00Z', false];
        yield 'april 31' => ['2026-04-31T00:00:00Z', false];
        yield 'non-leap february 29' => ['2026-02-29T00:00:00Z', false];
        yield 'century non-leap february 29' => ['2100-02-29T00:00:00Z', false];
        yield 'month 13' => ['2026-13-01T00:00:00Z', false];
        yield 'month 00' => ['2026-00-10T00:00:00Z', false];
        yield 'day 00' => ['2026-01-00T00:00:00Z', false];
        yield 'hour 24' => ['2026-01-01T24:00:00Z', false];
        yield 'minute 60' => ['2026-01-01T23:60:00Z', false];
        yield 'leap second' => ['2026-01-01T23:59:60Z', false];
        yield 'space separator' => ['2026-01-01 00:00:00Z', false];
        yield 'numeric offset' => ['2026-01-01T00:00:00+00:00', false];
        yield 'fractional seconds' => ['2026-01-01T00:00:00.123Z', false];
        yield 'lowercase z' => ['2026-01-01T00:00:00z', false];
        yield 'missing Z' => ['2026-01-01T00:00:00', false];
        yield 'trailing newline' => ["2026-01-01T00:00:00Z\n", false];
        yield 'leading space' => [' 2026-01-01T00:00:00Z', false];
        yield 'date only' => ['2026-01-01', false];
        yield 'free text' => ['yesterday', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('timestamps')]
    public function testCanonicalTimestampParserIsStrictAndNeverNormalises(string $value, bool $valid): void
    {
        $parsed = ReleaseContract::parseTimestamp($value);

        if ($valid) {
            self::assertNotNull($parsed, $value);
            self::assertSame($value, gmdate('Y-m-d\TH:i:s\Z', $parsed), 'exact round trip');
        } else {
            self::assertNull($parsed, $value);
        }
    }

    // ------------------------------------------------------------ F94-05: date-only

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function effectiveDates(): iterable
    {
        yield 'valid date' => ['2026-12-31', '2026-12-31T23:59:59Z'];
        yield 'valid leap day' => ['2028-02-29', '2028-02-29T23:59:59Z'];
        yield 'valid february end' => ['2026-02-28', '2026-02-28T23:59:59Z'];
        yield 'february 31' => ['2026-02-31', null];
        yield 'april 31' => ['2026-04-31', null];
        yield 'non-leap february 29' => ['2026-02-29', null];
        yield 'month 13' => ['2026-13-01', null];
        yield 'day 00' => ['2026-01-00', null];
        yield 'trailing newline' => ["2026-01-01\n", null];
        yield 'slashes' => ['2026/01/01', null];
        yield 'full timestamp is accepted as itself' => ['2026-01-01T10:00:00Z', '2026-01-01T10:00:00Z'];
        yield 'impossible full timestamp' => ['2026-02-31T10:00:00Z', null];
        yield 'free text' => ['last Tuesday', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('effectiveDates')]
    public function testDateOnlyEffectiveInstantIsValidatedThenResolvedToEndOfUtcDay(string $value, ?string $expected): void
    {
        $instant = ReleaseContract::parseEffectiveInstant($value);

        if ($expected === null) {
            self::assertNull($instant, $value);
        } else {
            self::assertNotNull($instant, $value);
            self::assertSame($expected, gmdate('Y-m-d\TH:i:s\Z', $instant));
        }
    }

    // ------------------------------------------------------------ F94-05: every consuming boundary

    public function testSchemaRejectsImpossibleDatesInEveryTemporalField(): void
    {
        foreach (['qualification_started_at', 'qualified_at'] as $field) {
            $evidence = EvidenceFixtures::validDist();
            $evidence[$field] = '2026-02-31T00:00:00Z';
            self::assertNotSame([], QualificationEvidenceSchema::validate($evidence), $field);
        }

        $evidence = EvidenceFixtures::validDist();
        $review = $evidence['semantic_review'];
        self::assertIsArray($review);
        $review['reviewed_at'] = '2026-02-31T00:00:00Z';
        $evidence['semantic_review'] = $review;
        self::assertNotSame([], QualificationEvidenceSchema::validate($evidence));
    }

    public function testSchemaRejectsImpossibleOwnerApprovalDatesAndInconsistentEffectiveInstant(): void
    {
        $valid = EvidenceFixtures::validSourceOnly();
        self::assertSame([], QualificationEvidenceSchema::validate($valid));

        foreach (['approval_date', 'effective_date'] as $field) {
            $evidence = $valid;
            $decision = $evidence['source_only_decision'];
            self::assertIsArray($decision);
            $owner = $decision['owner_approval'];
            self::assertIsArray($owner);
            $owner[$field] = '2026-02-31';
            $decision['owner_approval'] = $owner;
            $evidence['source_only_decision'] = $decision;

            self::assertStringContainsString('strictly valid calendar', implode('|', QualificationEvidenceSchema::validate($evidence)), $field);
        }

        $evidence = $valid;
        $decision = $evidence['source_only_decision'];
        self::assertIsArray($decision);
        $decision['effective_at'] = '2026-09-30T23:59:59Z';
        $evidence['source_only_decision'] = $decision;
        self::assertStringContainsString('does not equal the later', implode('|', QualificationEvidenceSchema::validate($evidence)));
    }

    public function testRavSemanticReviewRejectsImpossibleReviewedAt(): void
    {
        $repo = new Fx();
        try {
            $sha = str_repeat('a', 40);
            $file = $repo->semanticReviewFile('1.0.0-rc.3', $sha, '2026-02-31T00:00:00Z');

            $r = (new ReleaseArtifactVerifier())->evaluateSemanticReviewEvidence($file, '1.0.0-rc.3', $sha, '2026-10-06T20:00:00Z');

            self::assertSame('FAIL', $r['status']);
            self::assertStringContainsString('strict UTC timestamp', $r['message']);
        } finally {
            $repo->cleanup();
        }
    }

    public function testSourceOnlyDecisionRejectsImpossibleOwnerDates(): void
    {
        foreach (['2026-02-31', '2026-04-31', '2026-02-29'] as $date) {
            $repo = new Fx();
            $repo->populateRelease('1.0.0-rc.3');
            $repo->write(Fx::decisionPath('DEC-100'), Fx::decisionRecord('DEC-100', declaration: ['Approval Date' => $date, 'Effective Date' => $date]));
            $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => 'DEC-100', 'status' => 'ACTIVE']]));
            $commit = $repo->commit('decision');

            $r = (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
                ['decision_id' => 'DEC-100', 'decision_file' => Fx::decisionPath('DEC-100'), 'commit_ref' => $commit],
                $repo->path,
                '1.0.0-rc.3',
                $commit,
                'source-only',
                '2030-01-01T00:00:00Z',
            );
            $repo->cleanup();

            self::assertSame('FAIL', $r['status'], $date);
            self::assertStringContainsString('missing or ambiguous', $r['message'], $date);
        }
    }

    public function testChronologyOrderingIsPreserved(): void
    {
        $e = EvidenceFixtures::validDist();
        self::assertSame([], QualificationEvidenceSchema::validate($e));

        $late = $e;
        $late['qualified_at'] = '2026-10-06T19:00:00Z';
        self::assertStringContainsString('qualified_at precedes', implode('|', QualificationEvidenceSchema::validate($late)));

        $future = $e;
        $review = $future['semantic_review'];
        self::assertIsArray($review);
        $review['reviewed_at'] = '2026-10-06T20:00:01Z';
        $future['semantic_review'] = $review;
        self::assertStringContainsString('later than qualification_started_at', implode('|', QualificationEvidenceSchema::validate($future)));
    }

    // ------------------------------------------------------------ F94-06: SemVer

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function targets(): iterable
    {
        yield '1.0.0' => ['1.0.0', true];
        yield '0.0.0' => ['0.0.0', true];
        yield '10.20.30' => ['10.20.30', true];
        yield '1.0.0-alpha' => ['1.0.0-alpha', true];
        yield '1.0.0-alpha.1' => ['1.0.0-alpha.1', true];
        yield '1.0.0-rc.3' => ['1.0.0-rc.3', true];
        yield '1.0.0-0' => ['1.0.0-0', true];
        yield '1.0.0-alpha-beta' => ['1.0.0-alpha-beta', true];
        yield '1.0.0-x.7.z.92' => ['1.0.0-x.7.z.92', true];
        yield '1.0.0-0a' => ['1.0.0-0a', true];
        yield '1.0.0+build.1' => ['1.0.0+build.1', true];
        yield '1.0.0+001' => ['1.0.0+001', true];
        yield '1.0.0-rc.3+build.42' => ['1.0.0-rc.3+build.42', true];
        yield '1.0.0-rc.3+build.1' => ['1.0.0-rc.3+build.1', true];
        yield '01.0.0' => ['01.0.0', false];
        yield '1.01.0' => ['1.01.0', false];
        yield '1.0.01' => ['1.0.01', false];
        yield '1.0.0-01' => ['1.0.0-01', false];
        yield '1.0.0-alpha.01' => ['1.0.0-alpha.01', false];
        yield '1.0.0-a..b' => ['1.0.0-a..b', false];
        yield '1.0.0-' => ['1.0.0-', false];
        yield '1.0.0-alpha.' => ['1.0.0-alpha.', false];
        yield '1.0.0+' => ['1.0.0+', false];
        yield '1.0.0+a..b' => ['1.0.0+a..b', false];
        yield '1.0.0-rc_3' => ['1.0.0-rc_3', false];
        yield 'v1.0.0' => ['v1.0.0', false];
        yield '1.0' => ['1.0', false];
        yield '1' => ['1', false];
        yield '1.0.0.0' => ['1.0.0.0', false];
        yield 'leading space' => [' 1.0.0', false];
        yield 'trailing newline' => ["1.0.0\n", false];
        yield 'empty' => ['', false];
        yield 'unicode digits' => ['١.٠.٠', false];
    }

    #[DataProvider('targets')]
    public function testCanonicalExactSemVerValidator(string $value, bool $valid): void
    {
        self::assertSame($valid, ReleaseContract::isValidExactSemVer($value), $value);
    }

    #[DataProvider('targets')]
    public function testRavTargetValidationUsesTheCanonicalValidator(string $value, bool $valid): void
    {
        $r = (new ReleaseArtifactVerifier())->evaluateTargetVersionSyntax($value);

        self::assertSame($valid ? 'PASS' : 'FAIL', $r['status'], $value);
    }

    #[DataProvider('targets')]
    public function testEvidenceSchemaTargetValidationUsesTheCanonicalValidator(string $value, bool $valid): void
    {
        $evidence = EvidenceFixtures::validDist($value);
        $errors = implode('|', QualificationEvidenceSchema::validate($evidence));

        self::assertSame($valid, ! str_contains($errors, 'invalid target_version'), $value . ' => ' . $errors);
    }

    public function testDecisionScopeExactTokensUseTheCanonicalValidator(): void
    {
        self::assertTrue(ReleaseContract::versionInScope('1.0.0-rc.3', '1.0.0-rc.3'));
        self::assertTrue(ReleaseContract::versionInScope('1.0.0-rc.3', '1.0.x, 2.0.0'));
        foreach (['1.0.0-01', '01.0.0', '1.0.0-a..b', '01.x', '1.01.x'] as $badToken) {
            self::assertNull(ReleaseContract::versionInScope('1.0.0-rc.3', $badToken), $badToken);
        }
    }

    public function testPavRejectsMalformedFabricatedTargetsBeforeAnyNetworkWork(): void
    {
        foreach (['1.0.0-01', '1.0.0-a..b', '01.0.0', 'v1.0.0'] as $bad) {
            $evidence = EvidenceFixtures::validDist($bad);
            $result = (new PublishedArtifactVerifier())->verify(['qualification_evidence' => $evidence]);

            self::assertSame('FAIL', $result['status'], $bad);
            self::assertSame('FAIL', $result['checks']['qualification_evidence']['status'], $bad);
            self::assertStringContainsString('invalid target_version', $result['checks']['qualification_evidence']['message']);
            self::assertArrayNotHasKey('composer_version_evidence', $result['checks'], 'no Composer work before evidence validation');
            self::assertArrayNotHasKey('composer_resolution', $result['checks']);
        }
    }

    public function testDecisionIdentifiersInFabricatedEvidenceRejectTrailingNewlines(): void
    {
        foreach (['decision_id' => "DEC-100\n", 'decision_file' => "docs/decisions/DEC-100_TEST.md\n"] as $field => $value) {
            $evidence = EvidenceFixtures::validSourceOnly();
            $decision = $evidence['source_only_decision'];
            self::assertIsArray($decision);
            $decision[$field] = $value;
            $evidence['source_only_decision'] = $decision;

            self::assertStringContainsString('invalid Decision ID or record path', implode('|', QualificationEvidenceSchema::validate($evidence)), $field);
        }
    }

    public function testNormalizeVersionRemainsSeparateObservedVersionNormalisation(): void
    {
        self::assertSame('1.0.0-rc.3', ReleaseContract::normalizeVersion('v1.0.0-rc.3'));
        self::assertFalse(ReleaseContract::isValidExactSemVer('v1.0.0-rc.3'), 'a v-prefix is never a valid exact release target');
    }
}
