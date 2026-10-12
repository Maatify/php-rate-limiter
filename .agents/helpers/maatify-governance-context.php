#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Maatify Governance Context - Deterministic Read-Only Helper
 *
 * Exposes mechanically knowable repository and standards adoption state.
 *
 * Strictly read-only: no network access, no filesystem mutation, no Git write operations.
 * Deterministic JSON output with stable key ordering.
 *
 * Supported modes:
 *  - "canonical-source": Maatify/php-engineering-standards repository.
 *  - "selective-adoption": Consumer repository with a single usable STANDARDS_MANIFEST.md.
 */

final class GovernanceContextHelper
{
    private const CANONICAL_REPOSITORY_IDENTITY = 'github.com/maatify/php-engineering-standards';

    private string $rootDir;
    private string $validationMode;
    private ?string $sourceRootDir;
    /** @var list<string> */
    private array $errors = [];

    public function __construct(string $rootDir, string $validationMode = 'reconciliation', ?string $sourceRootDir = null)
    {
        $real = realpath($rootDir);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("Invalid repository root directory: {$rootDir}");
        }
        $this->rootDir = $real;

        if ($validationMode !== 'reconciliation' && $validationMode !== 'manifest-validation') {
            throw new InvalidArgumentException("Invalid validation mode: {$validationMode}. Allowed modes: 'reconciliation', 'manifest-validation'.");
        }
        $this->validationMode = $validationMode;

