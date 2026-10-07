<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceFixtures;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\QualificationEvidenceSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualificationEvidenceSchemaTest extends TestCase
{
    public function testValidCompleteDistEvidencePassesTheInputGate(): void
    {
        self::assertSame([], QualificationEvidenceSchema::validate(EvidenceFixtures::validDist()));
    }

    public function testValidCompleteSourceOnlyEvidencePassesTheInputGate(): void
    {
        self::assertSame([], QualificationEvidenceSchema::validate(EvidenceFixtures::validSourceOnly()));
    }

    private const string UNSET = '__unset__';

    /**
     * @param list<string> $path
     */
    #[DataProvider('invalidDistMutations')]
    public function testRejectsIncompleteOrFabricatedShapeDistEvidence(string $expectedFragment, array $path, mixed $value): void
    {
        $errors = QualificationEvidenceSchema::validate(self::setPath(EvidenceFixtures::validDist(), $path, $value));

        self::assertNotSame([], $errors);
        self::assertStringContainsString($expectedFragment, implode(' | ', $errors));
    }

    /**
     * @return iterable<string, array{string, list<string>, mixed}>
     */
    public static function invalidDistMutations(): iterable
    {
        $manifest = EvidenceFixtures::validDist()['content_manifest'];
        $twoFiles = is_array($manifest) ? array_slice($manifest, 0, 2, true) : [];

        yield 'wrong schema' => ['unknown schema_version', ['schema_version'], '1.0.0'];
        yield 'failed status' => ['status is "FAIL"', ['status'], 'FAIL'];
        yield 'wrong package' => ['package_name', ['package_name'], 'x/y'];
        yield 'invalid target' => ['invalid target_version', ['target_version'], 'rc3'];
        yield 'invalid candidate sha' => ['invalid candidate_sha', ['candidate_sha'], 'v1.0.0'];
        yield 'missing tree sha' => ['candidate_tree_sha', ['candidate_tree_sha'], self::UNSET];
        yield 'invalid tree sha' => ['invalid candidate_tree_sha', ['candidate_tree_sha'], 'zz'];
        yield 'invalid delivery policy' => ['unknown delivery_policy', ['delivery_policy'], 'zip'];
        yield 'missing channel' => ['approved_distribution_channel', ['approved_distribution_channel'], ''];
        yield 'unapproved dist channel' => ['not the package-approved channel', ['approved_distribution_channel'], 'https://evil.example.test'];
        yield 'empty semantic review' => ['semantic_review is absent or empty', ['semantic_review'], []];
        yield 'missing semantic claim' => ['is not CONFIRMED', ['semantic_review', 'claims', 'security.lifecycle_support_semantics'], self::UNSET];
        yield 'unconfirmed semantic claim' => ['is not CONFIRMED', ['semantic_review', 'claims', 'readme.exact_install_target'], 'PENDING'];
        yield 'semantic review wrong target' => ['semantic_review target', ['semantic_review', 'target'], '9.9.9'];
        yield 'semantic review wrong sha' => ['semantic_review candidate_sha', ['semantic_review', 'candidate_sha'], str_repeat('f', 40)];
        yield 'semantic review wrong schema' => ['semantic_review has the wrong schema_version', ['semantic_review', 'schema_version'], '9.0.0'];
        yield 'empty manifest' => ['content_manifest is absent or empty', ['content_manifest'], []];
        yield 'two-file manifest' => ['content_manifest is incomplete', ['content_manifest'], $twoFiles];
        yield 'bad hash' => ['not a SHA-256', ['content_manifest', 'README.md'], 'abc'];
        yield 'ambiguous path' => ['malformed/ambiguous path', ['content_manifest', 'src/../README.md'], str_repeat('a', 64)];
        yield 'missing distribution evidence' => ['distribution_evidence is absent', ['distribution_evidence'], []];
        yield 'archive missing files' => ['required files missing', ['distribution_evidence', 'required_files_missing'], ['src/X.php']];
        yield 'archive differs from tree' => ['archive content equals', ['distribution_evidence', 'archive_content_matches_tree'], false];
        yield 'forbidden archive entries' => ['forbidden archive entries', ['distribution_evidence', 'forbidden_entries'], ['.env']];
        yield 'archive exclude hits required' => ['composer_archive_exclude status', ['distribution_evidence', 'composer_archive_exclude', 'status'], 'REQUIRED_CONTENT_IMPACTED'];
        yield 'dist carries a source-only decision' => ['must be null for the dist', ['source_only_decision'], ['decision_id' => 'DEC-1']];
        yield 'timestamps reversed' => ['qualified_at precedes', ['qualified_at'], '2020-01-01T00:00:00Z'];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('invalidSourceOnlyMutations')]
    public function testRejectsIncompleteSourceOnlyEvidence(string $expectedFragment, array $path, mixed $value): void
    {
        $errors = QualificationEvidenceSchema::validate(self::setPath(EvidenceFixtures::validSourceOnly(), $path, $value));

        self::assertNotSame([], $errors);
        self::assertStringContainsString($expectedFragment, implode(' | ', $errors));
    }

    /**
     * @return iterable<string, array{string, list<string>, mixed}>
     */
    public static function invalidSourceOnlyMutations(): iterable
    {
        yield 'source-only without decision' => ['source_only_decision is mandatory', ['source_only_decision'], null];
        yield 'missing immutable commit' => ['missing "decision_commit"', ['source_only_decision', 'decision_commit'], self::UNSET];
        yield 'non-sha decision commit' => ['"decision_commit" is not a 40-hex', ['source_only_decision', 'decision_commit'], 'v1.0.0'];
        yield 'not active at qualification' => ['not ACTIVE and indexed', ['source_only_decision', 'status_at_qualification'], 'PROPOSED'];
        yield 'index not active' => ['not ACTIVE and indexed', ['source_only_decision', 'index_status_at_qualification'], 'SUPERSEDED'];
        yield 'no owner approval' => ['Owner approval', ['source_only_decision', 'owner_approval'], []];
        yield 'effective after start' => ['does not predate', ['source_only_decision', 'effective_at'], '2999-01-01T00:00:00Z'];
        yield 'channel mismatch' => ['channel differs', ['source_only_decision', 'approved_distribution_channel'], 'https://other.example.test'];
        yield 'wrong package' => ['package_name mismatch', ['source_only_decision', 'package_name'], 'other/pkg'];
        yield 'scope does not cover' => ['version scope does not cover', ['source_only_decision', 'version_scope'], '2.x'];
        yield 'not source-only' => ['intentional source-only', ['source_only_decision', 'delivery_mode'], 'dist'];
        yield 'target mismatch' => ['qualification target/candidate/start', ['source_only_decision', 'qualification_target'], '9.9.9'];
        yield 'candidate mismatch' => ['qualification target/candidate/start', ['source_only_decision', 'candidate_sha'], str_repeat('9', 40)];
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private static function setPath(array $data, array $path, mixed $value): array
    {
        $key = $path[0];
        $rest = array_slice($path, 1);
        if ($rest === []) {
            if ($value === self::UNSET) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        } else {
            $child = is_array($data[$key] ?? null) ? $data[$key] : [];
            $data[$key] = self::setPath($child, $rest, $value);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
