<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceFixtures;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceWriter;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ProcessRunner;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\QualificationEvidenceSchema;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseContract;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseRepositoryFixture as Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Qualifying lifecycle: durable evidence/report persistence is part of PASS, and the
 * semantic review must already exist (reviewed_at <= qualification_started_at).
 */
final class QualifyingPersistenceTest extends TestCase
{
    private const string TARGET = '1.0.0-rc.3';
    private const string STARTED = '2026-10-06T20:00:00Z';

    private Fx $repo;
    private string $outDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new Fx();
        $this->repo->populateRelease(self::TARGET);
        $this->outDir = sys_get_temp_dir() . '/maatify-out-' . bin2hex(random_bytes(6));
        mkdir($this->outDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->repo->cleanup();
        @chmod($this->outDir, 0777);
        ReleaseContract::removeTree($this->outDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------ semantic-review chronology

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function reviewTimestamps(): iterable
    {
        yield 'after the boundary' => ['2026-10-06T20:00:01Z', false];
        yield 'far-future review' => ['2099-01-01T00:00:00Z', false];
        yield 'exactly at the boundary' => [self::STARTED, true];
        yield 'before the boundary' => ['2026-10-06T19:59:59Z', true];
        yield 'local time with offset (ambiguous)' => ['2026-10-06T19:00:00+02:00', false];
        yield 'space-separated' => ['2026-10-06 19:00:00', false];
        yield 'date only' => ['2026-10-06', false];
        yield 'garbage' => ['yesterday', false];
    }

    #[DataProvider('reviewTimestamps')]
    public function testRavSemanticReviewChronologyIsBoundToQualificationStart(string $reviewedAt, bool $eligible): void
    {
        $sha = str_repeat('a', 40);
        $file = $this->outDir . '/review.json';
        file_put_contents($file, (string) file_get_contents($this->repo->semanticReviewFile(self::TARGET, $sha, $reviewedAt)));

        $result = (new ReleaseArtifactVerifier())->evaluateSemanticReviewEvidence($file, self::TARGET, $sha, self::STARTED);

        self::assertSame($eligible ? 'PASS' : 'FAIL', $result['status'], $result['message']);
    }

    #[DataProvider('reviewTimestamps')]
    public function testSchemaChronologyIsBoundToQualificationStart(string $reviewedAt, bool $eligible): void
    {
        $evidence = EvidenceFixtures::validDist();
        $review = $evidence['semantic_review'];
        self::assertIsArray($review);
        $review['reviewed_at'] = $reviewedAt;
        $evidence['semantic_review'] = $review;
        $evidence['qualification_started_at'] = self::STARTED;
        $evidence['qualified_at'] = '2026-10-06T20:05:00Z';

        $errors = QualificationEvidenceSchema::validate($evidence);

        if ($eligible) {
            self::assertSame([], $errors);
        } else {
            self::assertNotSame([], $errors);
        }
    }

    public function testQualificationStartAfterQualifiedAtStillFails(): void
    {
        $evidence = EvidenceFixtures::validDist();
        $evidence['qualification_started_at'] = '2026-10-06T21:00:00Z';
        $evidence['qualified_at'] = '2026-10-06T20:05:00Z';

        self::assertStringContainsString('qualified_at precedes', implode('|', QualificationEvidenceSchema::validate($evidence)));
    }

    public function testFullQualifyingRavRejectsFutureDatedSemanticReview(): void
    {
        $candidate = $this->repo->commit('release');

        $result = (new ReleaseArtifactVerifier())->verifyQualifying([
            'target' => self::TARGET,
            'candidate_sha' => $candidate,
            'repo_path' => $this->repo->path,
            'semantic_review_file' => $this->repo->semanticReviewFile(self::TARGET, $candidate, '2099-01-01T00:00:00Z'),
        ], $this->outDir . '/evidence.json');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('later than the RAV qualification start', $result['checks']['semantic_review']['message']);
        self::assertFileDoesNotExist($this->outDir . '/evidence.json');
    }

    // ------------------------------------------------------------ writer

    public function testWriterPersistsAtomicallyAndLeavesNoTemporaryFile(): void
    {
        $dest = $this->outDir . '/out.json';

        $r = (new EvidenceWriter())->write($dest, ['a' => 1, 'b' => ['c' => true]], $this->repo->path);

        self::assertSame('PASS', $r['status'], $r['message']);
        self::assertSame(['a' => 1, 'b' => ['c' => true]], json_decode((string) file_get_contents($dest), true));
        self::assertSame([$this->basename($dest)], $this->listDir());
    }

    public function testWriterRejectsMissingParentDirectoryDirectoryDestinationAndExistingFile(): void
    {
        $w = new EvidenceWriter();
        mkdir($this->outDir . '/adir');
        file_put_contents($this->outDir . '/existing.json', 'old');

        self::assertSame('FAIL', $w->write($this->outDir . '/nope/out.json', ['x' => 1], $this->repo->path)['status']);
        self::assertSame('FAIL', $w->write($this->outDir . '/adir', ['x' => 1], $this->repo->path)['status']);
        $existing = $w->write($this->outDir . '/existing.json', ['x' => 1], $this->repo->path);
        self::assertSame('FAIL', $existing['status']);
        self::assertSame('old', file_get_contents($this->outDir . '/existing.json'), 'stale evidence must never be overwritten');
        self::assertSame('FAIL', $w->write('', ['x' => 1], $this->repo->path)['status']);
    }

    public function testWriterRejectsDestinationsInsideTheRepositoryIncludingGitDirAndSymlinks(): void
    {
        $w = new EvidenceWriter();
        $before = $this->repo->git(['status', '--porcelain']);

        foreach (['/evidence.json', '/src/evidence.json', '/.git/evidence.json'] as $inside) {
            $r = $w->write($this->repo->path . $inside, ['x' => 1], $this->repo->path);
            self::assertSame('FAIL', $r['status'], $inside);
            self::assertStringContainsString('inside the repository', $r['message']);
        }

        symlink($this->repo->path, $this->outDir . '/link-to-repo');
        self::assertSame('FAIL', $w->write($this->outDir . '/link-to-repo/evidence.json', ['x' => 1], $this->repo->path)['status']);

        self::assertSame($before, $this->repo->git(['status', '--porcelain']));
        self::assertFileDoesNotExist($this->repo->path . '/evidence.json');
    }

    public function testWriterFailsOnUnwritableDirectoryWithoutPartialFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission failures cannot be simulated as root.');
        }
        $locked = $this->outDir . '/locked';
        mkdir($locked);
        chmod($locked, 0555);

        $r = (new EvidenceWriter())->write($locked . '/out.json', ['x' => 1], $this->repo->path);
        chmod($locked, 0755);

        self::assertSame('FAIL', $r['status']);
        self::assertSame([], array_values(array_diff(scandir($locked) ?: [], ['.', '..'])));
    }

