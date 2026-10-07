<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceFixtures;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\GitRepository;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseContract;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseRepositoryFixture as Fx;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\SourceOnlyDecisionVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial tests for qualifying PAV: complete-evidence gate before network work,
 * approved-channel binding, exact resolved version, Composer isolation, retained
 * delivery metadata, and the source-only historical Decision chain.
 */
final class PublishedArtifactEvidenceTest extends TestCase
{
    private const string TARGET = '1.0.0-rc.3';
    private const string ID = 'DEC-100';
    private const string SUCCESSOR = 'DEC-101';

    private ?Fx $repo = null;

    protected function tearDown(): void
    {
        $this->repo?->cleanup();
        parent::tearDown();
    }

    // ------------------------------------------------ evidence gate before any network work

    public function testIncompleteEvidenceFailsBeforeAnyComposerWork(): void
    {
        $evidence = EvidenceFixtures::validDist();
        $manifest = $evidence['content_manifest'];
        self::assertIsArray($manifest);
        $evidence['content_manifest'] = array_slice($manifest, 0, 2, true);

        $result = (new PublishedArtifactVerifier())->verify(['qualification_evidence' => $evidence]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['qualification_evidence']['status']);
        self::assertArrayNotHasKey('composer_resolution', $result['checks']);
        self::assertSame('UNRESOLVED', $result['installation_mode']);
    }

