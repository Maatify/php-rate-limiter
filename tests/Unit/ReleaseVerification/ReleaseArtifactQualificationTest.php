<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\QualificationEvidenceSchema;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseContract;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseRepositoryFixture as Fx;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial tests for qualifying RAV against real Git objects: immutable source-only
 * Decision proof, real archive/export verification, and the emitted evidence schema.
 */
final class ReleaseArtifactQualificationTest extends TestCase
{
    private const string TARGET = '1.0.0-rc.3';
    private const string ID = 'DEC-100';

    private Fx $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new Fx();
        $this->repo->populateRelease(self::TARGET);
    }

    protected function tearDown(): void
    {
        $this->repo->cleanup();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- qualifying dist RAV

    public function testQualifyingDistRavPassesAndEmitsCompleteSchemaValidEvidence(): void
    {
        $candidate = $this->repo->commit('release');
        $out = $this->repo->path . '-evidence.json';

        $result = (new ReleaseArtifactVerifier())->verify([
            'target' => self::TARGET,
            'candidate_sha' => $candidate,
            'repo_path' => $this->repo->path,
            'semantic_review_file' => $this->repo->semanticReviewFile(self::TARGET, $candidate),
            'output_evidence' => $out,
        ]);

        self::assertSame('PASS', $result['status'], implode("\n", $result['failures']));
        self::assertFileExists($out);
        /** @var array<string, mixed> $evidence */
        $evidence = json_decode((string) file_get_contents($out), true);
        self::assertSame([], QualificationEvidenceSchema::validate($evidence));
        self::assertSame('dist', $evidence['delivery_policy']);
        self::assertNull($evidence['source_only_decision']);
        self::assertSame(ReleaseContract::DEFAULT_DISTRIBUTION_CHANNEL, $evidence['approved_distribution_channel']);
        self::assertSame($this->repo->git(['rev-parse', $candidate . '^{tree}']), $evidence['candidate_tree_sha']);
        self::assertIsArray($evidence['content_manifest']);
        self::assertArrayHasKey('src', $evidence['content_manifest']);
        $dist = $evidence['distribution_evidence'];
        self::assertIsArray($dist);
        self::assertIsArray($dist['composer_archive_exclude']);
        self::assertSame('NOT_APPLICABLE', $dist['composer_archive_exclude']['status']);
    }

    public function testWorktreeEditsDoNotChangeCandidateEvidenceAndAreRejectedAsDirty(): void
    {
        $candidate = $this->repo->commit('release');
        $this->repo->write('README.md', "# tampered, no target\n");

        $result = (new ReleaseArtifactVerifier())->verify([
            'target' => self::TARGET,
            'candidate_sha' => $candidate,
            'repo_path' => $this->repo->path,
            'semantic_review_file' => $this->repo->semanticReviewFile(self::TARGET, $candidate),
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('not clean', $result['checks']['candidate_sha_and_git']['message']);
    }

    // ---------------------------------------------------------------- archive behaviour

    public function testFailsWhenNestedRequiredFileIsExportIgnored(): void
    {
        $this->repo->write('.gitattributes', "src/Nested/CriticalRuntimeFile.php export-ignore\n");
        $candidate = $this->repo->commit('release');

        $result = (new ReleaseArtifactVerifier())->evaluateCandidateArchiveExport($this->repo->path, $candidate);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('src/Nested/CriticalRuntimeFile.php', $result['message']);
    }

    public function testFailsWhenComposerArchiveExcludeRemovesRequiredFile(): void
    {
        $this->repo->write('composer.json', (string) json_encode([
            'name' => 'maatify/php-rate-limiter',
            'license' => 'proprietary',
            'archive' => ['exclude' => ['/src/Nested/']],
        ]));
        $candidate = $this->repo->commit('release');

        $result = (new ReleaseArtifactVerifier())->evaluateCandidateArchiveExport($this->repo->path, $candidate);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('archive.exclude removes required file(s)', $result['message']);
        self::assertStringContainsString('src/Nested/CriticalRuntimeFile.php', $result['message']);
    }

    public function testComposerArchiveExcludeThatDoesNotTouchRequiredContentIsRecorded(): void
    {
        $this->repo->write('composer.json', (string) json_encode([
            'name' => 'maatify/php-rate-limiter',
            'license' => 'proprietary',
            'archive' => ['exclude' => ['/tests', '*.log']],
        ]));
        $this->repo->write('tests/ExampleTest.php', "<?php\n");
        $candidate = $this->repo->commit('release');

        $result = (new ReleaseArtifactVerifier())->evaluateCandidateArchiveExport($this->repo->path, $candidate);

        self::assertSame('PASS', $result['status'], $result['message']);
        $details = $result['details'] ?? [];
        self::assertIsArray($details['composer_archive_exclude']);
        self::assertSame('APPLIED_NO_REQUIRED_IMPACT', $details['composer_archive_exclude']['status'] ?? null);
    }

    public function testValidArchivePassesWithNestedFilesCounted(): void
    {
        $candidate = $this->repo->commit('release');

        $result = (new ReleaseArtifactVerifier())->evaluateCandidateArchiveExport($this->repo->path, $candidate);

        self::assertSame('PASS', $result['status'], $result['message']);
        $details = $result['details'] ?? [];
        self::assertGreaterThanOrEqual(10, $details['required_files_in_archive'] ?? 0);
        self::assertSame([], $details['required_files_missing'] ?? null);
    }

    public function testFailsWhenForbiddenTrackedArtifactEntersArchive(): void
    {
        $this->repo->write('.env', 'SECRET=1');
        $candidate = $this->repo->commit('release');

        $result = (new ReleaseArtifactVerifier())->evaluateCandidateArchiveExport($this->repo->path, $candidate);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('forbidden entries: .env', $result['message']);
    }

    // ---------------------------------------------------------------- source-only: immutable proof

    public function testSourceOnlyValidCompleteHistoricalEvidenceIsEligible(): void
    {
        [$decisionCommit, $candidate] = $this->sourceOnlyRepo();

        $result = $this->policy($decisionCommit, $candidate);

        self::assertSame('PASS', $result['status'], $result['message']);
        $d = $result['details'] ?? [];
        self::assertSame($decisionCommit, $d['decision_commit']);
        self::assertSame(Fx::CHANNEL, $d['approved_distribution_channel']);
        self::assertSame('1.0.x', $d['version_scope']);
        self::assertSame('ACTIVE', $d['index_status_at_qualification']);
        self::assertSame($this->repo->git(['rev-parse', $decisionCommit . ':' . Fx::decisionPath(self::ID)]), $d['record_blob_sha']);
    }

    public function testSourceOnlyFullQualifyingRavProducesSchemaValidSourceOnlyEvidence(): void
    {
        [$decisionCommit, $candidate] = $this->sourceOnlyRepo();
        $out = $this->repo->path . '-evidence.json';

        $result = (new ReleaseArtifactVerifier())->verify([
            'target' => self::TARGET,
            'candidate_sha' => $candidate,
            'repo_path' => $this->repo->path,
            'semantic_review_file' => $this->repo->semanticReviewFile(self::TARGET, $candidate),
            'delivery_policy' => 'source-only',
            'source_only_decision_id' => self::ID,
            'source_only_decision_file' => Fx::decisionPath(self::ID),
            'source_only_commit' => $decisionCommit,
            'output_evidence' => $out,
        ]);

        self::assertSame('PASS', $result['status'], implode("\n", $result['failures']));
        $evidence = $result['qualification_evidence'] ?? [];
        self::assertSame([], QualificationEvidenceSchema::validate($evidence));
        self::assertSame(Fx::CHANNEL, $evidence['approved_distribution_channel']);
    }

    public function testSourceOnlyFakeCommitRefStringFails(): void
    {
        [, $candidate] = $this->sourceOnlyRepo();

        foreach (['v1.0.0-rc.3', 'HEAD', 'abc123', str_repeat('0', 40)] as $fake) {
            $result = $this->policy($fake, $candidate);
            self::assertSame('FAIL', $result['status'], $fake);
        }
    }

    public function testSourceOnlyDecisionCommitMissingFromCandidateHistoryFails(): void
    {
        [, $candidate] = $this->sourceOnlyRepo();
        // A real commit that is NOT an ancestor of the candidate.
        $this->repo->git(['checkout', '-q', '--orphan', 'other']);
        $this->repo->write('other.txt', 'x');
        $orphan = $this->repo->commit('orphan');
        $this->repo->git(['checkout', '-q', 'main']);

        $result = $this->policy($orphan, $candidate);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('not contained in the history', $result['message']);
    }

    public function testSourceOnlyDecisionFileMissingAtImmutableCommitFails(): void
    {
        $early = $this->repo->commit('before the decision');
        [, $candidate] = $this->sourceOnlyRepo();

        $result = $this->policy($early, $candidate);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('does not exist at immutable commit', $result['message']);
    }

    public function testSourceOnlyIndexMissingAtImmutableCommitFails(): void
    {
        $this->repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID));
        $noIndex = $this->repo->commit('record without index');
        $this->repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
        $candidate = $this->repo->commit('release');

        $result = $this->policy($noIndex, $candidate);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('DECISIONS_INDEX.md does not exist at immutable commit', $result['message']);
    }

    public function testSourceOnlyDecisionNotActiveAtQualificationFails(): void
    {
        foreach (['PROPOSED', 'SUPERSEDED', 'DEFERRED'] as $status) {
            $repo = new Fx();
            $repo->populateRelease(self::TARGET);
            $repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, $status));
            $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => $status]]));
            $commit = $repo->commit('decision');

            $result = (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
                ['decision_id' => self::ID, 'decision_file' => Fx::decisionPath(self::ID), 'commit_ref' => $commit],
                $repo->path,
                self::TARGET,
                $commit,
                'source-only',
                gmdate('Y-m-d\TH:i:s\Z'),
            );
            $repo->cleanup();

            self::assertSame('FAIL', $result['status'], $status);
            self::assertStringContainsString('expected ACTIVE', $result['message']);
        }
    }

    public function testSourceOnlyIndexNotActiveFails(): void
    {
        $this->repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID));
        $this->repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'SUPERSEDED', 'superseded_by' => 'DEC-101']]));
        $commit = $this->repo->commit('decision');

        $result = $this->policy($commit, $commit);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('not indexed as ACTIVE', $result['message']);
    }

    public function testSourceOnlyWithoutOwnerApprovalFails(): void
    {
        [$commit, $candidate] = $this->sourceOnlyRepo(authority: 'Proposed by a maintainer.');
        self::assertSame('FAIL', $this->policy($commit, $candidate)['status']);
        self::assertStringContainsString('Owner approval', $this->policy($commit, $candidate)['message']);

        $repo2 = new Fx();
        $repo2->populateRelease(self::TARGET);
        $repo2->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, declaration: ['Owner Approval' => 'PENDING']));
        $repo2->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
        $c2 = $repo2->commit('decision');
        $r2 = (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
            ['decision_id' => self::ID, 'decision_file' => Fx::decisionPath(self::ID), 'commit_ref' => $c2],
            $repo2->path,
            self::TARGET,
            $c2,
            'source-only',
            gmdate('Y-m-d\TH:i:s\Z'),
        );
        $repo2->cleanup();
        self::assertSame('FAIL', $r2['status']);
        self::assertStringContainsString('complete Owner approval evidence', $r2['message']);
    }

    public function testSourceOnlyEffectiveTimingNotBeforeRavFailsOrIsAmbiguous(): void
    {
        $cases = [
            'future approval' => ['Approval Date' => '2999-01-01', 'Effective Date' => '2999-01-01'],
            'effective later than RAV' => ['Effective Date' => '2999-01-01'],
            'ambiguous format' => ['Approval Date' => 'last Tuesday'],
            'missing effective date' => ['Effective Date' => ''],
        ];
        foreach ($cases as $label => $override) {
            $repo = new Fx();
            $repo->populateRelease(self::TARGET);
            $repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, declaration: $override));
            $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
            $c = $repo->commit('decision');
            $r = (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
                ['decision_id' => self::ID, 'decision_file' => Fx::decisionPath(self::ID), 'commit_ref' => $c],
                $repo->path,
                self::TARGET,
                $c,
                'source-only',
                gmdate('Y-m-d\TH:i:s\Z'),
            );
            $repo->cleanup();
            self::assertSame('FAIL', $r['status'], $label);
        }
    }

    public function testSourceOnlyDateOnlyApprovalOnTheSameDayAsRavIsTreatedAsNotYetEffective(): void
    {
        $repo = new Fx();
        $repo->populateRelease(self::TARGET);
        $repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, declaration: ['Approval Date' => '2026-10-07', 'Effective Date' => '2026-10-07']));
        $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
        $c = $repo->commit('decision');
        $r = (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
            ['decision_id' => self::ID, 'decision_file' => Fx::decisionPath(self::ID), 'commit_ref' => $c],
            $repo->path,
            self::TARGET,
            $c,
            'source-only',
            '2026-10-07T10:00:00Z',
        );
        $repo->cleanup();

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('does not predate', $r['message']);
    }

    public function testSourceOnlyWrongPackageScopeChannelOrTargetFails(): void
    {
        $cases = [
            'wrong package' => [['Package' => 'maatify/other'], 'package'],
            'missing channel' => [['Composer Channel' => ''], 'channel'],
            'channel with credentials' => [['Composer Channel' => 'https://user:pw@packages.example.test'], 'channel'],
            'target outside scope' => [['Version Scope' => '2.x'], 'outside the version scope'],
            'exact scope other version' => [['Version Scope' => '1.0.0-rc.2'], 'outside the version scope'],
            'malformed scope' => [['Version Scope' => '>=1.0'], 'malformed'],
            'not source-only' => [['Delivery Mode' => 'dist'], 'source-only'],
            'no rationale' => [['Dist Rationale' => ''], 'dist is intentionally not offered'],
        ];
        foreach ($cases as $label => [$override, $needle]) {
            $repo = new Fx();
            $repo->populateRelease(self::TARGET);
            $repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, declaration: $override));
            $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
            $c = $repo->commit('decision');
            $r = (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
                ['decision_id' => self::ID, 'decision_file' => Fx::decisionPath(self::ID), 'commit_ref' => $c],
                $repo->path,
                self::TARGET,
                $c,
                'source-only',
                gmdate('Y-m-d\TH:i:s\Z'),
            );
            $repo->cleanup();
            self::assertSame('FAIL', $r['status'], $label);
            self::assertStringContainsString($needle, $r['message'], $label);
        }
    }

    public function testSourceOnlyWithoutDeclarationSectionFails(): void
    {
        $this->repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, withDeclaration: false));
        $this->repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
        $c = $this->repo->commit('decision');

        $r = $this->policy($c, $c);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('no "Source-Only Delivery Declaration"', $r['message']);
    }

    public function testSourceOnlyRecordAmendedBetweenDecisionAndCandidateFails(): void
    {
        [$decisionCommit] = $this->sourceOnlyRepo();
        $this->repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, declaration: ['Version Scope' => '1.x']));
        $candidate = $this->repo->commit('silent amendment');

        $r = $this->policy($decisionCommit, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('amended or removed before qualification', $r['message']);
    }

    public function testSourceOnlyPolicyRequiresGovernanceParametersAndRejectsUnknownPolicy(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $candidate = $this->repo->commit('release');

        self::assertSame('FAIL', $verifier->evaluateSourceOnlyPolicy(null, $this->repo->path, self::TARGET, $candidate, 'source-only')['status']);
        self::assertSame('FAIL', $verifier->evaluateSourceOnlyPolicy(null, $this->repo->path, self::TARGET, $candidate, 'zip-only')['status']);
        self::assertSame('PASS', $verifier->evaluateSourceOnlyPolicy(null, $this->repo->path, self::TARGET, $candidate, 'dist')['status']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{0: string, 1: string} decision commit, candidate commit
     */
    private function sourceOnlyRepo(string $authority = 'Owner-approved package delivery decision.'): array
    {
        $this->repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, authority: $authority));
        $this->repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
        $decisionCommit = $this->repo->commit('decision');
        $this->repo->write('CHANGELOG.md', "## [Unreleased]\n\n## [" . self::TARGET . "]\n- Initial feature set\n- more\n");
        $candidate = $this->repo->commit('release candidate');

        return [$decisionCommit, $candidate];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>|null}
     */
    private function policy(string $decisionCommit, string $candidate): array
    {
        return (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
            ['decision_id' => self::ID, 'decision_file' => Fx::decisionPath(self::ID), 'commit_ref' => $decisionCommit],
            $this->repo->path,
            self::TARGET,
            $candidate,
            'source-only',
            gmdate('Y-m-d\TH:i:s\Z'),
        );
    }
}