    public function testWriterFailsReadBackVerificationAndLeavesNoFinalOrTemporaryFile(): void
    {
        $writer = new EvidenceWriter(static function (string $temp): void {
            file_put_contents($temp, '{"corrupted":true}');
        });

        $r = $writer->write($this->outDir . '/out.json', ['x' => 1], $this->repo->path);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('Read-back verification', $r['message']);
        self::assertSame([], $this->listDir());
    }

    public function testWriterFailsOnUnencodableData(): void
    {
        $r = (new EvidenceWriter())->write($this->outDir . '/out.json', ['bad' => NAN], $this->repo->path);

        self::assertSame('FAIL', $r['status']);
        self::assertSame([], $this->listDir());
    }

    // ------------------------------------------------------------ RAV lifecycle

    public function testQualifyingRavPersistsEvidenceThatEqualsTheGeneratedResult(): void
    {
        $candidate = $this->repo->commit('release');
        $dest = $this->outDir . '/evidence.json';

        $result = (new ReleaseArtifactVerifier())->verifyQualifying($this->ravOptions($candidate), $dest);

        self::assertSame('PASS', $result['status'], implode("\n", $result['failures']));
        self::assertFileExists($dest);
        $persisted = json_decode((string) file_get_contents($dest), true);
        self::assertIsArray($persisted);
        self::assertSame([], QualificationEvidenceSchema::validate($persisted));
        self::assertSame($persisted, json_decode((string) json_encode($result['qualification_evidence'] ?? null), true));
        self::assertSame(self::TARGET, $persisted['target_version']);
        self::assertSame($candidate, $persisted['candidate_sha']);
        self::assertSame($result['candidate_tree_sha'], $persisted['candidate_tree_sha']);
        self::assertSame('PASS', $persisted['status']);
        self::assertSame($dest, $result['evidence_path'] ?? null);
        self::assertSame('', $this->repo->git(['status', '--porcelain']), 'persisting must not dirty the candidate');
        self::assertSame([$this->basename($dest)], $this->listDir());
    }