    public function testUnapprovedCallerRepositoryFailsQualifyingPavBeforeNetworkWork(): void
    {
        $result = (new PublishedArtifactVerifier())->verify([
            'qualification_evidence' => EvidenceFixtures::validDist(),
            'composer_repository' => 'https://attacker.example.test/repo',
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['approved_channel']['status']);
        self::assertArrayNotHasKey('composer_resolution', $result['checks']);
    }

    public function testChannelBindingAcceptsOnlyTheQualificationBoundChannel(): void
    {
        $verifier = new PublishedArtifactVerifier();
        $bound = ReleaseContract::DEFAULT_DISTRIBUTION_CHANNEL;

        self::assertSame('PASS', $verifier->evaluateChannelBinding($bound, null)['status']);
        self::assertSame('PASS', $verifier->evaluateChannelBinding($bound, 'HTTPS://Repo.Packagist.org/')['status']);
        self::assertSame('FAIL', $verifier->evaluateChannelBinding($bound, 'https://repo.packagist.org.evil.test')['status']);
        self::assertSame('FAIL', $verifier->evaluateChannelBinding($bound, 'https://user:secret@mirror.example.test')['status']);
        self::assertStringNotContainsString('secret', $verifier->evaluateChannelBinding($bound, 'https://user:secret@mirror.example.test')['message']);
    }

    public function testQualifyingSourceOnlyPavFailsWhenRepositoryHistoryIsUnavailable(): void
    {
        $result = (new PublishedArtifactVerifier())->verify([
            'qualification_evidence' => EvidenceFixtures::validSourceOnly(),
            'repo_path' => '/nonexistent/path/for/history',
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['source_only_decision']['status']);
        self::assertStringContainsString('history is unavailable', $result['checks']['source_only_decision']['message']);
        self::assertArrayNotHasKey('composer_resolution', $result['checks']);
    }

    // ------------------------------------------------ exact resolved version

    public function testResolvedVersionMustEqualQualifiedTarget(): void
    {
        $verifier = new PublishedArtifactVerifier();
        $pkg = ['name' => 'maatify/php-rate-limiter', 'version' => 'v1.0.0-rc.3'];
        $lock = ['name' => 'maatify/php-rate-limiter', 'version' => 'v1.0.0-rc.3'];

        $ok = $verifier->evaluateResolvedVersion($pkg, $lock, 'maatify/php-rate-limiter', self::TARGET);
        self::assertSame('PASS', $ok['status']);
        self::assertSame(self::TARGET, $ok['details']['requested_version'] ?? null);
        self::assertSame('v1.0.0-rc.3', $ok['details']['resolved_version'] ?? null);
        self::assertSame('maatify/php-rate-limiter', $ok['details']['installed_package'] ?? null);

        self::assertSame('FAIL', $verifier->evaluateResolvedVersion(['name' => 'maatify/php-rate-limiter', 'version' => '1.0.0-rc.2'], null, 'maatify/php-rate-limiter', self::TARGET)['status']);
        self::assertSame('FAIL', $verifier->evaluateResolvedVersion(['name' => 'maatify/php-rate-limiter', 'version' => 'dev-main'], null, 'maatify/php-rate-limiter', self::TARGET)['status']);
        self::assertSame('FAIL', $verifier->evaluateResolvedVersion(['name' => 'maatify/php-rate-limiter'], null, 'maatify/php-rate-limiter', self::TARGET)['status']);
        self::assertSame('FAIL', $verifier->evaluateResolvedVersion(['name' => 'other/pkg', 'version' => self::TARGET], null, 'maatify/php-rate-limiter', self::TARGET)['status']);
        self::assertSame('FAIL', $verifier->evaluateResolvedVersion($pkg, ['name' => 'maatify/php-rate-limiter', 'version' => '1.0.0-rc.2'], 'maatify/php-rate-limiter', self::TARGET)['status']);
    }

    public function testAmbiguousInstalledMetadataThrows(): void
    {
        $dir = sys_get_temp_dir() . '/pav-amb-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $pkg = ['name' => 'maatify/php-rate-limiter', 'version' => self::TARGET];
        file_put_contents($dir . '/installed.json', (string) json_encode(['packages' => [$pkg, $pkg]]));

        try {
            $this->expectException(\RuntimeException::class);
            (new PublishedArtifactVerifier())->parseInstalledJson($dir . '/installed.json', 'maatify/php-rate-limiter');
        } finally {
            ReleaseContract::removeTree($dir);
        }
    }

    // ------------------------------------------------ Composer isolation and audit evidence

    public function testInheritedComposerStateCapableOfChangingResolutionIsClearedAndControlled(): void
    {
        $parent = [
            'PATH' => '/usr/bin:/bin',
            'HOME' => '/home/dev',
            'COMPOSER_HOME' => '/home/dev/.composer',
            'COMPOSER_AUTH' => '{"github-oauth":{"github.com":"ghp_SECRET"}}',
            'COMPOSER_MIRROR_PATH_REPOS' => '1',
            'COMPOSER_ROOT_VERSION' => '9.9.9',
            'COMPOSER_PREFER_STABLE' => '0',
            'COMPOSER_MINIMAL_CHANGES' => '1',
            'COMPOSER_WITH_ALL_DEPENDENCIES' => '1',
            'COMPOSER_VENDOR_DIR' => '/elsewhere/vendor',
            'COMPOSER' => '/elsewhere/composer.json',
            'COMPOSER_PREFERRED_INSTALL' => 'source',
            'HTTPS_PROXY' => 'http://proxy-user:proxy-pass@proxy.example.test:3128',
            'AWS_SECRET_ACCESS_KEY' => 'unrelated-secret',
        ];

        $built = (new PublishedArtifactVerifier())->buildComposerEnvironment('/tmp/root', $parent);
        $env = $built['env'];

        self::assertSame('/tmp/root/composer-home', $env['COMPOSER_HOME']);
        self::assertSame('/tmp/root/composer-cache', $env['COMPOSER_CACHE_DIR']);
        self::assertSame('/tmp/root/vendor', $env['COMPOSER_VENDOR_DIR']);
        self::assertSame('/tmp/root/composer.json', $env['COMPOSER']);
        self::assertSame('/tmp/root/home', $env['HOME']);
        self::assertSame('1', $env['COMPOSER_NO_INTERACTION']);

        foreach (['COMPOSER_AUTH', 'COMPOSER_MIRROR_PATH_REPOS', 'COMPOSER_ROOT_VERSION', 'COMPOSER_PREFER_STABLE', 'COMPOSER_MINIMAL_CHANGES', 'COMPOSER_WITH_ALL_DEPENDENCIES', 'COMPOSER_PREFERRED_INSTALL', 'AWS_SECRET_ACCESS_KEY'] as $name) {
            self::assertArrayNotHasKey($name, $env, $name);
        }

        $evidence = $built['evidence'];
        self::assertContains('COMPOSER_AUTH', $evidence['cleared']);
        self::assertContains('COMPOSER_PREFERRED_INSTALL', $evidence['cleared']);
        self::assertContains('COMPOSER_MIRROR_PATH_REPOS', $evidence['cleared']);
        self::assertSame(['HTTPS_PROXY'], $evidence['passthrough']);

        $serialized = (string) json_encode($evidence);
        foreach (['ghp_SECRET', 'proxy-pass', 'unrelated-secret', '/elsewhere', '/home/dev'] as $leak) {
            self::assertStringNotContainsString($leak, $serialized, 'audit evidence must carry names only, never inherited values');
        }
    }

    public function testConsumerManifestUsesOnlyTheBoundChannelAndRecordsInstallPreference(): void
    {
        $manifest = (new PublishedArtifactVerifier())->buildConsumerManifest('maatify/php-rate-limiter', self::TARGET, Fx::CHANNEL);

        self::assertSame([['type' => 'composer', 'url' => Fx::CHANNEL], ['packagist.org' => false]], $manifest['repositories']);
        self::assertSame('dist', $manifest['config']['preferred-install']);
        self::assertFalse($manifest['config']['allow-plugins']);
        self::assertSame(self::TARGET, $manifest['require']['maatify/php-rate-limiter']);
    }

    // ------------------------------------------------ delivery metadata retained

    public function testDistAndSourceMetadataAreRetainedAndRedacted(): void
    {
        $sha = str_repeat('a', 40);
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => self::TARGET,
            'installation-source' => 'dist',
            'install-path' => '../maatify/php-rate-limiter',
            'dist' => ['type' => 'zip', 'url' => 'https://user:pw@api.example.test/zipball/' . $sha . '?token=abc123&x=1', 'reference' => $sha],
            'source' => ['type' => 'git', 'url' => 'https://github.com/Maatify/php-rate-limiter.git', 'reference' => $sha],
        ];

        $eval = (new PublishedArtifactVerifier())->evaluateInstalledMetadata($pkg, $sha, 'dist', null);

        self::assertSame('dist', $eval['mode']);
        $d = $eval['delivery_evidence'];
        self::assertSame('dist', $d['installation_source']);
        self::assertNotNull($d['dist']);
        self::assertNotNull($d['source']);
        self::assertSame('zip', $d['dist']['type']);
        self::assertSame($sha, $d['dist']['reference']);
        self::assertSame('git', $d['source']['type']);
        self::assertSame($sha, $d['source']['reference']);
        self::assertSame(self::TARGET, $d['resolved_version']);
        self::assertSame('../maatify/php-rate-limiter', $d['install_path']);
        $serialized = (string) json_encode($d);
        self::assertStringNotContainsString('abc123', $serialized);
        self::assertStringNotContainsString('user:pw', $serialized);
        self::assertStringNotContainsString('pw@', $serialized);
    }

    public function testDistFallbackToSourceFailsEvenWithSourceOnlyEvidenceAndCorrectSourceContent(): void
    {
        $sha = str_repeat('a', 40);
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => self::TARGET,
            'installation-source' => 'source',
            'dist' => ['type' => 'zip', 'url' => 'https://example.test/z', 'reference' => $sha],
            'source' => ['type' => 'git', 'url' => 'https://example.test/r.git', 'reference' => $sha],
        ];

        $eval = (new PublishedArtifactVerifier())->evaluateInstalledMetadata($pkg, $sha, 'source-only', ['status' => 'PASS', 'message' => 'ok']);

        self::assertSame('FAIL', $eval['checks']['installation_mode']['status']);
        self::assertStringContainsString('fallback or preference failure is prohibited', $eval['checks']['installation_mode']['message']);
    }