        if ($sourceRootDir !== null && $sourceRootDir !== '') {
            $realSource = realpath($sourceRootDir);
            $this->sourceRootDir = $realSource !== false && is_dir($realSource) ? $realSource : $sourceRootDir;
        } else {
            $this->sourceRootDir = null;
        }
    }

    /**
     * Resolves the Git repository root by walking upward from the current working directory,
     * or by validating an explicitly supplied root directory.
     */
    public static function resolveRepositoryRoot(?string $explicitRoot = null): ?string
    {
        if ($explicitRoot !== null && $explicitRoot !== '') {
            $real = realpath($explicitRoot);
            if ($real === false || !is_dir($real)) {
                return null;
            }
            if (is_dir($real . '/.git') || is_file($real . '/.git')) {
                return $real;
            }
            return null;
        }

        $dir = getcwd() ?: '.';
        $dir = realpath($dir) ?: $dir;

        while (true) {
            if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                // Reached filesystem root without finding .git
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(): array
    {
        $this->errors = [];

        $manifestRelPath = $this->locateUsableManifest();
        $isCanonicalSource = $this->detectCanonicalSource();

        if ($this->validationMode === 'manifest-validation') {
            if ($this->sourceRootDir === null || $this->sourceRootDir === '') {
                $this->errors[] = 'manifest-validation-source-root-missing: --source-root is required in manifest-validation mode';
            }
            if ($manifestRelPath === null) {
                $this->errors[] = 'manifest-validation-manifest-missing: valid consumer manifest is required in manifest-validation mode';
            }
        }

        if ($this->hasErrorPrefix('ambiguous-manifest')) {
            $mode = 'unresolved';
            $data = $this->buildEmptyData();
        } elseif ($manifestRelPath !== null) {
            $mode = 'selective-adoption';
            $data = $this->inspectSelectiveAdoption($manifestRelPath);
        } elseif ($isCanonicalSource && $this->validationMode !== 'manifest-validation') {
            $mode = 'canonical-source';
            $data = $this->inspectCanonicalSource();
        } else {
            $mode = 'unresolved';
            if ($this->validationMode !== 'manifest-validation') {
                $this->errors[] = 'no-manifest-outside-canonical-source';
            }
            $data = $this->buildEmptyData();
        }

        $mechanicalStatus = count($this->errors) === 0 ? 'VALID' : 'INVALID';

        sort($this->errors);

        $result = [
            'adoption_commit' => $data['adoption_commit'] ?? null,
            'decision_index' => $data['decision_index'] ?? null,
            'errors' => $this->errors,
            'explicit_exceptions_overrides' => $data['explicit_exceptions_overrides'] ?? [],
            'governance_mode' => $mode,
            'governance_surfaces' => $data['governance_surfaces'] ?? [],
            'indexed_active_decisions' => $data['indexed_active_decisions'] ?? [],
            'manifest' => $manifestRelPath,
            'mechanical_status' => $mechanicalStatus,
            'operational_assets' => $data['operational_assets'] ?? null,
            'overall_resolution_status' => $data['overall_resolution_status'] ?? null,
            'profile_activations' => $data['profile_activations'] ?? [],
            'resolved_applicable_standards' => $data['resolved_applicable_standards'] ?? [],
            'upstream_repository' => $data['upstream_repository'] ?? null,
            'validation_mode' => $this->validationMode,
        ];

        ksort($result);

        return $result;
    }

    /**
     * Locates a usable STANDARDS_MANIFEST.md candidate.
     * A candidate is usable only if its enclosing directory contains standards/STANDARDS_ADOPTION_STANDARD_AR.md.
     */
    private function locateUsableManifest(): ?string
    {
        $allCandidates = $this->findFilesByName($this->rootDir, 'STANDARDS_MANIFEST.md', ['.git', 'vendor', 'node_modules', 'tests', '.idea']);

        $usable = [];
        foreach ($allCandidates as $candAbs) {
            $candDir = dirname($candAbs);
            $controlFile = $candDir . '/standards/STANDARDS_ADOPTION_STANDARD_AR.md';
            if (is_file($controlFile)) {
                $usable[] = $this->toRelativePath($candAbs);
            }
        }

        if (count($usable) === 0) {
            return null;
        }

        if (count($usable) > 1) {
            $this->errors[] = 'ambiguous-manifest: multiple usable candidate manifests found (' . count($usable) . ')';
            return null;
        }

        return $usable[0];
    }

    private function detectCanonicalSource(?string $dir = null): bool
    {
        $target = $dir ?? $this->rootDir;
        $agents = $target . '/AGENTS.md';
        $adoptionStd = $target . '/standards/STANDARDS_ADOPTION_STANDARD_AR.md';
        $aiStd = $target . '/standards/ai/AI_COLLABORATION_WORKFLOW_AR.md';
        $decisionIndex = $target . '/docs/decisions/DECISIONS_INDEX.md';

        return is_file($agents) && is_file($adoptionStd) && is_file($aiStd) && is_file($decisionIndex);
    }

    private function verifyCanonicalSourceRepository(string $sourceRoot, ?string $expectedUpstreamRepo = null): bool
    {
        $real = realpath($sourceRoot);
        if ($real === false || !is_dir($real)) {
            return false;
        }

        // Must be a Git repository
        if (!is_dir($real . '/.git') && !is_file($real . '/.git')) {
            return false;
        }

        // Must have canonical governance surfaces
        if (!$this->detectCanonicalSource($real)) {
            return false;
        }

        // Upstream repository declared in manifest must match authoritative canonical repository identity
        if ($expectedUpstreamRepo === null || $expectedUpstreamRepo === '') {
            return false;
        }
        $normalizedExpected = $this->normalizeGitUrl($expectedUpstreamRepo);
        if ($normalizedExpected !== self::CANONICAL_REPOSITORY_IDENTITY) {
            return false;
        }

        // Verify Git repository identity via local git config
        $cmd = sprintf('git -C %s config --get-regexp %s 2>/dev/null', escapeshellarg($real), escapeshellarg('^remote\..*\.url$'));
        exec($cmd, $outUrls, $retUrls);

        $hasMatchingRemote = false;
        foreach ($outUrls as $line) {
            $parts = explode(' ', trim($line), 2);
            if (count($parts) === 2) {
                $url = trim($parts[1]);
                $normalizedUrl = $this->normalizeGitUrl($url);
                if ($normalizedUrl !== null && $normalizedUrl === self::CANONICAL_REPOSITORY_IDENTITY) {
                    $hasMatchingRemote = true;
                    break;
                }
            }
        }

        return $hasMatchingRemote;
    }

    private function normalizeGitUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Query and fragment are strictly forbidden on Git repository URLs
        if (str_contains($url, '?') || str_contains($url, '#')) {
            return null;
        }

        if (str_contains($url, '://')) {
            $parsed = parse_url($url);
            if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host']) || !isset($parsed['path'])) {
                return null;
            }

            if (isset($parsed['query']) || isset($parsed['fragment'])) {
                return null;
            }

            $scheme = strtolower($parsed['scheme']);
            $port = $parsed['port'] ?? null;

            if ($scheme === 'https') {
                if ($port !== null && $port !== 443) {
                    return null;
                }
            } elseif ($scheme === 'ssh') {
                if ($port !== null && $port !== 22) {
                    return null;
                }
            } else {
                return null;
            }

            $host = strtolower($parsed['host']);
            $rawPath = $parsed['path'];

            if (!preg_match('#^/([a-zA-Z0-9_.-]+)/([a-zA-Z0-9_.-]+)(/?)$#', $rawPath, $m)) {
                return null;
            }
        } elseif (preg_match('/^(?:[a-zA-Z0-9_.-]+@)?([a-zA-Z0-9_.-]+):(.+)$/', $url, $matchScp)) {
            $host = strtolower($matchScp[1]);
            $rawPath = $matchScp[2];

            if (!preg_match('#^([a-zA-Z0-9_.-]+)/([a-zA-Z0-9_.-]+)(/?)$#', $rawPath, $m)) {
                return null;
            }
        } else {
            return null;
        }

        $owner = strtolower($m[1]);
        $repo = strtolower($m[2]);

        if ($owner === '.' || $owner === '..' || $repo === '.' || $repo === '..' || $repo === '.git') {
            return null;
        }

        if (str_ends_with($repo, '.git')) {
            $repo = substr($repo, 0, -4);
        }

        if ($host === '' || $owner === '' || $repo === '' || $repo === '.' || $repo === '..') {
            return null;
        }

        return "{$host}/{$owner}/{$repo}";
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectCanonicalSource(): array
    {
        $governanceSurfaces = [];
        $knownSurfaces = [
            'AGENTS.md',
            'standards/STANDARDS_ADOPTION_STANDARD_AR.md',
            'standards/ai/AI_COLLABORATION_WORKFLOW_AR.md',
            'standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md',
            'standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md',
            'standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md',
            'standards/governance/STANDARD_VERSIONING_POLICY_AR.md',
            'docs/decisions/DECISIONS_INDEX.md',
        ];

        foreach ($knownSurfaces as $surface) {
            if (is_file($this->rootDir . '/' . $surface)) {
                $governanceSurfaces[] = $surface;
            }
        }
        sort($governanceSurfaces);

        $indexFull = $this->rootDir . '/docs/decisions/DECISIONS_INDEX.md';
        if (!is_file($indexFull)) {
            $this->errors[] = 'missing-required-decision-index: canonical source requires docs/decisions/DECISIONS_INDEX.md';
            $decisionIndexRel = null;
            $activeDecisions = [];
        } else {
            $decisionIndexRel = 'docs/decisions/DECISIONS_INDEX.md';
            $activeDecisions = $this->parseAndValidateDecisionIndex($indexFull);
        }

        return [
            'adoption_commit' => null,
            'decision_index' => $decisionIndexRel,
            'explicit_exceptions_overrides' => [],
            'governance_surfaces' => $governanceSurfaces,
            'indexed_active_decisions' => $activeDecisions,
            'operational_assets' => null,
            'overall_resolution_status' => null,
            'profile_activations' => [],
            'resolved_applicable_standards' => [],
            'upstream_repository' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectSelectiveAdoption(string $manifestRelPath): array
    {
        $manifestFull = $this->rootDir . '/' . $manifestRelPath;
        $content = (string) file_get_contents($manifestFull);
        $localStandardsRoot = dirname($manifestRelPath);

        // 1. Upstream repository
        $upstreamRepo = $this->extractUpstreamRepository($content);
        if ($upstreamRepo === null || $upstreamRepo === '') {
            $this->errors[] = 'malformed-manifest: missing Upstream Repository';
        }

        // 2. Adoption commit
        $adoptionCommit = $this->extractAdoptionCommit($content);
        if ($adoptionCommit === null || !preg_match('/^[0-9a-fA-F]{40}$/', $adoptionCommit)) {
            $this->errors[] = 'malformed-manifest: invalid or missing Adoption Commit';
        }

        // 3. Overall Resolution Status
        $resolutionStatus = $this->extractOverallResolutionStatus($content);
        if ($resolutionStatus === null || $resolutionStatus === '') {
            $this->errors[] = 'missing-overall-resolution-status';
        } elseif ($resolutionStatus !== 'VALID') {
            $this->errors[] = "incomplete-or-invalid-adoption: Overall Resolution Status is not VALID ('{$resolutionStatus}')";
        }

        // 4. Active Profiles
        $profileActivations = $this->extractProfileActivations($content);

        // 5. Resolved Applicable Standards
        $resolvedStandards = $this->extractAndVerifyResolvedStandards($content, $manifestRelPath);

        // 7. Explicit Exceptions / Overrides
        $exceptions = $this->extractExceptionsOverrides($content);

        // Manifest Validation source checkout verification (if in manifest-validation mode)
        $canonicalSourceVerified = false;
        if ($this->validationMode === 'manifest-validation') {
            if ($this->sourceRootDir === null || $this->sourceRootDir === '') {
                if (!in_array('manifest-validation-source-root-missing: --source-root is required in manifest-validation mode', $this->errors, true)) {
                    $this->errors[] = 'manifest-validation-source-root-missing: --source-root is required in manifest-validation mode';
                }
            } else {
                $normalizedManifestUpstream = $upstreamRepo !== null ? $this->normalizeGitUrl($upstreamRepo) : null;
                if ($normalizedManifestUpstream !== self::CANONICAL_REPOSITORY_IDENTITY) {
                    $this->errors[] = "invalid-manifest-upstream-repository: manifest Upstream Repository ('{$upstreamRepo}') does not match canonical standards repository identity";
                }
                if (!$this->verifyCanonicalSourceRepository($this->sourceRootDir, $upstreamRepo)) {
                    $this->errors[] = "invalid-canonical-source-repository: cannot verify canonical standards repository identity at {$this->sourceRootDir}";
                } else {
                    $canonicalSourceVerified = true;
                    if ($adoptionCommit !== null && preg_match('/^[0-9a-fA-F]{40}$/', $adoptionCommit)) {
                        $cmdCheck = sprintf('git -C %s cat-file -e %s^{commit} 2>/dev/null', escapeshellarg($this->sourceRootDir), escapeshellarg($adoptionCommit));
                        exec($cmdCheck, $dummy, $ret);
                        if ($ret !== 0) {
                            $this->errors[] = "authoritative-commit-not-found: commit {$adoptionCommit} not found in canonical source repository";
                        }
                    }
                }
            }
        }

        // 6. Operational Assets
        $operationalAssets = $this->extractAndVerifyOperationalAssets(
            $content,
            $adoptionCommit,
            $exceptions,
            $canonicalSourceVerified
        );

        // 8. Governance Surfaces in consumer
        $surfaces = [$manifestRelPath];
        if (is_file($this->rootDir . '/AGENTS.md')) {
            $surfaces[] = 'AGENTS.md';
        }
        $localAdoptionStd = $localStandardsRoot . '/standards/STANDARDS_ADOPTION_STANDARD_AR.md';
        if (is_file($this->rootDir . '/' . $localAdoptionStd)) {
            $surfaces[] = $localAdoptionStd;
        }
        $localDecisionIndex = 'docs/decisions/DECISIONS_INDEX.md';
        $hasLocalDecisionIndex = is_file($this->rootDir . '/' . $localDecisionIndex);
        if ($hasLocalDecisionIndex) {
            $surfaces[] = $localDecisionIndex;
        }
        sort($surfaces);

        // 9. Decision Index verification
        $decisionGovernanceRequired = $this->isDecisionGovernanceApplicable($resolvedStandards);
        $decisionIndexRel = null;
        $activeDecisions = [];

        if ($decisionGovernanceRequired && !$hasLocalDecisionIndex) {
            $this->errors[] = 'missing-required-decision-index: std-decision-governance is applicable';
        } elseif ($hasLocalDecisionIndex) {
            $decisionIndexRel = $localDecisionIndex;
            $activeDecisions = $this->parseAndValidateDecisionIndex($this->rootDir . '/' . $localDecisionIndex);
        }

        return [
            'adoption_commit' => $adoptionCommit,
            'decision_index' => $decisionIndexRel,
            'explicit_exceptions_overrides' => array_column($exceptions, 'display_line'),
            'governance_surfaces' => $surfaces,
            'indexed_active_decisions' => $activeDecisions,
            'operational_assets' => $operationalAssets,
            'overall_resolution_status' => $resolutionStatus,
            'profile_activations' => $profileActivations,
            'resolved_applicable_standards' => $resolvedStandards,
            'upstream_repository' => $upstreamRepo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmptyData(): array
    {
        return [
            'adoption_commit' => null,
            'decision_index' => null,
            'explicit_exceptions_overrides' => [],
            'governance_surfaces' => [],
            'indexed_active_decisions' => [],
            'operational_assets' => null,
            'overall_resolution_status' => null,
            'profile_activations' => [],
            'resolved_applicable_standards' => [],
            'upstream_repository' => null,
        ];
    }

    private function extractUpstreamRepository(string $content): ?string
    {
        // Real grammar: ## Upstream Repository followed by `repo` or repo
        if (preg_match('/(?:^|\n)##+[ \t]+Upstream Repository[ \t]*\n+[ \t]*`?([^`\r\n]+)`?/iu', $content, $m)) {
            return trim($m[1]);
        }
        // Fallback: bullet format
        return $this->extractHeaderValue($content, 'Upstream Repository');
    }

    private function extractAdoptionCommit(string $content): ?string
    {
        // Real grammar: ## Adoption Commit followed by `SHA`
        if (preg_match('/(?:^|\n)##+[ \t]+Adoption Commit[ \t]*\n+[ \t]*`?([0-9a-fA-F]{40})`?/iu', $content, $m)) {
            return trim($m[1]);
        }
        // Fallback: bullet format
        return $this->extractHeaderValue($content, 'Adoption Commit');
    }

    private function extractOverallResolutionStatus(string $content): ?string
    {
        if (preg_match('/^[ \t]*-[ \t]*(?:\*\*)?Overall Resolution Status:?(?:\*\*)?:?[ \t]*`?([^`\r\n]+?)`?[ \t]*$/im', $content, $m)) {
            return trim($m[1]);
        }
        return $this->extractHeaderValue($content, 'Overall Resolution Status');
    }

    private function extractHeaderValue(string $content, string $headerName): ?string
    {
        $pattern = '/^[ \t]*-[ \t]*(?:\*\*)?' . preg_quote($headerName, '/') . '(?::\*\*|\*\*:|:\*\*|\*\*|:)[ \t]*`?([^`\r\n]+?)`?[ \t]*$/im';
        if (preg_match($pattern, $content, $m)) {
            return trim($m[1]);
        }

        $pattern2 = '/^[ \t]*(?:\*\*)?' . preg_quote($headerName, '/') . '(?::\*\*|\*\*:|:\*\*|\*\*|:)[ \t]*`?([^`\r\n]+?)`?[ \t]*$/im';
        if (preg_match($pattern2, $content, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @return list<array{profile_id: string, scope: string, version: string}>
     */
    private function extractProfileActivations(string $content): array
    {
        $activations = [];

        // Real grammar: ### Activation \d+: `profile-id`
        if (preg_match_all('/(?:^|\n)###+[ \t]+Activation\s+\d+:\s+`?([a-z0-9_-]+)`?(.*?)(?=(?:\n###|\n##|\z))/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $profileId = trim($m[1]);
                $block = $m[2];

                $version = '';
                if (preg_match('/-[ \t]*\*\*Profile Version:\*\*[ \t]*`?([^`\r\n]+)`?/i', $block, $vM)) {
                    $version = trim($vM[1]);
                }

                $scope = '/';
                if (preg_match('/-[ \t]*\*\*Scope:\*\*[ \t]*`?([^`\r\n]+)`?/i', $block, $sM)) {
                    $scope = trim($sM[1]);
                }

                $resStatus = null;
                if (preg_match('/-[ \t]*\*\*Resolution Status:\*\*[ \t]*`?([^`\r\n]+)`?/i', $block, $rM)) {
                    $resStatus = trim($rM[1]);
                }

                if ($resStatus === null || $resStatus === '') {
                    $this->errors[] = "missing-profile-activation-status: {$profileId}";
                } elseif ($resStatus !== 'VALID') {
                    $this->errors[] = "invalid-profile-activation-status: {$profileId} status is '{$resStatus}'";
                }

                $activations[] = [
                    'profile_id' => $profileId,
                    'scope' => $scope,
                    'version' => $version,
                ];
            }
        }

        // Fallback: bullet syntax - `profile` (v1.0.0) @ `/`
        if (count($activations) === 0 && preg_match_all('/(?:^|\n)[ \t]*-[ \t]*`?([a-z0-9_-]+)`?[ \t]*(?:\(v?([0-9.]+)\))?[ \t]*(?:@|scope:)[ \t]*`?([^`\r\n]+)`?/i', $content, $matchesList, PREG_SET_ORDER)) {
            foreach ($matchesList as $m) {
                $activations[] = [
                    'profile_id' => trim($m[1]),
                    'scope' => trim($m[3] ?? '/'),
                    'version' => trim($m[2] ?? ''),
                ];
            }
        }

        // Fallback: table syntax | Profile ID | Version | Scope |
        if (count($activations) === 0 && preg_match_all('/^\|[ \t]*`?([a-z0-9_-]+)`?[ \t]*\|[ \t]*`?v?([0-9.]+)`?[ \t]*\|[ \t]*`?([^|\r\n]+)`?[ \t]*\|/m', $content, $matchesTable, PREG_SET_ORDER)) {
            foreach ($matchesTable as $m) {
                if (strtolower($m[1]) === 'profile id' || str_contains($m[1], '---')) {
                    continue;
                }
                $activations[] = [
                    'profile_id' => trim($m[1]),
                    'scope' => trim($m[3]),
                    'version' => trim($m[2]),
                ];
            }
        }

        usort($activations, static fn (array $a, array $b): int => strcmp($a['profile_id'] . $a['scope'], $b['profile_id'] . $b['scope']));
        return $activations;
    }

    /**
     * @return list<array{local_path: string, recorded_version: string, standard_id: ?string}>
     */
    private function extractAndVerifyResolvedStandards(string $content, string $manifestRelPath): array
    {
        if (!preg_match('/(?:^|\n)##+[ \t]+[^\r\n]*(?:Final Resolved Applicable Standards Set|Resolved Applicable Standards Set|Applicable Standards)[^\r\n]*\r?\n(.*?)(?=\r?\n##|\z)/is', $content, $sectionMatch)) {
            return [];
        }

        $sectionBody = $sectionMatch[1];
        if (preg_match('/```(?:text)?\s*\n(.*?)\n```/is', $sectionBody, $fencedMatch)) {
            $standards = [];
            foreach (preg_split('/\r\n|\n|\r/', trim($fencedMatch[1])) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (!preg_match('/^([a-zA-Z0-9_\-.\/]+\.md)\s+version\s+([0-9]+\.[0-9]+\.[0-9]+)$/i', $line, $matches)) {
                    $this->errors[] = 'malformed-resolved-standard-row: cannot parse fenced entry';
                    continue;
                }
                $standards[] = $this->verifySingleResolvedStandard($matches[1], $matches[2], $manifestRelPath);
            }
            return $this->sortResolvedStandards($standards);
        }

        $lines = preg_split('/\r\n|\n|\r/', $sectionBody) ?: [];
        $hasTableRows = false;
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '|')) {
                $hasTableRows = true;
                break;
            }
        }

        if ($hasTableRows) {
            return $this->extractResolvedStandardsTable($lines, $manifestRelPath);
        }

        $standards = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^-[ \t]+(?:None|Empty)[ \t]*$/i', $line)) {
                continue;
            }

            if (preg_match('/^-[ \t]+\[([^\]]+)\]\(([^()]*)\)[ \t]+\((?:version:?[ \t]*)?v?([0-9]+\.[0-9]+\.[0-9]+)\)$/i', $line, $matches)) {
                $standards[] = $this->verifySingleResolvedStandard($matches[2], $matches[3], $manifestRelPath, true);
                continue;
            }
            if (preg_match('/^-[ \t]+`?([a-zA-Z0-9_\-.\/]+\.md)`?(?:[ \t]*\([ \t]*(?:version:?[ \t]*)?v?([0-9]+\.[0-9]+\.[0-9]+)[ \t]*\)|[ \t]+v?([0-9]+\.[0-9]+\.[0-9]+))?$/i', $line, $matches)) {
                $recordedVersion = !empty($matches[2]) ? $matches[2] : (!empty($matches[3]) ? $matches[3] : null);
                $standards[] = $this->verifySingleResolvedStandard($matches[1], $recordedVersion, $manifestRelPath);
                continue;
            }
            if (str_starts_with($line, '-') || str_contains($line, '.md') || str_contains($line, '](')) {
                $this->errors[] = 'malformed-resolved-standard-row: cannot parse declared list entry';
            }
        }

        return $this->sortResolvedStandards($standards);
    }

    /**
     * @param list<string> $lines
     * @return list<array{local_path: string, recorded_version: string, standard_id: ?string}>
     */
    private function extractResolvedStandardsTable(array $lines, string $manifestRelPath): array
    {
        $rows = [];
        $preamble = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '|')) {
                $rows[] = $line;
                continue;
            }
            if ($rows === []) {
                $preamble[] = $line;
                continue;
            }
            $rows[] = $line;
        }

        foreach ($preamble as $line) {
            if (str_starts_with($line, '-') || str_contains($line, '.md') || str_contains($line, '](')) {
                $this->errors[] = 'malformed-resolved-standards-table: declared Standard row appears before table header';
                return [];
            }
        }
        if (count($rows) < 2 || !str_starts_with($rows[0], '|')) {
            $this->errors[] = 'malformed-resolved-standards-table: missing header or separator';
            return [];
        }

        $header = $this->parsePipeTableRow($rows[0]);
        if ($header === null || !in_array(count($header), [2, 4], true)) {
            $this->errors[] = 'malformed-resolved-standards-table: unsupported header column count';
            return [];
        }
        $normalizedHeaders = array_map(static fn (string $cell): string => strtolower(trim($cell, "`* \t")), $header);
        $standardColumn = null;
        $versionColumn = null;
        $standardIdColumn = null;
        $provenanceColumn = null;
        foreach ($normalizedHeaders as $index => $label) {
            if (in_array($label, ['standard', 'standard path', 'standard file', 'standard reference', 'applicable standard', 'resolved applicable standard'], true)) {
                if ($standardColumn !== null || $standardIdColumn !== null) {
                    $standardColumn = -1;
                    break;
                }
                $standardColumn = $index;
            }
            if ($label === 'local path') {
                if ($standardColumn !== null) {
                    $standardColumn = -1;
                    break;
                }
                $standardColumn = $index;
            }
            if (in_array($label, ['version', 'standard version'], true)) {
                if ($versionColumn !== null) {
                    $versionColumn = -1;
                    break;
                }
                $versionColumn = $index;
            }
            if ($label === 'standard id') {
                if ($standardIdColumn !== null) {
                    $standardIdColumn = -1;
                    break;
                }
                $standardIdColumn = $index;
            }
            if ($label === 'resolution provenance') {
                if ($provenanceColumn !== null) {
                    $provenanceColumn = -1;
                    break;
                }
                $provenanceColumn = $index;
            }
        }
        $isTwoColumnSchema = count($header) === 2
            && $standardColumn !== null && $standardColumn >= 0
            && $standardIdColumn === null && $provenanceColumn === null;
        $isFourColumnSchema = count($header) === 4
            && $standardColumn !== null && $standardColumn >= 0
            && $standardIdColumn !== null && $standardIdColumn >= 0
            && $provenanceColumn !== null && $provenanceColumn >= 0;
        if ((!$isTwoColumnSchema && !$isFourColumnSchema)
            || $versionColumn === null || $versionColumn < 0
            || count(array_unique(array_filter([$standardColumn, $versionColumn, $standardIdColumn, $provenanceColumn], static fn ($column): bool => $column !== null))) !== count(array_filter([$standardColumn, $versionColumn, $standardIdColumn, $provenanceColumn], static fn ($column): bool => $column !== null))) {
            $this->errors[] = 'malformed-resolved-standards-table: header must identify the supported Standard/Version schema';
            return [];
        }

        $separator = $this->parsePipeTableRow($rows[1]);
        if ($separator === null || count($separator) !== count($header)) {
            $this->errors[] = 'malformed-resolved-standards-table: missing or malformed separator';
            return [];
        }
        foreach ($separator as $cell) {
            if (!preg_match('/^:?-{3,}:?$/', trim($cell))) {
                $this->errors[] = 'malformed-resolved-standards-table: missing or malformed separator';
                return [];
            }
        }

        $standards = [];
        $seenTargets = [];
        foreach (array_slice($rows, 2) as $row) {
            $cells = $this->parsePipeTableRow($row);
            if ($cells === null || count($cells) !== count($header)) {
                $this->errors[] = 'malformed-resolved-standard-row: inconsistent table column count';
                continue;
            }

            $standardCell = trim($cells[$standardColumn]);
            $versionCell = trim($cells[$versionColumn]);
            $recordedStandardId = $standardIdColumn !== null ? trim($cells[$standardIdColumn], "` \t") : null;
            foreach ($cells as $cellIndex => $cell) {
                if ($cellIndex !== $standardColumn && preg_match('/\[[^\]]*\]\([^)]*\)/', $cell)) {
                    $this->errors[] = 'malformed-resolved-standard-row: Markdown link is outside the Standard path cell';
                    continue 2;
                }
            }
            if ($standardIdColumn !== null && !preg_match('/^std-[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $recordedStandardId)) {
                $this->errors[] = 'malformed-resolved-standard-row: Standard ID cell is invalid';
                continue;
            }
            if ($provenanceColumn !== null && trim($cells[$provenanceColumn]) === '') {
                $this->errors[] = 'malformed-resolved-standard-row: Resolution provenance cell is empty';
                continue;
            }
            $linkCount = preg_match_all('/\[[^\]]*\]\([^)]*\)/', $standardCell);
            if ($linkCount > 1) {
                $this->errors[] = 'malformed-resolved-standard-row: multiple Standard links in one row';
                continue;
            }
            if ($standardIdColumn !== null && $linkCount !== 1) {
                $this->errors[] = 'malformed-resolved-standard-row: Local path cell must contain one Markdown link';
                continue;
            }
            if ($linkCount === 1) {
                if (!preg_match('/^\[([^\]]+)\]\(([^()]*)\)$/', $standardCell, $linkMatches)) {
                    $this->errors[] = 'malformed-resolved-standard-row: ambiguous Markdown link cell';
                    continue;
                }
                $path = $linkMatches[2];
                $relativeLink = true;
            } else {
                if (!preg_match('/^`?([a-zA-Z0-9_\-.\/]+\.md)`?$/', $standardCell, $pathMatches)) {
                    $this->errors[] = 'malformed-resolved-standard-row: Standard cell must contain one local path or Markdown link';
                    continue;
                }
                $path = $pathMatches[1];
                $relativeLink = false;
            }
            if (preg_match('/\[[^\]]*\]\([^)]*\)/', $versionCell) || !preg_match('/^`?([0-9]+\.[0-9]+\.[0-9]+)`?$/', $versionCell, $versionMatches)) {
                $this->errors[] = 'malformed-resolved-standard-row: Version cell must contain one MAJOR.MINOR.PATCH value';
                continue;
            }
            if (isset($seenTargets[$path])) {
                $this->errors[] = 'malformed-resolved-standard-row: duplicate Standard path';
                continue;
            }
            $seenTargets[$path] = true;
            $standards[] = $this->verifySingleResolvedStandard($path, $versionMatches[1], $manifestRelPath, $relativeLink, $recordedStandardId);
        }

        return $this->sortResolvedStandards($standards);
    }

    /** @return list<string>|null */
    private function parsePipeTableRow(string $line): ?array
    {
        if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
            return null;
        }
        $cells = explode('|', substr($line, 1, -1));
        if (count($cells) < 2) {
            return null;
        }
        return array_map('trim', $cells);
    }

    /** @param list<array{local_path: string, recorded_version: string, standard_id: ?string}> $standards */
    private function sortResolvedStandards(array $standards): array
    {
        usort($standards, static fn (array $a, array $b): int => strcmp($a['local_path'], $b['local_path']));
        return $standards;
    }

    /**
     * @return array{local_path: string, recorded_version: string, standard_id: ?string}
     */
    private function verifySingleResolvedStandard(string $extractedPath, ?string $extractedVersion, string $manifestRelPath, bool $relativeLink = false, ?string $recordedStandardId = null): array
    {
        $normalizedPath = $this->normalizeResolvedStandardPath($extractedPath, $manifestRelPath, $relativeLink);
        if ($normalizedPath === null) {
            $this->errors[] = "invalid-local-standard-path: {$extractedPath}";
            return [
                'local_path' => $extractedPath,
                'recorded_version' => (string) $extractedVersion,
                'standard_id' => null,
            ];
        }
        $extractedPath = $normalizedPath;
        $fullPath = $this->rootDir . '/' . $extractedPath;
        if (!is_file($fullPath)) {
            $this->errors[] = "missing-local-standard: {$extractedPath}";
            return [
                'local_path' => $extractedPath,
                'recorded_version' => (string) $extractedVersion,
                'standard_id' => null,
            ];
        }

        $stdContent = (string) file_get_contents($fullPath);
        $idFieldCount = preg_match_all('/^\s*(?:[-*]\s*)?\*{0,2}Standard ID:\*{0,2}\s*(.*?)\s*$/im', $stdContent, $idFields);
        $localStdId = $idFieldCount === 1 ? trim($idFields[1][0], "` \t") : null;
        if ($localStdId === null || !preg_match('/^std-[a-z0-9]+(?:-[a-z0-9]+)*$/', $localStdId)) {
            $this->errors[] = "missing-or-invalid-standard-id: {$extractedPath}";
            $localStdId = null;
        } elseif ($recordedStandardId !== null && $recordedStandardId !== $localStdId) {
            $this->errors[] = "standard-id-mismatch: {$extractedPath} expected {$recordedStandardId} but found {$localStdId}";
        }

        $versionFieldCount = preg_match_all('/^\s*(?:[-*]\s*)?\*{0,2}Standard Version:\*{0,2}\s*(.*?)\s*$/im', $stdContent, $versionFields);
        $localStdVersion = $versionFieldCount === 1 ? trim($versionFields[1][0], "` \t") : null;
        if ($localStdVersion === null || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $localStdVersion)) {
            $this->errors[] = "missing-or-invalid-standard-version: {$extractedPath}";
            $localStdVersion = null;
        }

        if ($extractedVersion !== null && $localStdVersion !== null && $extractedVersion !== $localStdVersion) {
            $this->errors[] = "standard-version-mismatch: {$extractedPath} expected {$extractedVersion} but found {$localStdVersion}";
        }

        return [
            'local_path' => $extractedPath,
            'recorded_version' => $extractedVersion ?? $localStdVersion ?? '',
            'standard_id' => $localStdId,
        ];
    }

    private function normalizeResolvedStandardPath(string $path, string $manifestRelPath, bool $relativeLink): ?string
    {
        if ($path === '' || str_contains($path, '?') || str_contains($path, '#') || str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path)) {
            return null;
        }

        $base = $relativeLink ? dirname($manifestRelPath) : '';
        $parts = $base === '.' || $base === '' ? [] : explode('/', $base);
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $normalized = implode('/', $parts);
        if (!str_ends_with(strtolower($normalized), '.md')) {
            return null;
        }

        $fullPath = $this->rootDir . '/' . $normalized;
        $realRoot = realpath($this->rootDir);
        $realFile = realpath($fullPath);
        if ($realFile !== false && ($realRoot === false || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR))) {
            return null;
        }
        if ($realRoot === false || $realFile === false || !is_file($realFile)) {
            return $normalized;
        }
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($realFile, strlen($realRoot) + 1));
    }

    /**
     * @param list<array{local_path: string, recorded_version: string, standard_id: ?string}> $resolvedStandards
     */
    private function isDecisionGovernanceApplicable(array $resolvedStandards): bool
    {
        foreach ($resolvedStandards as $std) {
            if ($std['standard_id'] === 'std-decision-governance') {
                return true;
            }
            if (str_ends_with($std['local_path'], 'DECISION_GOVERNANCE_STANDARD_AR.md')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{canonical_contract_owner: string, decision_id: string, decision_record: string, record_exists: bool, scope_concern: string, status: string, title: string}>
     */
    private function parseAndValidateDecisionIndex(string $indexFile): array
    {
        $content = file_get_contents($indexFile);
        $activeDecisions = [];
        $seenIds = [];
        $recognizedStatuses = ['PROPOSED', 'ACTIVE', 'SUPERSEDED', 'REJECTED', 'DEFERRED'];
        if ($content === false) {
            $this->errors[] = 'malformed-decision-index: cannot read index file';
            return [];
        }

        $lines = preg_split('/\r\n|\n/', $content) ?: [];
        $expectedHeader = ['Decision ID', 'Title', 'Status', 'Scope / Concern', 'Decision Record', 'Canonical Contract / Current Owner', 'Supersedes', 'Superseded By'];
        $headerIndex = null;
        $headerCells = null;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (!str_starts_with(trim($line), '|')) {
                continue;
            }
            $headerCells = $this->parseDecisionIndexRow($line);
            $headerIndex = $index;
            break;
        }
        if ($headerIndex === null || $headerCells !== $expectedHeader) {
            $this->errors[] = 'malformed-decision-index: missing or invalid canonical table header';
            return [];
        }

        $separatorIndex = $headerIndex + 1;
        $separatorCells = isset($lines[$separatorIndex]) ? $this->parseDecisionIndexRow($lines[$separatorIndex]) : null;
        if ($separatorCells === null || count($separatorCells) !== count($expectedHeader)
            || array_filter($separatorCells, static fn (string $cell): bool => preg_match('/^:?-{3,}:?$/D', $cell) !== 1) !== []
        ) {
            $this->errors[] = 'malformed-decision-index: missing or invalid table separator';
            return [];
        }

        $tableEnded = false;
        for ($lineIndex = $separatorIndex + 1; $lineIndex < count($lines); $lineIndex++) {
            $line = $lines[$lineIndex];
            if (trim($line) === '') {
                continue;
            }
            if (!str_starts_with(trim($line), '|')) {
                if (str_contains($line, '|') && preg_match('/\bDEC-[A-Za-z0-9-]+\b/', $line, $malformedId) === 1) {
                    $decisionId = $malformedId[0];
                    $this->errors[] = isset($seenIds[$decisionId])
                        ? "duplicate-decision-id: {$decisionId}"
                        : "malformed-decision-index-row: {$decisionId} is not a table row";
                    $seenIds[$decisionId] = true;
                }
                $tableEnded = true;
                continue;
            }
            if ($tableEnded) {
                $this->errors[] = 'malformed-decision-index: non-contiguous table data row';
            }

            $cols = $this->parseDecisionIndexRow($line);
            $rawCells = explode('|', trim($line, '|'));
            $decisionId = $cols[0] ?? trim($rawCells[0] ?? '', " `\t");
            if ($decisionId === '' || $decisionId === 'Decision ID') {
                $this->errors[] = 'malformed-decision-index-row: missing or invalid Decision ID';
            } elseif (isset($seenIds[$decisionId])) {
                $this->errors[] = "duplicate-decision-id: {$decisionId}";
            } else {
                $seenIds[$decisionId] = true;
            }

            if ($cols === null || count($cols) !== count($expectedHeader)) {
                $count = $cols === null ? count($rawCells) : count($cols);
                $this->errors[] = "malformed-decision-index-row: {$decisionId} expected 8 columns, got {$count}";
                continue;
            }

            [$decisionId, $title, $rawStatus, $scopeConcern, $recordCell, $canonicalOwner] = array_slice($cols, 0, 6);
            $status = $rawStatus;
            if ($title === '' || $status === '' || $scopeConcern === '' || $recordCell === '' || $canonicalOwner === '') {
                $this->errors[] = "malformed-decision-index-row: {$decisionId} has an empty required cell";
            }
            if (!in_array($status, $recognizedStatuses, true)) {
                $this->errors[] = "invalid-decision-status: {$decisionId} has unrecognized status '{$status}'";
            }

            if ($status === 'ACTIVE') {
                $recordRel = $this->extractDecisionRecordPath($recordCell, $indexFile);
                if ($recordRel === null || $recordRel === '') {
                    $this->errors[] = "broken-decision-record-reference: {$decisionId} missing or unparseable record reference";
                    $exists = false;
                } else {
                    $fullRecordPath = $this->rootDir . '/' . $recordRel;
                    $exists = is_file($fullRecordPath);
                    if (!$exists) {
                        $this->errors[] = "missing-decision-record: {$decisionId} record file '{$recordRel}' does not exist";
                    }
                }

                $activeDecisions[] = [
                    'canonical_contract_owner' => $canonicalOwner,
                    'decision_id' => $decisionId,
                    'decision_record' => $recordRel ?? $recordCell,
                    'record_exists' => $exists,
                    'scope_concern' => $scopeConcern,
                    'status' => 'ACTIVE',
                    'title' => $title,
                ];
            }
        }

        usort($activeDecisions, static fn (array $a, array $b): int => strcmp($a['decision_id'], $b['decision_id']));
        return $activeDecisions;
    }

    /** @return list<string>|null */
    private function parseDecisionIndexRow(string $line): ?array
    {
        $line = trim($line);
        if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
            return null;
        }
        $cells = array_map(static fn (string $cell): string => trim($cell, " `\t"), explode('|', substr($line, 1, -1)));
        return $cells;
    }

    private function extractDecisionRecordPath(string $cell, string $indexFile): ?string
    {
        $consumerRoot = realpath($this->rootDir);
        $expectedDocsDirectory = $this->rootDir . '/docs';
        $expectedDecisionDirectory = $expectedDocsDirectory . '/decisions';
        $docsRealPath = realpath($expectedDocsDirectory);
        $decisionDirectory = realpath($this->rootDir . '/docs/decisions');
        $indexRealPath = realpath($indexFile);
        if ($consumerRoot === false || $docsRealPath !== $expectedDocsDirectory
            || $decisionDirectory !== $expectedDecisionDirectory || $indexRealPath === false
            || !$this->isPathWithin($decisionDirectory, $consumerRoot)
            || !$this->isPathWithin($indexRealPath, $decisionDirectory)
        ) {
            return null;
        }

        $rawPath = null;
        if (preg_match('/\[.*?\]\((.*?)\)/', $cell, $m)) {
            $rawPath = trim(explode('—', $m[1])[0]);
        } elseif (str_ends_with($cell, '.md')) {
            $rawPath = trim($cell);
        }

        if ($rawPath === null || $rawPath === '' || str_contains($rawPath, '\\')
            || str_contains($rawPath, '?') || str_contains($rawPath, '#')
            || str_starts_with($rawPath, '/') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $rawPath)
        ) {
            return null;
        }
        $rawPath = preg_replace('~^\./~', '', $rawPath) ?? $rawPath;
        if ($rawPath === '' || str_contains($rawPath, '//')) {
            return null;
        }
        foreach (explode('/', $rawPath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        if (!str_ends_with(strtolower($rawPath), '.md')) {
            return null;
        }

        $resolved = realpath($decisionDirectory . '/' . $rawPath);
        if ($resolved === false || !is_file($resolved) || !$this->isPathWithin($resolved, $decisionDirectory)
            || !$this->isPathWithin($resolved, $consumerRoot)
        ) {
            return null;
        }
        return $this->toRelativePath($resolved);
    }

    private function isPathWithin(string $path, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        return str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<array{kind: string, local_path: string, sha256: string, upstream_path: string}>|null
     */
    private function extractAndVerifyOperationalAssets(
        string $content,
        ?string $defaultAdoptionCommit = null,
        array $exceptions = [],
        bool $canonicalSourceVerified = false
    ): ?array {
        if (!preg_match('/(?:^|\n)##+[ \t]+Pinned Operational Assets Set[ \t]*(?:\n|\r\n)(.*?)(?=\n##|\z)/is', $content, $sectionMatch)) {
            return null;
        }

        $sectionBody = trim($sectionMatch[1]);
        if ($sectionBody === '') {
            $this->errors[] = 'malformed-operational-assets-inventory-empty';
            return [];
        }

        $lines = explode("\n", $sectionBody);
        $headerFound = false;
        $separatorFound = false;
        $assets = [];
        $seenLocalPaths = [];

        // Resolve only canonical, exact-format operational-asset exception records.
        $authoritativeSourceIdentity = $defaultAdoptionCommit;
        $allValidRecords = [];
        $invalidOperationalException = false;
        foreach ($exceptions as $exception) {
            $ex = $exception['record_line'];
            $declaresOperationalException = $exception['declares_operational_asset'];
            if ($declaresOperationalException) {
                if (!$exception['canonical_list_marker'] || !$exception['canonical_whitespace']) {
                    $this->errors[] = $exception['format_error'];
                    $invalidOperationalException = true;
                    continue;
                }
                $record = $this->parseOperationalAssetException($ex);
                if ($record === null) {
                    preg_match_all('/[0-9a-fA-F]{40}/', $ex, $badRecordShas);
                    $this->errors[] = count(array_unique($badRecordShas[0])) > 1
                        ? 'ambiguous-operational-asset-exception: multiple commit SHAs in canonical record'
                        : 'invalid-operational-asset-exception: malformed or incomplete canonical record';
                    $invalidOperationalException = true;
                    continue;
                }
                $allValidRecords[] = $record;
            }
        }
        $seenExceptionPairs = [];
        foreach ($allValidRecords as $record) {
            $pair = $record['upstream_path'] . "\0" . $record['local_path'];
            if (isset($seenExceptionPairs[$pair])) {
                $this->errors[] = 'ambiguous-operational-asset-exception: duplicate or conflicting record for exact path pair';
                $invalidOperationalException = true;
                continue;
            }
            $seenExceptionPairs[$pair] = true;
            if (!$this->validateConsumerExceptionDecision($record['decision_id'], $record)) {
                $this->errors[] = "invalid-operational-asset-exception: Decision-ID {$record['decision_id']} is not an indexed active consumer decision with matching approval scope";
                $invalidOperationalException = true;
                continue;
            }
        }
        if ($invalidOperationalException) {
            $authoritativeSourceIdentity = null;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (!$headerFound) {
                if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
                    $this->errors[] = "unsupported-or-ambiguous-operational-assets-representation: unexpected non-table content before header: '{$line}'";
                    return [];
                }

                $cols = array_map('trim', explode('|', trim($line, '|')));
                $normalizedCols = array_map(static fn (string $c): string => strtolower(trim($c, '` *')), $cols);
                if ($normalizedCols === ['kind', 'upstream path', 'local path', 'sha-256']) {
                    $headerFound = true;
                    continue;
                }
                $this->errors[] = 'unsupported-or-ambiguous-operational-assets-representation: invalid table header';
                return [];
            }

            if (!$separatorFound) {
                if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
                    $this->errors[] = "unsupported-or-ambiguous-operational-assets-representation: missing or invalid table separator: '{$line}'";
                    return [];
                }

                $sepCols = array_map('trim', explode('|', trim($line, '|')));
                if (count($sepCols) !== 4) {
                    $this->errors[] = 'unsupported-or-ambiguous-operational-assets-representation: missing or invalid table separator: expected 4 separator columns';
                    return [];
                }

                $validSep = true;
                foreach ($sepCols as $sc) {
                    if (!preg_match('/^:?-+:?$/', $sc)) {
                        $validSep = false;
                        break;
                    }
                }

                if (!$validSep) {
                    $this->errors[] = "unsupported-or-ambiguous-operational-assets-representation: missing or invalid table separator: '{$line}'";
                    return [];
                }

                $separatorFound = true;
                continue;
            }

            if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
                $this->errors[] = "unsupported-or-ambiguous-operational-assets-representation: unexpected non-table content in declared inventory: '{$line}'";
                continue;
            }

            if (preg_match('/^\|[-:\s|]+$/', $line)) {
                $this->errors[] = 'unsupported-or-ambiguous-operational-assets-representation: misplaced table separator';
                continue;
            }

            $cols = array_map(static fn (string $c): string => trim($c, "` \t"), explode('|', trim($line, '|')));
            if (count($cols) !== 4) {
                $this->errors[] = 'unsupported-or-ambiguous-operational-assets-representation: expected 4 columns, got ' . count($cols);
                continue;
            }

            [$kind, $upstreamPath, $localPath, $sha256] = $cols;
            $assetSourceIdentity = $authoritativeSourceIdentity;
            if (!$invalidOperationalException) {
                $matchingExceptions = array_values(array_filter($allValidRecords, static fn (array $record): bool =>
                    $record['upstream_path'] === $upstreamPath && $record['local_path'] === $localPath
                ));
                if (count($matchingExceptions) > 1) {
                    $this->errors[] = 'ambiguous-operational-asset-exception: multiple records apply to the same exact path pair';
                    $assetSourceIdentity = null;
                } elseif (count($matchingExceptions) === 1) {
                    $assetSourceIdentity = $matchingExceptions[0]['source_commit'];
                }
            }

            $allowedKinds = ['skill', 'deterministic-helper', 'provider-adapter'];
            if (!in_array($kind, $allowedKinds, true)) {
                $this->errors[] = "unknown-operational-asset-kind: {$kind} ({$localPath})";
            }

            if (!preg_match('/^[0-9a-fA-F]{64}$/', $sha256)) {
                $this->errors[] = "malformed-operational-asset-sha256: {$sha256} ({$localPath})";
            }

            if (!$this->isValidRelativePath($localPath)) {
                $this->errors[] = "invalid-operational-asset-path: {$localPath}";
                continue;
            }

            if (!$this->isValidRelativePath($upstreamPath)) {
                $this->errors[] = "malformed-operational-asset-upstream-path: {$upstreamPath}";
            }

            if (isset($seenLocalPaths[$localPath])) {
                $this->errors[] = "duplicate-operational-asset: {$localPath}";
            }
            $seenLocalPaths[$localPath] = true;

            $fullPath = $this->rootDir . '/' . $localPath;
            if (!is_file($fullPath)) {
                $this->errors[] = "missing-operational-asset: {$localPath}";
            } else {
                $realLocal = realpath($fullPath);
                if ($realLocal === false || (!str_starts_with($realLocal, $this->rootDir . '/') && $realLocal !== $this->rootDir)) {
                    $this->errors[] = "invalid-operational-asset-path: {$localPath} resolves outside repository";
                } else {
                    $actualSha = hash_file('sha256', $realLocal);
                    if ($actualSha === false || strtolower($actualSha) !== strtolower($sha256)) {
                        $this->errors[] = "operational-asset-sha-mismatch: {$localPath}";
                    }

                    // Explicit manifest-validation mode: verify against canonical source repository checkout
                    if ($this->validationMode === 'manifest-validation' && $canonicalSourceVerified && $this->sourceRootDir !== null) {
                        if ($assetSourceIdentity !== null && preg_match('/^[0-9a-fA-F]{40}$/', $assetSourceIdentity)) {
                            $commitCheckCmd = sprintf('git -C %s cat-file -e %s^{commit} 2>/dev/null', escapeshellarg($this->sourceRootDir), escapeshellarg($assetSourceIdentity));
                            exec($commitCheckCmd, $dummy, $commitRet);
                            if ($commitRet !== 0) {
                                $commitErr = "authoritative-commit-not-found: commit {$assetSourceIdentity} not found in canonical source repository";
                                if (!in_array($commitErr, $this->errors, true)) {
                                    $this->errors[] = $commitErr;
                                }
                            } else {
                                $upstreamObject = $assetSourceIdentity . ':' . $upstreamPath;
                                $typeCheckCmd = sprintf('git -C %s cat-file -t %s 2>/dev/null', escapeshellarg($this->sourceRootDir), escapeshellarg($upstreamObject));
                                $typeOut = [];
                                exec($typeCheckCmd, $typeOut, $typeRet);
                                if ($typeRet !== 0) {
                                    $this->errors[] = "missing-canonical-source-asset: {$upstreamPath} not found at commit {$authoritativeSourceIdentity}";
                                } else {
                                    $objectType = trim(implode('', $typeOut));
                                    if ($objectType !== 'blob') {
                                        $this->errors[] = "invalid-canonical-source-asset-type: {$upstreamPath} at commit {$assetSourceIdentity} is '{$objectType}', expected 'blob'";
                                    } else {
                                        $cmdShow = sprintf('git -C %s cat-file blob %s', escapeshellarg($this->sourceRootDir), escapeshellarg($upstreamObject));
                                        $resShow = $this->runCommand($cmdShow);
                                        if ($resShow['exit_code'] !== 0 || !$resShow['read_success'] || $resShow['stdout'] === null) {
                                            $this->errors[] = "failed-reading-canonical-source-asset: cannot read blob for {$upstreamPath} at commit {$assetSourceIdentity}";
                                        } else {
                                            $upstreamContent = $resShow['stdout'];
                                            $upstreamSha = hash('sha256', $upstreamContent);
                                            if (strtolower($upstreamSha) !== strtolower($sha256)) {
                                                $this->errors[] = "operational-asset-sha-mismatch: authoritative upstream content for {$localPath} does not match recorded SHA-256";
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $assets[] = [
                'kind' => $kind,
                'local_path' => $localPath,
                'sha256' => strtolower($sha256),
                'upstream_path' => $upstreamPath,
            ];
        }

        if (!$headerFound) {
            $this->errors[] = 'unsupported-or-ambiguous-operational-assets-representation: missing table header';
            return [];
        }

        if (!$separatorFound) {
            $this->errors[] = 'unsupported-or-ambiguous-operational-assets-representation: missing table separator';
            return [];
        }

        if (count($assets) === 0) {
            $this->errors[] = 'malformed-operational-assets-inventory-empty: declared inventory table contains no asset rows';
            return [];
        }

        usort($assets, static fn (array $a, array $b): int => strcmp($a['local_path'], $b['local_path']));
        return $assets;
    }

    private function isValidRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[a-zA-Z]:/', $path)) {
            return false;
        }

        $segments = explode('/', str_replace('\\', '/', $path));
        foreach ($segments as $seg) {
            if ($seg === '.' || $seg === '..') {
                return false;
            }
        }

        return true;
    }

    /** @return array{decision_id:string,upstream_path:string,local_path:string,source_commit:string,approved_on:string,reason:string}|null */
    private function parseOperationalAssetException(string $line): ?array
    {
        $pattern = '/^Type=OPERATIONAL_ASSET_MIXED_PINNING; State=APPROVED_AND_DOCUMENTED; Decision-ID=(DEC-[0-9]{3}); Authority=Project Owner; Upstream-Path=([A-Za-z0-9._\/-]+); Local-Path=([A-Za-z0-9._\/-]+); Source-Commit=([0-9a-f]{40}); Approved-On=([0-9]{4}-[0-9]{2}-[0-9]{2}); Reason=(\S(?:[^\x00-\x1F\x7F;`]*\S)?)$/D';
        if (!preg_match($pattern, $line, $m) || !checkdate((int) substr($m[5], 5, 2), (int) substr($m[5], 8, 2), (int) substr($m[5], 0, 4))) {
            return null;
        }
        $canonicalPath = static fn (string $path): bool =>
            preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/', $path) === 1;
        if (!$canonicalPath($m[2]) || !$canonicalPath($m[3]) || !$this->isValidRelativePath($m[2]) || !$this->isValidRelativePath($m[3])) {
            return null;
        }
        return ['decision_id'=>$m[1], 'upstream_path'=>$m[2], 'local_path'=>$m[3], 'source_commit'=>$m[4], 'approved_on'=>$m[5], 'reason'=>$m[6]];
    }

    /** Mechanical checks only; decision meaning remains subject to review. */
    private function validateConsumerExceptionDecision(string $decisionId, array $record): bool
    {
        $index = $this->rootDir . '/docs/decisions/DECISIONS_INDEX.md';
        $expectedDecisionDirectory = $this->rootDir . '/docs/decisions';
        $decisionDirectory = realpath($expectedDecisionDirectory);
        $indexRealPath = realpath($index);
        if ($decisionDirectory !== $expectedDecisionDirectory || $indexRealPath === false || !is_file($indexRealPath)
            || !$this->isPathWithin($indexRealPath, $decisionDirectory)
        ) {
            return false;
        }
        $matches = [];
        foreach ($this->parseAndValidateDecisionIndex($index) as $decision) {
            if ($decision['decision_id'] === $decisionId && $decision['status'] === 'ACTIVE' && $decision['record_exists']) {
                $matches[] = $decision;
            }
        }
        if (count($matches) !== 1) {
            return false;
        }
        $path = realpath($this->rootDir . '/' . $matches[0]['decision_record']);
        if ($path === false || !is_file($path) || !$this->isPathWithin($path, $decisionDirectory)
            || !$this->isPathWithin($path, $this->rootDir)
        ) {
            return false;
        }
        $body = file_get_contents($path);
        if ($body === false) {
            return false;
        }

        $expected = [
            'Decision ID' => $decisionId,
            'Status' => 'ACTIVE',
            'Decision Authority' => 'Project Owner',
            'Upstream Path' => $record['upstream_path'],
            'Local Path' => $record['local_path'],
            'Source Commit' => $record['source_commit'],
            'Approved-On' => $record['approved_on'],
            'Reason' => $record['reason'],
        ];
        $found = [];
        $inMetadata = false;
        $reservedFields = array_keys($expected);
        $reservedPattern = implode('|', array_map(static fn (string $field): string => preg_quote($field, '/'), $reservedFields));
        foreach (explode("\n", $body) as $lineNumber => $line) {
            if ($lineNumber === 0 && preg_match('/^#\s+.+/', trim($line))) {
                $inMetadata = true;
                continue;
            }
            if (preg_match('/^##\s+/', trim($line))) {
                break;
            }
            if (!$inMetadata) {
                continue;
            }
            if (!preg_match('/^\s*(?:[-*+]\s+)?\*\*(' . $reservedPattern . '):\*\*\s*(.*?)\s*$/D', $line, $m)) {
                if (preg_match('/^\s*(?:[-*+]\s+)?(?:\*\*)?(' . $reservedPattern . ')(?:\*\*)?\s*:/', $line, $malformed)) {
                    $this->errors[] = "invalid-operational-asset-exception: Decision-ID {$decisionId} has malformed or duplicated reserved metadata field {$malformed[1]}";
                    return false;
                }
                continue;
            }
            $field = $m[1];
            if (isset($found[$field]) || !preg_match('/^`([^`]*)`$/D', $m[2], $valueMatch)) {
                $this->errors[] = "invalid-operational-asset-exception: Decision-ID {$decisionId} has malformed or duplicated reserved metadata field {$field}";
                return false;
            }
            $found[$field] = $valueMatch[1];
        }
        if (count($found) !== count($expected)) {
            return false;
        }
        foreach ($expected as $field => $expectedValue) {
            if (!array_key_exists($field, $found) || $found[$field] !== $expectedValue) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array{display_line:string,record_line:string,canonical_list_marker:bool,canonical_whitespace:bool,declares_operational_asset:bool,format_error:string}> */
    private function extractExceptionsOverrides(string $content): array
    {
        $exceptions = [];
        $lines = preg_split('/\r\n|\n/', $content);
        if (!is_array($lines)) {
            return [];
        }

        $canonicalHeadings = [];
        $relatedHeadings = [];
        foreach ($lines as $index => $line) {
            if (!preg_match('/^##[ \t]+(.+?)[ \t]*$/D', $line, $heading)) {
                continue;
            }
            $title = $heading[1];
            if ($title === 'Explicit Exceptions/Overrides') {
                $canonicalHeadings[] = $index;
            } elseif (preg_match('/exceptions|overrides/i', $title)) {
                $relatedHeadings[] = [$index, $title];
            }
        }

        if (count($canonicalHeadings) > 1 || count($relatedHeadings) > 0) {
            $this->errors[] = 'ambiguous-operational-asset-exceptions-section: duplicate or non-canonical exceptions/overrides section';
            return [];
        }
        if (count($canonicalHeadings) !== 1) {
            return [];
        }

        $start = $canonicalHeadings[0] + 1;
        $end = count($lines);
        for ($i = $start; $i < count($lines); $i++) {
            if (preg_match('/^##[ \t]+/', $lines[$i])) {
                $end = $i;
                break;
            }
        }

        $bodyLines = array_slice($lines, $start, $end - $start);
        $nonEmptyBodyLines = array_values(array_filter($bodyLines, static fn (string $line): bool => $line !== ''));
        if (preg_match('/^```(?:text)?\nNone\n```$/iD', implode("\n", $nonEmptyBodyLines))
            || $nonEmptyBodyLines === ['None'] || $nonEmptyBodyLines === ['`None`']
        ) {
            return [];
        }

        foreach ($bodyLines as $rawLine) {
            if (preg_match('/^[ \t]*$/D', $rawLine)) {
                continue;
            }

            $declarationPrefix = '^[ \t]*(?:(?:[-*+][ \t]+))*';
            $targetTypeField = 'Type[ \t]*=[ \t]*OPERATIONAL_ASSET_';
            $knownRecordFields = '(?:State|Decision-ID|Authority|Upstream-Path|Local-Path|Source-Commit|Approved-On|Reason)';
            $typeDeclaration = preg_match('/' . $declarationPrefix . $targetTypeField . '/i', $rawLine) === 1
                || preg_match('/' . $declarationPrefix . $knownRecordFields . '[ \t]*=[^;\r\n]*;[ \t]*' . $targetTypeField . '/i', $rawLine) === 1;
            $isListItem = preg_match('/^[ \t]*[*+-][ \t]/', $rawLine) === 1;
            if (!$typeDeclaration && !$isListItem) {
                continue;
            }
            if ($isListItem && preg_match('/^[ \t]*[*+-] None$/iD', $rawLine)) {
                continue;
            }

            $canonicalListMarker = !$typeDeclaration || str_starts_with($rawLine, '- ');
            $canonicalWhitespace = $canonicalListMarker
                && !str_starts_with($rawLine, '-  ')
                && !str_ends_with($rawLine, ' ')
                && !str_ends_with($rawLine, "\t");
            $recordLine = str_starts_with($rawLine, '- ') ? substr($rawLine, 2) : $rawLine;
            $formatError = $typeDeclaration && !$canonicalListMarker
                ? "invalid-operational-asset-exception: declaration must begin exactly with '- '"
                : ($typeDeclaration && !$canonicalWhitespace
                    ? 'invalid-operational-asset-exception: declaration contains non-canonical whitespace'
                    : 'invalid-operational-asset-exception: malformed or incomplete canonical record');
            $exceptions[] = [
                'display_line' => $rawLine,
                'record_line' => $recordLine,
                'canonical_list_marker' => $canonicalListMarker,
                'canonical_whitespace' => $canonicalWhitespace,
                'declares_operational_asset' => $typeDeclaration,
                'format_error' => $formatError,
            ];
        }
        usort($exceptions, static fn (array $a, array $b): int => strcmp($a['display_line'], $b['display_line']));
        return $exceptions;
    }

    private function hasErrorPrefix(string $prefix): bool
    {
        foreach ($this->errors as $err) {
            if (str_starts_with($err, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $excludeDirs
     * @return list<string>
     */
    private function findFilesByName(string $dir, string $fileName, array $excludeDirs): array
    {
        $matches = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current) use ($excludeDirs): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $excludeDirs, true);
                    }
                    return true;
                }
            )
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getFilename() === $fileName) {
                $matches[] = $fileInfo->getPathname();
            }
        }

        return $matches;
    }

    private function toRelativePath(string $absPath): string
    {
        if (str_starts_with($absPath, $this->rootDir . '/')) {
            return substr($absPath, strlen($this->rootDir) + 1);
        }
        return $absPath;
    }

    /**
     * Executes a shell command and captures stdout raw bytes along with exit code and stream read status.
     *
     * @return array{exit_code: int, stdout: ?string, stderr: ?string, read_success: bool}
     */
    private function runCommand(string $command): array
    {
        if (getenv('MAATIFY_TEST_SEAM_FAIL_READ') === '1' && str_contains($command, 'cat-file blob')) {
            return [
                'exit_code' => 0,
                'stdout' => null,
                'stderr' => 'simulated stream read failure',
                'read_success' => false,
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [
                'exit_code' => -1,
                'stdout' => null,
                'stderr' => 'Failed to execute command',
                'read_success' => false,
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $readSuccess = ($stdout !== false);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout === false ? null : $stdout,
            'stderr' => $stderr === false ? null : $stderr,
            'read_success' => $readSuccess,
        ];
    }
}

// Minimal CLI Execution Entry Point
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $explicitRoot = null;
    $validationMode = 'reconciliation';
    $sourceRoot = null;

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--root=')) {
            $explicitRoot = substr($arg, 7);
        } elseif (str_starts_with($arg, '--mode=')) {
            $validationMode = substr($arg, 7);
        } elseif (str_starts_with($arg, '--source-root=')) {
            $sourceRoot = substr($arg, 14);
        } elseif ($arg === '--help' || $arg === '-h') {
            echo "Usage: php maatify-governance-context.php [--root=<dir>] [--mode=<reconciliation|manifest-validation>] [--source-root=<dir>]\n\n";
            echo "Options:\n";
            echo "  --root=<dir>         Target repository root directory (default: current working tree).\n";
            echo "  --mode=<mode>        Validation mode: 'reconciliation' (default) or 'manifest-validation'.\n";
            echo "  --source-root=<dir>  Local canonical standards checkout directory (required for manifest-validation).\n";
            echo "  --help, -h           Show this help message.\n";
            exit(0);
        } else {
            $errorOut = [
                'errors' => ["unknown-cli-argument: {$arg}"],
                'mechanical_status' => 'INVALID',
            ];
            echo json_encode($errorOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            exit(1);
        }
    }

    if ($validationMode !== 'reconciliation' && $validationMode !== 'manifest-validation') {
        $errorOut = [
            'errors' => ["unknown-or-invalid-validation-mode: {$validationMode}"],
            'mechanical_status' => 'INVALID',
        ];
        echo json_encode($errorOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }

    $repoRoot = GovernanceContextHelper::resolveRepositoryRoot($explicitRoot);
    if ($repoRoot === null) {
        $errorOut = [
            'errors' => ['repository-root-not-found'],
            'mechanical_status' => 'INVALID',
        ];
        echo json_encode($errorOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }

    try {
        $helper = new GovernanceContextHelper($repoRoot, $validationMode, $sourceRoot);
        $result = $helper->inspect();
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit($result['mechanical_status'] === 'VALID' ? 0 : 1);
    } catch (Throwable $e) {
        $errorOut = [
            'errors' => [$e->getMessage()],
            'mechanical_status' => 'INVALID',
        ];
        echo json_encode($errorOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
}