    public function testQualifyingRavFailsWhenPersistenceFailsAndLeavesNoEvidence(): void
    {
        $candidate = $this->repo->commit('release');
        $dest = $this->outDir . '/evidence.json';
        $writer = new EvidenceWriter(static function (string $temp): void {
            file_put_contents($temp, '{}');
        });

        $result = (new ReleaseArtifactVerifier())->verifyQualifying($this->ravOptions($candidate), $dest, $writer);

        self::assertSame('FAIL', $result['status']);
        self::assertArrayNotHasKey('qualification_evidence', $result);
        self::assertSame('FAIL', $result['checks']['evidence_persistence']['status']);
        self::assertFileDoesNotExist($dest);
        self::assertSame([], $this->listDir());
    }

    public function testQualifyingRavRejectsRepositoryInternalAndInvalidDestinationsBeforeVerifying(): void
    {
        $candidate = $this->repo->commit('release');

        foreach ([$this->repo->path . '/evidence.json', $this->outDir . '/missing-dir/evidence.json', $this->outDir] as $dest) {
            $result = (new ReleaseArtifactVerifier())->verifyQualifying($this->ravOptions($candidate), $dest);

            self::assertSame('FAIL', $result['status'], $dest);
            self::assertArrayNotHasKey('qualification_evidence', $result);
            self::assertArrayNotHasKey('candidate_sha_and_git', $result['checks'], 'no verification work before the destination is proven');
        }
        self::assertSame('', $this->repo->git(['status', '--porcelain']));
    }

    public function testFailedRavDoesNotPersistEvidence(): void
    {
        $candidate = $this->repo->commit('release');
        $options = $this->ravOptions($candidate);
        $options['target'] = 'not-semver';

        $result = (new ReleaseArtifactVerifier())->verifyQualifying($options, $this->outDir . '/evidence.json');

        self::assertSame('FAIL', $result['status']);
        self::assertFileDoesNotExist($this->outDir . '/evidence.json');
    }

    // ------------------------------------------------------------ PAV lifecycle

    public function testPavPersistsCompleteReportOnlyThenKeepsPass(): void
    {
        $dest = $this->outDir . '/report.json';

        $result = (new PublishedArtifactVerifier())->finalizePersistence($this->syntheticPavPass(), $dest, $this->repo->path);

        self::assertSame('PASS', $result['status']);
        self::assertSame($dest, $result['report_path'] ?? null);
        $report = json_decode((string) file_get_contents($dest), true);
        self::assertIsArray($report);
        foreach (['status', 'package_name', 'target_version', 'qualified_sha', 'installation_mode', 'installed_path', 'composer_audit', 'delivery_evidence', 'verified_at', 'checks', 'failures'] as $key) {
            self::assertArrayHasKey($key, $report, $key);
        }
        self::assertSame('PASS', $report['status']);
        self::assertSame('dist', $report['installation_mode']);
        self::assertIsArray($report['composer_audit']);
        self::assertArrayHasKey('controlled_environment', $report['composer_audit']);
        self::assertIsArray($report['delivery_evidence']);
        self::assertSame('dist', $report['delivery_evidence']['installation_source']);
        self::assertIsArray($report['checks']);
        self::assertArrayHasKey('resolved_version', $report['checks']);
        self::assertArrayHasKey('content_manifest_correspondence', $report['checks']);
    }