    public function testInstalledJsonAndLockDisagreeingOnDistExposureIsAmbiguous(): void
    {
        $sha = str_repeat('a', 40);
        $pkg = ['name' => 'maatify/php-rate-limiter', 'version' => self::TARGET, 'installation-source' => 'dist', 'dist' => ['type' => 'zip', 'url' => 'https://e.test/z', 'reference' => $sha]];
        $lock = ['name' => 'maatify/php-rate-limiter', 'version' => self::TARGET, 'source' => ['type' => 'git', 'url' => 'https://e.test/r.git', 'reference' => $sha]];

        $eval = (new PublishedArtifactVerifier())->evaluateInstalledMetadata($pkg, $sha, 'dist', null, $lock);

        self::assertSame('FAIL', $eval['checks']['installation_mode']['status']);
        self::assertStringContainsString('disagree', $eval['checks']['installation_mode']['message']);
    }

    public function testSourceOnlyInstallRequiresExposedSourceMetadataAndPassingHistoricalCheck(): void
    {
        $sha = str_repeat('a', 40);
        $verifier = new PublishedArtifactVerifier();
        $good = ['name' => 'maatify/php-rate-limiter', 'version' => self::TARGET, 'installation-source' => 'source', 'source' => ['type' => 'git', 'url' => 'https://e.test/r.git', 'reference' => $sha]];

        self::assertSame('PASS', $verifier->evaluateInstalledMetadata($good, $sha, 'source-only', ['status' => 'PASS', 'message' => 'ok'])['checks']['installation_mode']['status']);
        self::assertSame('FAIL', $verifier->evaluateInstalledMetadata($good, $sha, 'source-only', ['status' => 'FAIL', 'message' => 'broken'])['checks']['installation_mode']['status']);
        self::assertSame('FAIL', $verifier->evaluateInstalledMetadata($good, $sha, 'source-only', null)['checks']['installation_mode']['status']);
        $noSource = ['name' => 'maatify/php-rate-limiter', 'version' => self::TARGET, 'installation-source' => 'source'];
        self::assertSame('FAIL', $verifier->evaluateInstalledMetadata($noSource, $sha, 'source-only', ['status' => 'PASS', 'message' => 'ok'])['checks']['installation_mode']['status']);
    }