    public function testPavPersistenceFailureAfterSuccessfulVerificationIsNotPass(): void
    {
        $verifier = new PublishedArtifactVerifier();

        foreach ([$this->repo->path . '/report.json', $this->outDir . '/missing/report.json', $this->outDir] as $dest) {
            $result = $verifier->finalizePersistence($this->syntheticPavPass(), $dest, $this->repo->path);

            self::assertSame('FAIL', $result['status'], $dest);
            self::assertSame('FAIL', $result['checks']['report_persistence']['status']);
            self::assertArrayNotHasKey('report_path', $result);
        }

        $corrupting = new EvidenceWriter(static function (string $temp): void {
            file_put_contents($temp, '[]');
        });
        $result = $verifier->finalizePersistence($this->syntheticPavPass(), $this->outDir . '/report.json', $this->repo->path, $corrupting);
        self::assertSame('FAIL', $result['status']);
        self::assertFileDoesNotExist($this->outDir . '/report.json');
        self::assertSame([], $this->listDir());
    }

    public function testQualifyingPavRejectsInvalidReportDestinationBeforeAnyWork(): void
    {
        $result = (new PublishedArtifactVerifier())->verifyQualifying(
            ['qualification_evidence' => EvidenceFixtures::validDist(), 'repo_path' => $this->repo->path],
            $this->repo->path . '/report.json',
        );

        self::assertSame('FAIL', $result['status']);
        self::assertArrayNotHasKey('qualification_evidence', $result['checks']);
        self::assertArrayNotHasKey('composer_resolution', $result['checks']);
    }

    public function testFailedPavReportIsStillPersistedForRetention(): void
    {
        $incomplete = EvidenceFixtures::validDist();
        $incomplete['content_manifest'] = [];
        $dest = $this->outDir . '/report.json';

        $result = (new PublishedArtifactVerifier())->verifyQualifying(
            ['qualification_evidence' => $incomplete, 'repo_path' => $this->repo->path],
            $dest,
        );

        self::assertSame('FAIL', $result['status']);
        $report = json_decode((string) file_get_contents($dest), true);
        self::assertIsArray($report);
        self::assertSame('FAIL', $report['status']);
        self::assertArrayHasKey('composer_audit', $report);
    }

    // ------------------------------------------------------------ public CLIs

    public function testRavCliWithoutOutputEvidenceIsAUsageFailureAndNeverPasses(): void
    {
        $candidate = $this->repo->commit('release');

        $r = $this->cli('verify-release-artifact.php', [
            '--target=' . self::TARGET,
            '--candidate-sha=' . $candidate,
            '--repo-path=' . $this->repo->path,
            '--semantic-review-file=' . $this->repo->semanticReviewFile(self::TARGET, $candidate),
        ]);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--output-evidence', $r['output']);
        self::assertStringNotContainsString('OVERALL RESULT: PASS', $r['output']);
        self::assertSame([], $this->listDir());
    }

    public function testRavCliPersistsEvidenceOutsideTheRepositoryAndPasses(): void
    {
        $candidate = $this->repo->commit('release');
        $dest = $this->outDir . '/evidence.json';

        $r = $this->cli('verify-release-artifact.php', $this->ravCliArgs($candidate, $dest));

        self::assertSame(0, $r['code'], $r['output']);
        self::assertStringContainsString('OVERALL RESULT: PASS', $r['output']);
        $persisted = json_decode((string) file_get_contents($dest), true);
        self::assertIsArray($persisted);
        self::assertSame([], QualificationEvidenceSchema::validate($persisted));
        self::assertSame($candidate, $persisted['candidate_sha']);
        self::assertSame('', $this->repo->git(['status', '--porcelain']));
    }

    public function testRavCliFailsForRepositoryInternalUnwritableAndExistingDestinations(): void
    {
        $candidate = $this->repo->commit('release');
        file_put_contents($this->outDir . '/taken.json', 'x');

        foreach ([$this->repo->path . '/evidence.json', $this->outDir . '/missing/evidence.json', $this->outDir . '/taken.json'] as $dest) {
            $r = $this->cli('verify-release-artifact.php', $this->ravCliArgs($candidate, $dest));

            self::assertNotSame(0, $r['code'], $dest);
            self::assertStringNotContainsString('OVERALL RESULT: PASS', $r['output'], $dest);
        }
        self::assertFileDoesNotExist($this->repo->path . '/evidence.json');
        self::assertSame('x', file_get_contents($this->outDir . '/taken.json'));
        self::assertSame('', $this->repo->git(['status', '--porcelain']));
    }

    public function testPavCliWithoutOutputJsonIsAUsageFailureAndNeverPasses(): void
    {
        $evidence = $this->outDir . '/rav.json';
        file_put_contents($evidence, (string) json_encode(EvidenceFixtures::validDist()));

        $r = $this->cli('verify-published-artifact.php', ['--qualification-evidence=' . $evidence]);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--output-json', $r['output']);
        self::assertStringNotContainsString('OVERALL RESULT: PASS', $r['output']);
    }

    public function testPavCliFailsForInvalidDestinationAndPersistsFailureReportOtherwise(): void
    {
        $bad = EvidenceFixtures::validDist();
        $bad['content_manifest'] = [];
        $evidence = $this->outDir . '/rav.json';
        file_put_contents($evidence, (string) json_encode($bad));
        $toolRepo = dirname(__DIR__, 3);

        $inside = $this->cli('verify-published-artifact.php', ['--qualification-evidence=' . $evidence, '--output-json=' . $toolRepo . '/zz-pav-report.json']);
        self::assertNotSame(0, $inside['code']);
        self::assertFileDoesNotExist($toolRepo . '/zz-pav-report.json');

        $report = $this->outDir . '/pav-report.json';
        $ok = $this->cli('verify-published-artifact.php', ['--qualification-evidence=' . $evidence, '--output-json=' . $report]);
        self::assertSame(1, $ok['code']);
        self::assertStringNotContainsString('OVERALL RESULT: PASS', $ok['output']);
        $decoded = json_decode((string) file_get_contents($report), true);
        self::assertIsArray($decoded);
        self::assertSame('FAIL', $decoded['status']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{target: string, candidate_sha: string, repo_path: string, semantic_review_file: string}
     */
    private function ravOptions(string $candidate): array
    {
        return [
            'target' => self::TARGET,
            'candidate_sha' => $candidate,
            'repo_path' => $this->repo->path,
            'semantic_review_file' => $this->repo->semanticReviewFile(self::TARGET, $candidate),
        ];
    }

    /**
     * @return list<string>
     */
    private function ravCliArgs(string $candidate, string $dest): array
    {
        return [
            '--target=' . self::TARGET,
            '--candidate-sha=' . $candidate,
            '--repo-path=' . $this->repo->path,
            '--semantic-review-file=' . $this->repo->semanticReviewFile(self::TARGET, $candidate),
            '--output-evidence=' . $dest,
        ];
    }

    /**
     * @param list<string> $args
     * @return array{code: int, output: string}
     */
    private function cli(string $script, array $args): array
    {
        return ProcessRunner::run([PHP_BINARY, dirname(__DIR__, 3) . '/scripts/release/' . $script, ...$args], null, null, null, true);
    }

    /**
     * @return array{
     *     status: 'PASS'|'FAIL',
     *     package_name: string,
     *     target_version: string,
     *     qualified_sha: string,
     *     installation_mode: string,
     *     installed_path: string,
     *     composer_audit: array<string, mixed>,
     *     delivery_evidence: array<string, mixed>,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     * }
     */
    private function syntheticPavPass(): array
    {
        return [
            'status' => 'PASS',
            'package_name' => ReleaseContract::PACKAGE_NAME,
            'target_version' => self::TARGET,
            'qualified_sha' => str_repeat('a', 40),
            'installation_mode' => 'dist',
            'installed_path' => '/tmp/x/vendor/maatify/php-rate-limiter',
            'composer_audit' => ['composer_version' => 'Composer version 2.10.3', 'controlled_environment' => ['set' => [], 'cleared' => [], 'passthrough' => []]],
            'delivery_evidence' => ['installation_source' => 'dist', 'dist' => ['type' => 'zip', 'url' => 'https://e.test/z', 'reference' => str_repeat('a', 40)]],
            'verified_at' => '2026-10-07T00:00:00Z',
            'checks' => [
                'resolved_version' => ['status' => 'PASS', 'message' => 'ok'],
                'content_manifest_correspondence' => ['status' => 'PASS', 'message' => 'ok'],
            ],
            'failures' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function listDir(): array
    {
        $items = array_values(array_diff(scandir($this->outDir) ?: [], ['.', '..']));
        sort($items);

        return $items;
    }

    private function basename(string $path): string
    {
        return basename($path);
    }
}