    // ------------------------------------------------ source-only historical chain

    public function testHistoricalChainPassesWhenDecisionRemainsActive(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->commit('later work');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('PASS', $r['status'], $r['message']);
        self::assertSame('ACTIVE', $r['details']['current_status'] ?? null);
    }

    public function testHistoricalChainFailsWhenRepositoryHistoryIsUnavailable(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();

        $r = (new SourceOnlyDecisionVerifier())->verifyHistoricalChain(null, $evidence, self::TARGET, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('history is unavailable', $r['message']);
    }

    public function testHistoricalChainFailsWhenRetainedEvidenceDiffersFromImmutableHistory(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();

        $tampered = $evidence;
        $tampered['approved_distribution_channel'] = 'https://other.example.test';
        self::assertSame('FAIL', $this->chain($tampered, $candidate)['status']);

        $tampered = $evidence;
        $tampered['decision_commit'] = str_repeat('1', 40);
        self::assertSame('FAIL', $this->chain($tampered, $candidate)['status']);

        $tampered = $evidence;
        $tampered['record_blob_sha'] = str_repeat('2', 40);
        self::assertSame('FAIL', $this->chain($tampered, $candidate)['status']);
    }

    public function testHistoricalChainFailsWhenActiveRecordIsRewrittenAfterQualification(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, declaration: ['Version Scope' => '1.x']));
        $this->repo()->commit('silent rewrite');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('rewritten', $r['message']);
    }

    public function testHistoricalChainFailsWhenDecisionIsNoLongerDiscoverable(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([]));
        $this->repo()->commit('drop index row');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('no longer discoverable', $r['message']);
    }

    public function testHistoricalChainFailsWhenRecordFileIsRemovedFromCurrentHistory(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->remove(Fx::decisionPath(self::ID));
        $this->repo()->commit('remove record');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('no longer exists', $r['message']);
    }

    public function testHistoricalChainAcceptsLegitimateSupersessionWithCoherentSuccessor(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->supersede(successorStatus: 'ACTIVE');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('PASS', $r['status'], $r['message']);
        self::assertSame('SUPERSEDED', $r['details']['current_status'] ?? null);
        self::assertSame([self::ID, self::SUCCESSOR], $r['details']['chain'] ?? null);
    }

    public function testHistoricalChainFollowsMultiHopSupersessionToAnActiveDecision(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->supersede(successorStatus: 'SUPERSEDED', thirdActive: true);

        $r = $this->chain($evidence, $candidate);

        self::assertSame('PASS', $r['status'], $r['message']);
        self::assertSame([self::ID, self::SUCCESSOR, 'DEC-102'], $r['details']['chain'] ?? null);
    }

    public function testHistoricalChainFailsWhenSupersededButSuccessorRecordIsMissing(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->supersede(successorStatus: 'ACTIVE');
        $this->repo()->remove(Fx::decisionPath(self::SUCCESSOR));
        $this->repo()->commit('successor record deleted');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('record for "DEC-101" does not exist', $r['message']);
    }

    public function testHistoricalChainFailsWhenSuccessorIsMissingFromTheIndex(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->supersede(successorStatus: 'ACTIVE', indexSuccessor: false);

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('missing from DECISIONS_INDEX.md', $r['message']);
    }

    public function testHistoricalChainFailsOnAsymmetricSupersession(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->supersede(successorStatus: 'ACTIVE', successorClaimsSupersedes: false);

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('asymmetric', $r['message']);
    }

    public function testHistoricalChainFailsWhenSupersededWithoutNamedSuccessor(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, 'SUPERSEDED'));
        $this->repo()->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'SUPERSEDED']]));
        $this->repo()->commit('superseded with no successor');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('names no successor', $r['message']);
    }

    public function testHistoricalChainFailsWhenRecordAndIndexDisagreeOnSuccessor(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, 'SUPERSEDED', supersededBy: 'None.'));
        $this->repo()->write(Fx::decisionPath(self::SUCCESSOR), Fx::decisionRecord(self::SUCCESSOR, supersedes: self::ID));
        $this->repo()->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([
            ['id' => self::ID, 'status' => 'SUPERSEDED', 'superseded_by' => self::SUCCESSOR],
            ['id' => self::SUCCESSOR, 'status' => 'ACTIVE', 'supersedes' => self::ID],
        ]));
        $this->repo()->commit('inconsistent');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('disagree', $r['message']);
    }

    public function testHistoricalChainFailsOnSupersessionCycle(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, 'SUPERSEDED', supersedes: self::SUCCESSOR, supersededBy: self::SUCCESSOR));
        $this->repo()->write(Fx::decisionPath(self::SUCCESSOR), Fx::decisionRecord(self::SUCCESSOR, 'SUPERSEDED', supersedes: self::ID, supersededBy: self::ID));
        $this->repo()->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([
            ['id' => self::ID, 'status' => 'SUPERSEDED', 'supersedes' => self::SUCCESSOR, 'superseded_by' => self::SUCCESSOR],
            ['id' => self::SUCCESSOR, 'status' => 'SUPERSEDED', 'supersedes' => self::ID, 'superseded_by' => self::ID],
        ]));
        $this->repo()->commit('cycle');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('cycle', $r['message']);
    }

    public function testHistoricalChainFailsWhenSupersessionRewritesTheApprovedDeclaration(): void
    {
        [$evidence, $candidate] = $this->qualifiedSourceOnly();
        $this->repo()->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, 'SUPERSEDED', declaration: ['Approval Date' => '2026-09-01'], supersededBy: self::SUCCESSOR));
        $this->repo()->write(Fx::decisionPath(self::SUCCESSOR), Fx::decisionRecord(self::SUCCESSOR, supersedes: self::ID));
        $this->repo()->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([
            ['id' => self::ID, 'status' => 'SUPERSEDED', 'superseded_by' => self::SUCCESSOR],
            ['id' => self::SUCCESSOR, 'status' => 'ACTIVE', 'supersedes' => self::ID],
        ]));
        $this->repo()->commit('rewritten supersession');

        $r = $this->chain($evidence, $candidate);

        self::assertSame('FAIL', $r['status']);
        self::assertStringContainsString('rewritten', $r['message']);
    }

    // ------------------------------------------------ helpers

    private function repo(): Fx
    {
        if ($this->repo === null) {
            $this->repo = new Fx();
            $this->repo->populateRelease(self::TARGET);
        }

        return $this->repo;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} source-only evidence object and candidate SHA
     */
    private function qualifiedSourceOnly(): array
    {
        $repo = $this->repo();
        $repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID));
        $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
        $decisionCommit = $repo->commit('decision');
        $repo->write('CHANGELOG.md', "## [Unreleased]\n\n## [" . self::TARGET . "]\n- x\n");
        $candidate = $repo->commit('candidate');

        $q = (new SourceOnlyDecisionVerifier())->qualify(
            new GitRepository($repo->path),
            self::ID,
            Fx::decisionPath(self::ID),
            $decisionCommit,
            self::TARGET,
            $candidate,
            gmdate('Y-m-d\TH:i:s\Z'),
        );
        self::assertSame('PASS', $q['status'], $q['message']);

        return [$q['details'] ?? [], $candidate];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>}
     */
    private function chain(array $evidence, string $candidate): array
    {
        return (new SourceOnlyDecisionVerifier())->verifyHistoricalChain(new GitRepository($this->repo()->path), $evidence, self::TARGET, $candidate);
    }

    private function supersede(
        string $successorStatus,
        bool $thirdActive = false,
        bool $indexSuccessor = true,
        bool $successorClaimsSupersedes = true,
    ): void {
        $repo = $this->repo();
        $rows = [['id' => self::ID, 'status' => 'SUPERSEDED', 'superseded_by' => self::SUCCESSOR]];
        $repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, 'SUPERSEDED', supersededBy: self::SUCCESSOR));

        $repo->write(Fx::decisionPath(self::SUCCESSOR), Fx::decisionRecord(
            self::SUCCESSOR,
            $successorStatus,
            supersedes: $successorClaimsSupersedes ? self::ID : 'None.',
            supersededBy: $thirdActive ? 'DEC-102' : 'None.',
        ));
        if ($indexSuccessor) {
            $rows[] = ['id' => self::SUCCESSOR, 'status' => $successorStatus, 'supersedes' => $successorClaimsSupersedes ? self::ID : 'None', 'superseded_by' => $thirdActive ? 'DEC-102' : 'None'];
        }
        if ($thirdActive) {
            $repo->write(Fx::decisionPath('DEC-102'), Fx::decisionRecord('DEC-102', supersedes: self::SUCCESSOR));
            $rows[] = ['id' => 'DEC-102', 'status' => 'ACTIVE', 'supersedes' => self::SUCCESSOR];
        }

        $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex($rows));
        $repo->commit('supersession');
    }
}
